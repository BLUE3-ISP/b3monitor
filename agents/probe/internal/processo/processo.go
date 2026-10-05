package processo

import (
	"bytes"
	"context"
	"crypto/x509"
	"errors"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/bundle"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/coordenador"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/envio"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/executor"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/politica"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/relogio"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/resultado"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/spool"
)

// Processo é a sonda em execução.
type Processo struct {
	Cfg     Config
	Versao  string
	Commit  string
	Relogio relogio.Relogio
	// Executor substitui o padrão (nos testes). Nulo: o do socket, se configurado, ou o nativo embutido.
	Executor coordenador.Executor
	// Logf recebe os eventos do processo. NUNCA recebe o token nem o corpo de resposta da central.
	Logf func(formato string, args ...any)

	token           string
	pol             *politica.Politica
	sp              *spool.Spool
	co              *coordenador.Coordenador
	env             *envio.Enviador
	cli             *envio.Cliente
	ultimaConfig    time.Time
	ultimoHeartbeat time.Time
	avisouExpirada  time.Time
}

func (p *Processo) log(f string, a ...any) {
	if p.Logf != nil {
		p.Logf(f, a...)
	}
}

// Iniciar liga as peças, carrega a configuração (da central; se ela não responde, a última válida em disco) e reconcilia a agenda.
func (p *Processo) Iniciar(ctx context.Context) error {
	if p.Relogio == nil {
		p.Relogio = relogio.Sistema{}
	}
	var err error
	if p.token, err = LerToken(p.Cfg.TokenFile); err != nil {
		return err
	}
	if p.pol, err = politica.Carregar(p.Cfg.PolicyFile); err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(p.Cfg.SpoolPath), 0o700); err != nil {
		return err
	}
	if p.sp, err = spool.Abrir(p.Cfg.SpoolPath, spool.Limites{}, p.Relogio); err != nil {
		return err
	}

	cliHTTP := &http.Client{Timeout: 30 * time.Second}
	if p.Cfg.TLSCAFile != "" {
		pem, err := os.ReadFile(p.Cfg.TLSCAFile)
		if err != nil {
			return err
		}
		pool := x509.NewCertPool()
		if !pool.AppendCertsFromPEM(pem) {
			return fmt.Errorf("tls_ca_file %s não tem certificado válido", p.Cfg.TLSCAFile)
		}
		cliHTTP.Transport = &http.Transport{TLSClientConfig: tlsConfig(pool)}
	}
	p.cli = &envio.Cliente{Base: p.Cfg.CentralURL, Token: p.token, HTTP: cliHTTP}
	p.env = &envio.Enviador{Spool: p.sp, Cliente: p.cli, Relogio: p.Relogio}

	ex := p.Executor
	if ex == nil {
		if p.Cfg.ExecutorSocket != "" {
			ex = executor.Cliente{Caminho: p.Cfg.ExecutorSocket}
		} else {
			ex = &executor.HTTP{Politica: p.pol, Relogio: p.Relogio}
		}
	}
	rel := p.Cfg.Clock
	p.co, err = coordenador.Novo(coordenador.Config{
		ProbeID: p.Cfg.ProbeID, Versao: p.Versao, Commit: p.Commit, Politica: p.pol, Spool: p.sp, Executor: ex, Relogio: p.Relogio,
		EstadoRelogio: func() resultado.Relogio {
			return resultado.Relogio{Status: rel.Status, OffsetMS: rel.OffsetMS, ReferenceKind: rel.ReferenceKind}
		},
		Capacidades:    coordenador.Capacidades{IPv4: p.Cfg.Capabilities.IPv4, IPv6: p.Cfg.Capabilities.IPv6, ICMP: p.Cfg.Capabilities.ICMP},
		DiretorioSpool: filepath.Dir(p.Cfg.SpoolPath),
	})
	if err != nil {
		return err
	}

	// A última configuração válida primeiro (a sonda mede mesmo com a central fora); depois, a central.
	if err := p.carregarCache(); err != nil {
		p.log("config: sem configuração em disco: %v", err)
	}
	p.atualizarConfig(ctx)

	// O que o reinício deixou pela metade é resolvido ANTES do primeiro passo.
	return p.co.Reconciliar(ctx)
}

// Fechar fecha o spool. Seguro chamar mais de uma vez.
func (p *Processo) Fechar() {
	if p.sp != nil {
		p.sp.Fechar()
		p.sp = nil
	}
}

// Tick faz uma volta do laço: atualiza a configuração se está na hora, executa o devido, envia o que está no spool e manda o
// heartbeat se está na hora. Erro de rede nunca é fatal; só uma falha do próprio spool devolve erro.
func (p *Processo) Tick(ctx context.Context) error {
	agora := p.Relogio.Agora()

	if agora.Sub(p.ultimaConfig) >= time.Duration(p.Cfg.ConfigRefreshS)*time.Second {
		p.atualizarConfig(ctx)
	}

	if err := p.co.Passo(ctx); err != nil {
		if errors.Is(err, coordenador.ErrConfigExpirada) {
			// Uma linha por minuto, e não uma por passo.
			if agora.Sub(p.avisouExpirada) >= time.Minute {
				p.log("config: %v", err)
				p.avisouExpirada = agora
			}
		} else {
			return fmt.Errorf("agenda: %w", err)
		}
	}

	if r, err := p.env.Ciclo(ctx); err != nil {
		var el *envio.ErroDeLote
		if errors.As(err, &el) {
			p.log("envio: a central respondeu HTTP %d; o spool guarda e tenta de novo", el.Status)
		} else {
			p.log("envio: %v; o spool guarda e tenta de novo", err)
		}
	} else if r.Confirmados+r.Quarentena+r.Reagendados > 0 {
		p.log("envio: confirmados=%d reagendados=%d quarentena=%d", r.Confirmados, r.Reagendados, r.Quarentena)
	}

	if agora.Sub(p.ultimoHeartbeat) >= time.Duration(p.Cfg.HeartbeatS)*time.Second {
		p.ultimoHeartbeat = agora
		if h, err := p.co.Heartbeat(ctx); err != nil {
			p.log("heartbeat: não foi montado: %v", err)
		} else if err := p.cli.EnviarHeartbeat(ctx, h); err != nil {
			p.log("heartbeat: não foi enviado: %v", err)
		}
	}
	return nil
}

// Rodar liga tudo e roda o laço até o contexto acabar (SIGTERM). Termina limpo: fecha o spool, sem abandonar nada em disco.
func (p *Processo) Rodar(ctx context.Context) error {
	if err := p.Iniciar(ctx); err != nil {
		p.Fechar()
		return err
	}
	defer p.Fechar()
	t := time.NewTicker(time.Duration(p.Cfg.StepIntervalMS) * time.Millisecond)
	defer t.Stop()
	// O sinal de parada só é olhado ENTRE os passos: um passo em andamento (um slot sendo medido, um lote sendo gravado) termina antes de
	// a sonda sair, com um contexto que o SIGTERM não cancela. Cancelar no meio deixaria o slot `executando` (que o reinício resolve como
	// `executor_error`) sem necessidade. O passo é limitado pelos tempos das próprias operações (timeout do alvo, 30 s da rede).
	trabalho := context.WithoutCancel(ctx)
	for {
		select {
		case <-ctx.Done():
			p.log("encerrando: o spool foi fechado e nada ficou pela metade")
			return nil
		case <-t.C:
			if err := p.Tick(trabalho); err != nil {
				return err
			}
		}
	}
}

// atualizarConfig busca o bundle na central. Só um bundle VÁLIDO e desta sonda é aplicado e gravado em disco; qualquer outra coisa
// deixa a configuração vigente como está.
func (p *Processo) atualizarConfig(ctx context.Context) {
	p.ultimaConfig = p.Relogio.Agora()
	dados, err := p.cli.BuscarBundle(ctx)
	if err != nil {
		p.log("config: não foi possível buscar na central (%v); fica a vigente", err)
		return
	}
	b, err := bundle.Ler(bytes.NewReader(dados))
	if err != nil {
		p.log("config: o bundle da central foi RECUSADO e não será aplicado: %v", err)
		return
	}
	if err := p.co.AplicarBundle(b); err != nil {
		p.log("config: %v", err)
		return
	}
	if err := gravarAtomico(p.Cfg.BundleCachePath, dados); err != nil {
		p.log("config: aplicado, mas não foi possível guardar em disco: %v", err)
	}
}

func (p *Processo) carregarCache() error {
	dados, err := os.ReadFile(p.Cfg.BundleCachePath)
	if err != nil {
		return err
	}
	b, err := bundle.Ler(bytes.NewReader(dados))
	if err != nil {
		return fmt.Errorf("o bundle em disco é inválido: %w", err)
	}
	return p.co.AplicarBundle(b)
}

// gravarAtomico escreve num temporário e renomeia: uma queda no meio nunca deixa o arquivo pela metade.
func gravarAtomico(caminho string, dados []byte) error {
	if err := os.MkdirAll(filepath.Dir(caminho), 0o700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(caminho), ".bundle-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if err := tmp.Chmod(0o600); err != nil {
		tmp.Close()
		return err
	}
	if _, err := tmp.Write(dados); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmp.Name(), caminho)
}
