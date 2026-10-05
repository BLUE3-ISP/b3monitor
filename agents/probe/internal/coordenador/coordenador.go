// Package coordenador é o ciclo da sonda (04 §1): carrega a política local e a última configuração válida, reconcilia a
// agenda, persiste o slot ANTES de executá-lo, executa o teste permitido, persiste o resultado final e deixa a transmissão
// ao `envio`. O resultado só sai do spool depois da confirmação durável da central.
//
// O que ele NÃO faz: medir. O executor é uma interface (`Executor`), e o nativo, com a política de destinos e a defesa de
// SSRF e rebinding, é o B7. Aqui entra o que decide SE e QUANDO medir, e o que registrar quando não mediu.
//
// Regras que moram aqui:
//   - **A central não amplia a política local.** Um assignment que a política não cobre vira um resultado `policy_denied`,
//     sem conexão alguma, em vez de ser executado ou silenciosamente descartado.
//   - **Slot perdido não vira rajada.** Se o slot passou da tolerância de início, ele NÃO é executado "atrasado" nem fingido
//     como executado: os poucos mais recentes viram `skipped` (`schedule_missed`) e o resto vira uma lacuna registrada.
//   - **Reinício entre planejar e concluir.** Um slot que estava `executando` e não se sabe se terminou vira
//     `executor_error`, com a identidade original, e nunca um sucesso fabricado. Um resultado já finalizado não é
//     reexecutado.
package coordenador

import (
	"context"
	"crypto/rand"
	"encoding/json"
	"errors"
	"fmt"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/agenda"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/bundle"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/politica"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/relogio"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/resultado"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/spool"
)

// MaxSkippedPorPasso é quantos slots perdidos viram resultado `skipped` individual; o resto de uma parada longa vira UMA
// lacuna registrada (não se inunda a central com milhares de "pulei").
const MaxSkippedPorPasso = 3

// Execucao é o pedido ao executor: configuração normalizada e destino já autorizado pela política (04 §4).
type Execucao struct {
	Assignment bundle.Assignment
	Alvo       bundle.AlvoHTTP
	Slot       time.Time
	Timeout    time.Duration
}

// Saida é o que o executor devolve. Precisa distinguir resultado do alvo, erro de execução, capacidade indisponível e erro de
// configuração (04 §4). O IP efetivo é o que a conexão usou, nunca o primeiro resultado de DNS por aproximação.
type Saida struct {
	Outcome    string
	Reason     string
	Destino    *resultado.Destino
	HTTPStatus *int
	Inicio     time.Time
	Fim        time.Time
}

// Executor mede um alvo. A implementação nativa é o B7.
type Executor interface {
	Executar(ctx context.Context, e Execucao) Saida
}

// Config do coordenador.
type Config struct {
	ProbeID  string
	Versao   string
	Commit   string
	Politica *politica.Politica
	Spool    *spool.Spool
	Executor Executor
	Relogio  relogio.Relogio
	// EstadoRelogio devolve o estado do relógio da sonda. Padrão: DESCONHECIDO, com offset nulo (INV-02). Um relógio sem
	// referência externa não pode se declarar sincronizado.
	EstadoRelogio func() resultado.Relogio
	// NovoID gera o result_id. Padrão: UUID v4.
	NovoID func() string
	// Capacidades de IPv4, IPv6 e ICMP que a sonda declara (padrão: `unknown`, que é o estado de uma capacidade não medida).
	Capacidades Capacidades
	// DiretorioSpool serve à medição do disco livre do heartbeat; DiscoLivre a substitui nos testes.
	DiretorioSpool string
	DiscoLivre     func() (uint64, error)
}

// Coordenador executa a agenda.
type Coordenador struct {
	cfg    Config
	bundle *bundle.Bundle
	inicio time.Time

	ultimaExecucao *time.Time
	atrasoMS       *int64
}

// Novo cria o coordenador. O instante de início serve ao `uptime_s` do heartbeat.
func Novo(cfg Config) (*Coordenador, error) {
	if cfg.Politica == nil || cfg.Spool == nil || cfg.Executor == nil || cfg.Relogio == nil || cfg.ProbeID == "" {
		return nil, errors.New("coordenador: Politica, Spool, Executor, Relogio e ProbeID são obrigatórios")
	}
	if cfg.NovoID == nil {
		cfg.NovoID = uuidV4
	}
	if cfg.EstadoRelogio == nil {
		cfg.EstadoRelogio = func() resultado.Relogio {
			return resultado.Relogio{Status: "unknown", OffsetMS: nil, ReferenceKind: "none"}
		}
	}
	return &Coordenador{cfg: cfg, inicio: cfg.Relogio.Agora()}, nil
}

// AplicarBundle troca a configuração vigente. O bundle já foi validado por `bundle.Ler`.
func (c *Coordenador) AplicarBundle(b *bundle.Bundle) error {
	if b.ProbeID != c.cfg.ProbeID {
		return fmt.Errorf("o bundle é da sonda %s, e esta é a %s", b.ProbeID, c.cfg.ProbeID)
	}
	c.bundle = b
	return nil
}

// Bundle devolve a configuração vigente (nil se ainda não há).
func (c *Coordenador) Bundle() *bundle.Bundle { return c.bundle }

// Reconciliar roda UMA vez no início (depois de carregar o bundle): resolve o que o reinício deixou pela metade.
func (c *Coordenador) Reconciliar(ctx context.Context) error {
	incompletos, err := c.cfg.Spool.Incompletos(ctx)
	if err != nil {
		return err
	}
	agora := c.cfg.Relogio.Agora()
	for _, it := range incompletos {
		a, err := contextoDe(it)
		if err != nil {
			// Sem o retrato do assignment não dá para montar um resultado honesto: fica incompleto e visível.
			return fmt.Errorf("reconciliando %s: %w", it.ResultID, err)
		}
		switch it.Estado {
		case spool.Executando:
			// Não se sabe se terminou. Erro de execução com a identidade original; nunca um sucesso fabricado.
			r := c.montar(a, it, resultado.ExecutorError, resultado.RazaoExecutorIndisp, nil, nil, it.PlanejadoEm, agora)
			if err := c.finalizar(ctx, it, spool.Concluido, r); err != nil {
				return err
			}
		case spool.Planejado:
			// Se a janela de início já fechou, o slot foi perdido; se ainda está aberta, o próximo passo o executa.
			if agenda.Classificar(a.Schedule, indice(a.Schedule, it.Slot), agora) == agenda.Perdido {
				r := c.montar(a, it, resultado.Skipped, resultado.RazaoAgendaPerdida, nil, nil, agora, agora)
				if err := c.finalizar(ctx, it, spool.Pulado, r); err != nil {
					return err
				}
			}
		}
	}
	return nil
}

// Passo faz o trabalho devido em `agora`. Determinístico: o teste avança o relógio e chama Passo.
func (c *Coordenador) Passo(ctx context.Context) error {
	if c.bundle == nil {
		return nil
	}
	agora := c.cfg.Relogio.Agora()
	if c.bundle.Expirado(agora) {
		// Configuração vencida: a sonda não executa com ela (config_expired). O heartbeat mostra a revisão aplicada.
		return ErrConfigExpirada
	}
	atribuicoes := c.bundle.Assignments
	if max := c.cfg.Politica.MaxAtribuicoes(); len(atribuicoes) > max {
		atribuicoes = atribuicoes[:max]
	}
	var primeiro error
	for _, a := range atribuicoes {
		if err := c.passoDe(ctx, a, agora); err != nil && primeiro == nil {
			primeiro = err
		}
	}
	return primeiro
}

// ErrConfigExpirada: o bundle vigente passou da validade.
var ErrConfigExpirada = errors.New("a configuração vigente expirou (config_expired): nada é executado")

func (c *Coordenador) passoDe(ctx context.Context, a bundle.Assignment, agora time.Time) error {
	cr := a.Schedule
	atual := agenda.Atual(cr, agora)
	if atual < 0 {
		return nil // o epoch ainda não chegou
	}
	primeiroVivo := agenda.PrimeiroNaoPerdido(cr, agora)

	inicio := primeiroVivo
	if ultimo, ok, err := c.cfg.Spool.UltimoSlot(ctx, a.AssignmentID, a.ConfigGeneration); err != nil {
		return err
	} else if ok {
		ult := indice(cr, ultimo)
		// O último slot planejado pode ter ficado `planejado` (reinício): ele ainda entra, e `Iniciar` recusa os que já andaram.
		if ult < inicio {
			if err := c.registrarPerdidos(ctx, a, ult+1, primeiroVivo-1, agora); err != nil {
				return err
			}
		}
		if ult < primeiroVivo {
			inicio = primeiroVivo
		} else {
			inicio = ult
		}
	}

	for n := inicio; n <= atual; n++ {
		if agenda.Classificar(cr, n, agora) != agenda.Devido {
			continue
		}
		if err := c.executar(ctx, a, n, agora); err != nil {
			return err
		}
	}
	return nil
}

// registrarPerdidos trata os slots [de, ate] que passaram sem execução: os mais recentes viram `skipped`; o resto, lacuna.
func (c *Coordenador) registrarPerdidos(ctx context.Context, a bundle.Assignment, de, ate int64, agora time.Time) error {
	if ate < de {
		return nil
	}
	total := ate - de + 1
	individuais := total
	if individuais > MaxSkippedPorPasso {
		individuais = MaxSkippedPorPasso
	}
	if resto := total - individuais; resto > 0 {
		if err := c.cfg.Spool.RegistrarLacuna(ctx, a.AssignmentID, a.ConfigGeneration,
			agenda.Slot(a.Schedule, de), agenda.Slot(a.Schedule, de+resto-1), int(resto)); err != nil {
			return err
		}
	}
	for n := ate - individuais + 1; n <= ate; n++ {
		it, novo, err := c.planejar(ctx, a, n)
		if err != nil {
			return err
		}
		if !novo {
			continue
		}
		ctxA, _ := json.Marshal(a)
		it.Contexto = ctxA
		r := c.montar(a, it, resultado.Skipped, resultado.RazaoAgendaPerdida, nil, nil, agora, agora)
		if err := c.finalizar(ctx, it, spool.Pulado, r); err != nil {
			return err
		}
	}
	return nil
}

func (c *Coordenador) planejar(ctx context.Context, a bundle.Assignment, n int64) (spool.ItemAgenda, bool, error) {
	ctxA, err := json.Marshal(a)
	if err != nil {
		return spool.ItemAgenda{}, false, err
	}
	it := spool.ItemAgenda{
		AssignmentID: a.AssignmentID, Geracao: a.ConfigGeneration, Slot: agenda.Slot(a.Schedule, n),
		ResultID: c.cfg.NovoID(), Contexto: ctxA,
	}
	id, novo, err := c.cfg.Spool.Planejar(ctx, it)
	it.ResultID = id // a identidade é a que foi persistida, mesmo depois de um reinício
	return it, novo, err
}

func (c *Coordenador) executar(ctx context.Context, a bundle.Assignment, n int64, agora time.Time) error {
	it, _, err := c.planejar(ctx, a, n)
	if err != nil {
		return err
	}
	// Planejado ANTES de executar. `Iniciar` só vale uma vez: um slot que já andou (executando ou concluído) não roda de novo.
	if ok, err := c.cfg.Spool.Iniciar(ctx, it); err != nil || !ok {
		return err
	}

	// A política local manda: o que ela não cobre não é executado, e o resultado diz isso.
	if err := c.cfg.Politica.Autoriza(a); err != nil {
		r := c.montar(a, it, resultado.PolicyDenied, resultado.RazaoPoliticaNegou, nil, nil, agora, agora)
		return c.finalizar(ctx, it, spool.Concluido, r)
	}
	alvo, err := a.AlvoHTTP()
	if err != nil {
		r := c.montar(a, it, resultado.ConfigError, resultado.RazaoPoliticaNegou, nil, nil, agora, agora)
		return c.finalizar(ctx, it, spool.Concluido, r)
	}

	timeout := time.Duration(a.Schedule.TimeoutMS) * time.Millisecond
	ctxExec, cancela := context.WithTimeout(ctx, timeout)
	defer cancela()
	saida := c.cfg.Executor.Executar(ctxExec, Execucao{Assignment: a, Alvo: alvo, Slot: it.Slot, Timeout: timeout})

	inicio, fim := saida.Inicio, saida.Fim
	if inicio.IsZero() {
		inicio = agora
	}
	if fim.IsZero() || fim.Before(inicio) {
		fim = c.cfg.Relogio.Agora()
		if fim.Before(inicio) {
			fim = inicio
		}
	}
	r := c.montar(a, it, saida.Outcome, saida.Reason, saida.Destino, saida.HTTPStatus, inicio, fim)
	t := fim
	c.ultimaExecucao = &t
	atraso := inicio.Sub(agenda.Inicio(a.Schedule, n)).Milliseconds()
	if atraso < 0 {
		atraso = 0
	}
	c.atrasoMS = &atraso
	return c.finalizar(ctx, it, spool.Concluido, r)
}

// montar monta o resultado normalizado de um slot.
func (c *Coordenador) montar(a bundle.Assignment, it spool.ItemAgenda, outcome, razao string, dest *resultado.Destino, status *int, inicio, fim time.Time) resultado.Resultado {
	agendado := resultado.Em(it.Slot)
	dur := fim.Sub(inicio).Milliseconds()
	if dur < 0 {
		dur = 0
	}
	var total *int64
	if outcome == resultado.Pass || outcome == resultado.Fail {
		total = &dur
	}
	return resultado.Resultado{
		SchemaVersion: resultado.SchemaVersion, ResultID: it.ResultID, ProbeID: c.cfg.ProbeID, AssignmentID: a.AssignmentID,
		ConfigGeneration: a.ConfigGeneration, PolicyRevision: a.PolicyRevision, ExecutionKind: "scheduled", ScheduledAt: &agendado,
		StartedAt: resultado.Em(inicio), FinishedAt: resultado.Em(fim), DurationMS: dur, IPFamily: a.IPFamily,
		Outcome: outcome, ReasonCode: razao, ActualDestination: dest,
		Metrics:      resultado.Metricas{HTTPStatus: status, TotalDurationMS: total},
		Clock:        c.cfg.EstadoRelogio(),
		Integrity:    resultado.Integridade{ProbeAssessment: "valid", ReasonCodes: []string{}, LocalPolicyRevision: c.cfg.Politica.Revision},
		PathEvidence: resultado.Caminho{Status: "not_required"},
		Executor:     resultado.Executor{Name: "native", Build: c.cfg.Versao},
	}
}

func (c *Coordenador) finalizar(ctx context.Context, it spool.ItemAgenda, estado string, r resultado.Resultado) error {
	envelope, err := json.Marshal(r)
	if err != nil {
		return err
	}
	return c.cfg.Spool.Finalizar(ctx, it, estado, envelope)
}

func contextoDe(it spool.ItemAgenda) (bundle.Assignment, error) {
	var a bundle.Assignment
	if len(it.Contexto) == 0 {
		return a, errors.New("o slot não tem o retrato do assignment")
	}
	return a, json.Unmarshal(it.Contexto, &a)
}

func indice(c bundle.Cronograma, slot time.Time) int64 {
	return int64(slot.Sub(c.Epoch.Time) / (time.Duration(c.IntervaloS) * time.Second))
}

func uuidV4() string {
	var b [16]byte
	rand.Read(b[:])
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80
	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:])
}
