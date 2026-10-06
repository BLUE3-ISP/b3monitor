package coordenador

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/bundle"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/envio"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/politica"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/relogio"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/resultado"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/spool"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/testes"
)

var proximoID atomic.Int64

const (
	probeID = "33333333-3333-4333-8333-333333333333"
	asgID   = "44444444-4444-4444-8444-444444444444"
	checkID = "22222222-2222-4222-8222-222222222222"
)

// epoch da configuração; o relógio dos testes começa 1 s depois do slot 0 e do jitter de 2 s: o slot 0 ainda não começou.
var epoch = time.Date(2026, 9, 28, 14, 0, 0, 0, time.UTC)

func politicaLab(t *testing.T) *politica.Politica {
	t.Helper()
	p, err := politica.Carregar("../../politica/lab.json")
	if err != nil {
		t.Fatal(err)
	}
	return p
}

func bundleCom(url string, geracao int, expira time.Time) *bundle.Bundle {
	alvo, _ := json.Marshal(map[string]any{"url": url, "method": "GET", "allowed_status_codes": []int{200}, "follow_redirects": false})
	return &bundle.Bundle{
		SchemaVersion: "1.0", ProbeID: probeID, Revision: 3, IssuedAt: resultado.Em(epoch), ExpiresAt: resultado.Em(expira),
		Assignments: []bundle.Assignment{{
			AssignmentID: asgID, ConfigGeneration: geracao, CheckID: checkID, Kind: "http", IPFamily: "ipv4", Target: alvo,
			Schedule:       bundle.Cronograma{Epoch: resultado.Em(epoch), IntervaloS: 30, JitterOffsetMS: 2000, ToleranciaInicio: 6000, TimeoutMS: 5000},
			PolicyRevision: "critical-v1",
		}},
	}
}

// ExecutorFalso conta as chamadas, devolve o que o teste mandou e deixa o teste espiar durante a execução.
type ExecutorFalso struct {
	mu       sync.Mutex
	Chamadas []Execucao
	Saida    func(Execucao) Saida
	Durante  func(Execucao)
}

func (e *ExecutorFalso) Executar(ctx context.Context, x Execucao) Saida {
	e.mu.Lock()
	e.Chamadas = append(e.Chamadas, x)
	e.mu.Unlock()
	if e.Durante != nil {
		e.Durante(x)
	}
	if e.Saida != nil {
		return e.Saida(x)
	}
	host := "portal.monitor.example"
	status := 200
	return Saida{Outcome: resultado.Pass, Reason: resultado.RazaoSucessoHTTP, HTTPStatus: &status,
		Destino: &resultado.Destino{IP: "203.0.113.10", Port: 443, Hostname: &host}}
}

func (e *ExecutorFalso) N() int { e.mu.Lock(); defer e.mu.Unlock(); return len(e.Chamadas) }

type amb struct {
	co  *Coordenador
	sp  *spool.Spool
	rel *relogio.Falso
	ex  *ExecutorFalso
	dir string
}

func novo(t *testing.T, b *bundle.Bundle) *amb {
	t.Helper()
	return novoEm(t, t.TempDir(), relogio.NovoFalso(epoch.Add(time.Second)), b, &ExecutorFalso{})
}

func novoEm(t *testing.T, dir string, rel *relogio.Falso, b *bundle.Bundle, ex *ExecutorFalso) *amb {
	t.Helper()
	sp, err := spool.Abrir(filepath.Join(dir, "spool.db"), spool.Limites{}, rel)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { sp.Fechar() })
	// O gerador é GLOBAL ao processo de teste: dois coordenadores sobre o mesmo spool (o reinício) nunca sugerem o mesmo id por
	// coincidência. Sem isso, um defeito que descartasse a identidade persistida passaria despercebido.
	co, err := Novo(Config{ProbeID: probeID, Versao: "0.0.1-lab", Politica: politicaLab(t), Spool: sp, Executor: ex, Relogio: rel,
		NovoID: func() string { return fmt.Sprintf("00000000-0000-4000-8000-%012d", proximoID.Add(1)) }})
	if err != nil {
		t.Fatal(err)
	}
	if b != nil {
		if err := co.AplicarBundle(b); err != nil {
			t.Fatal(err)
		}
	}
	return &amb{co: co, sp: sp, rel: rel, ex: ex, dir: dir}
}

func (a *amb) pendentes(t *testing.T) []map[string]any {
	t.Helper()
	itens, err := a.sp.Proximos(context.Background(), 1000, 1<<30)
	if err != nil {
		t.Fatal(err)
	}
	var out []map[string]any
	for _, it := range itens {
		var m map[string]any
		if err := json.Unmarshal(it.Envelope, &m); err != nil {
			t.Fatal(err)
		}
		out = append(out, m)
	}
	return out
}

func (a *amb) passo(t *testing.T) {
	t.Helper()
	if err := a.co.Passo(context.Background()); err != nil {
		t.Fatal(err)
	}
}

const url = "https://portal.monitor.example/health"

func TestNaoExecutaAntesDoInicioDoSlotEExecutaUmaVezDentroDaJanela(t *testing.T) {
	a := novo(t, bundleCom(url, 3, epoch.Add(24*time.Hour)))
	a.passo(t) // epoch+1 s: o slot 0 só começa em +2 s (jitter)
	if a.ex.N() != 0 {
		t.Fatal("executou antes de o slot começar")
	}
	a.rel.Avancar(time.Second) // +2 s: começa
	a.passo(t)
	a.passo(t) // de novo no mesmo instante: não repete
	a.rel.Avancar(3 * time.Second)
	a.passo(t) // ainda dentro da tolerância: não repete
	if a.ex.N() != 1 {
		t.Fatalf("o slot 0 devia executar UMA vez, executou %d", a.ex.N())
	}
	if len(a.pendentes(t)) != 1 {
		t.Fatalf("1 resultado no spool: %d", len(a.pendentes(t)))
	}
}

func TestPlanejaAntesDeExecutar(t *testing.T) {
	a := novo(t, bundleCom(url, 3, epoch.Add(24*time.Hour)))
	a.rel.Avancar(time.Second)
	var visto []spool.ItemAgenda
	a.ex.Durante = func(Execucao) {
		visto, _ = a.sp.Incompletos(context.Background())
	}
	a.passo(t)
	if len(visto) != 1 || visto[0].Estado != spool.Executando {
		t.Fatalf("durante a execução o slot tem de estar PERSISTIDO como executando: %+v", visto)
	}
	if inc, _ := a.sp.Incompletos(context.Background()); len(inc) != 0 {
		t.Fatalf("depois de concluir não sobra incompleto: %+v", inc)
	}
}

func TestResultadoTemOFormatoDoContratoERelogioDesconhecidoNaoViraZero(t *testing.T) {
	a := novo(t, bundleCom(url, 3, epoch.Add(24*time.Hour)))
	a.rel.Avancar(time.Second)
	a.passo(t)
	r := a.pendentes(t)[0]

	for k, v := range map[string]any{
		"schema_version": "1.0", "probe_id": probeID, "assignment_id": asgID, "config_generation": float64(3),
		"policy_revision": "critical-v1", "execution_kind": "scheduled", "scheduled_at": "2026-09-28T14:00:00Z",
		"ip_family": "ipv4", "outcome": "pass", "reason_code": "http_assertions_passed",
	} {
		if r[k] != v {
			t.Errorf("%s = %v, esperava %v", k, r[k], v)
		}
	}
	clock := r["clock"].(map[string]any)
	if clock["status"] != "unknown" || clock["offset_ms"] != nil {
		t.Errorf("sem referência externa o relógio é DESCONHECIDO, com offset nulo (INV-02): %v", clock)
	}
	if r["executor"].(map[string]any)["name"] != "native" {
		t.Errorf("executor: %v", r["executor"])
	}
	if r["integrity"].(map[string]any)["local_policy_revision"] != "local-lab-v1" {
		t.Errorf("integridade: %v", r["integrity"])
	}
}

func TestPoliticaLocalNegaESemConexao(t *testing.T) {
	a := novo(t, bundleCom("https://evil.example/segredos", 3, epoch.Add(24*time.Hour)))
	a.rel.Avancar(time.Second)
	a.passo(t)
	if a.ex.N() != 0 {
		t.Fatal("a política local negou: o executor NÃO pode ser chamado")
	}
	r := a.pendentes(t)[0]
	if r["outcome"] != "policy_denied" || r["reason_code"] != "policy_denied" || r["actual_destination"] != nil {
		t.Fatalf("o resultado tem de dizer que a política negou, sem destino: %v", r)
	}
}

func TestSlotPerdidoNaoVirarRajadaNemSucessoFingido(t *testing.T) {
	a := novo(t, bundleCom(url, 3, epoch.Add(48*time.Hour)))
	a.rel.Avancar(time.Second)
	a.passo(t) // slot 0 executa
	// A sonda fica parada por 1 hora (120 slots).
	a.rel.Avancar(time.Hour)
	a.passo(t)

	// Executou UM slot (o atual), e não 120.
	if a.ex.N() != 2 {
		t.Fatalf("depois da parada o executor roda só o slot vivo: %d chamadas no total", a.ex.N())
	}
	res := a.pendentes(t)
	pulados := 0
	for _, r := range res {
		if r["outcome"] == "skipped" {
			pulados++
			if r["reason_code"] != "schedule_missed" {
				t.Errorf("skipped tem de dizer schedule_missed: %v", r)
			}
		}
	}
	if pulados != MaxSkippedPorPasso {
		t.Fatalf("só os %d slots perdidos mais recentes viram skipped: %d", MaxSkippedPorPasso, pulados)
	}
	// O resto é lacuna REGISTRADA, não um sucesso nem um "sem falhas".
	if n, _ := a.sp.LacunasTotal(context.Background()); n < 100 {
		t.Fatalf("a parada de 1 h tem de aparecer como lacuna: %d", n)
	}
}

func TestConfigExpiradaNaoExecuta(t *testing.T) {
	a := novo(t, bundleCom(url, 3, epoch.Add(10*time.Second)))
	a.rel.Avancar(time.Minute)
	if err := a.co.Passo(context.Background()); err != ErrConfigExpirada {
		t.Fatalf("esperava ErrConfigExpirada: %v", err)
	}
	if a.ex.N() != 0 || len(a.pendentes(t)) != 0 {
		t.Fatal("com a configuração vencida nada é executado")
	}
}

func TestBundleDeOutraSondaERecusado(t *testing.T) {
	a := novo(t, nil)
	b := bundleCom(url, 3, epoch.Add(time.Hour))
	b.ProbeID = "99999999-9999-4999-8999-999999999999"
	if err := a.co.AplicarBundle(b); err == nil {
		t.Fatal("o bundle de outra sonda não pode ser aplicado")
	}
}

func TestMudancaDeGeracaoNaoMisturaOResultado(t *testing.T) {
	a := novo(t, bundleCom(url, 3, epoch.Add(24*time.Hour)))
	a.rel.Avancar(time.Second)
	a.passo(t)
	// A central entrega a geração 4 (mesmo assignment); o próximo slot é da geração nova.
	a.co.AplicarBundle(bundleCom(url, 4, epoch.Add(24*time.Hour)))
	a.rel.Avancar(30 * time.Second)
	a.passo(t)

	gens := map[float64]int{}
	for _, r := range a.pendentes(t) {
		gens[r["config_generation"].(float64)]++
	}
	if gens[3] != 1 || gens[4] != 1 {
		t.Fatalf("cada resultado leva a geração em que rodou: %v", gens)
	}
}

func TestDuasMedicoesDeUmMesmoSlotNuncaExistem(t *testing.T) {
	a := novo(t, bundleCom(url, 3, epoch.Add(24*time.Hour)))
	a.rel.Avancar(time.Second)
	for i := 0; i < 5; i++ {
		a.passo(t)
	}
	if a.ex.N() != 1 || len(a.pendentes(t)) != 1 {
		t.Fatalf("1 slot, 1 execução, 1 resultado: %d %d", a.ex.N(), len(a.pendentes(t)))
	}
}

// ---- Reinício: a agenda sobrevive a um SIGKILL de verdade ----

func TestMain(m *testing.M) {
	if dir := os.Getenv("COORD_FILHO"); dir != "" {
		filhoQueTravaNoMeioDaExecucao(dir)
		return
	}
	os.Exit(m.Run())
}

// O filho executa o slot 0 até o fim, começa o slot 1 e TRAVA dentro do executor, com o slot `executando`; o pai o mata.
func filhoQueTravaNoMeioDaExecucao(dir string) {
	rel := relogio.NovoFalso(epoch.Add(time.Second))
	sp, err := spool.Abrir(filepath.Join(dir, "spool.db"), spool.Limites{}, rel)
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(2)
	}
	ex := &ExecutorFalso{}
	co, _ := Novo(Config{ProbeID: probeID, Politica: mustPolitica(), Spool: sp, Executor: ex, Relogio: rel,
		// Espaço de ids PRÓPRIO do filho (prefixo 1…): o pai usa `proximoID` a partir de 1, e o mesmo id nos dois processos fazia o teste
		// falhar quando rodava SOZINHO (-run), e só passar dentro do pacote, onde outros testes já tinham avançado o contador.
		NovoID: func() string { return fmt.Sprintf("11111111-0000-4000-8000-%012d", len(ex.Chamadas)+1) }})
	co.AplicarBundle(bundleCom(url, 3, epoch.Add(24*time.Hour)))

	rel.Avancar(time.Second)
	co.Passo(context.Background()) // slot 0: completo
	rel.Avancar(30 * time.Second)
	ex.Durante = func(x Execucao) {
		fmt.Println("travou-no-meio")
		p, _ := os.FindProcess(os.Getpid())
		p.Signal(os.Kill)
		select {}
	}
	co.Passo(context.Background()) // slot 1: a morte acontece aqui dentro
}

func mustPolitica() *politica.Politica {
	p, err := politica.Carregar("../../politica/lab.json")
	if err != nil {
		panic(err)
	}
	return p
}

func TestCrashEReinicioMantemAAgendaSemDuplicarNemInventarSucesso(t *testing.T) {
	dir := t.TempDir()
	cmd := exec.Command(os.Args[0], "-test.run=NaoExiste")
	cmd.Env = append(os.Environ(), "COORD_FILHO="+dir)
	saida, _ := cmd.CombinedOutput()
	if !strings.Contains(string(saida), "travou-no-meio") {
		t.Fatalf("o filho não chegou ao meio da execução: %s", saida)
	}

	// REINÍCIO: processo novo, mesmo arquivo, relógio 5 s depois do slot 1 (ainda dentro da janela do slot 1: +32..+38 s).
	rel := relogio.NovoFalso(epoch.Add(34 * time.Second))
	ex := &ExecutorFalso{}
	a := novoEm(t, dir, rel, bundleCom(url, 3, epoch.Add(24*time.Hour)), ex)

	// Antes de reconciliar: 1 resultado finalizado (slot 0) e 1 slot executando (slot 1).
	inc, _ := a.sp.Incompletos(context.Background())
	if len(inc) != 1 || inc[0].Estado != spool.Executando {
		t.Fatalf("o slot que estava executando tem de sobreviver à queda: %+v", inc)
	}
	idOriginal := inc[0].ResultID

	if err := a.co.Reconciliar(context.Background()); err != nil {
		t.Fatal(err)
	}
	a.passo(t)

	res := a.pendentes(t)
	if len(res) != 2 {
		t.Fatalf("2 slots, 2 resultados (nada perdido, nada duplicado): %d", len(res))
	}
	var doSlot1 map[string]any
	for _, r := range res {
		if r["scheduled_at"] == "2026-09-28T14:00:30Z" {
			doSlot1 = r
		}
	}
	if doSlot1 == nil {
		t.Fatalf("o resultado do slot 1 não existe: %v", res)
	}
	// Não se sabe se terminou: erro de execução com a identidade ORIGINAL, e NUNCA um sucesso fabricado.
	if doSlot1["result_id"] != idOriginal || doSlot1["outcome"] != "executor_error" {
		t.Fatalf("slot interrompido: %v (esperava executor_error com result_id %s)", doSlot1, idOriginal)
	}
	// E o executor NÃO foi chamado de novo para nenhum slot já resolvido.
	if ex.N() != 0 {
		t.Fatalf("o reinício reexecutou %d slot(s) que já tinham resultado", ex.N())
	}

	// A agenda segue: o slot 2 roda normalmente depois do reinício.
	rel.Avancar(31 * time.Second)
	a.passo(t)
	if ex.N() != 1 || len(a.pendentes(t)) != 3 {
		t.Fatalf("o slot 2 devia rodar: executor=%d resultados=%d", ex.N(), len(a.pendentes(t)))
	}
}

func TestReinicioComSlotPlanejadoAindaNaJanelaOExecutaComAMesmaIdentidade(t *testing.T) {
	dir := t.TempDir()
	rel := relogio.NovoFalso(epoch.Add(time.Second))
	a := novoEm(t, dir, rel, bundleCom(url, 3, epoch.Add(24*time.Hour)), &ExecutorFalso{})
	// Planeja o slot 0 e "morre" antes de executar.
	asg := a.co.Bundle().Assignments[0]
	it, _, err := a.co.planejar(context.Background(), asg, 0)
	if err != nil {
		t.Fatal(err)
	}
	a.sp.Fechar()

	rel2 := relogio.NovoFalso(epoch.Add(3 * time.Second)) // dentro da janela (+2..+8 s)
	ex := &ExecutorFalso{}
	b := novoEm(t, dir, rel2, bundleCom(url, 3, epoch.Add(24*time.Hour)), ex)
	if err := b.co.Reconciliar(context.Background()); err != nil {
		t.Fatal(err)
	}
	b.passo(t)
	res := b.pendentes(t)
	if len(res) != 1 || res[0]["result_id"] != it.ResultID || res[0]["outcome"] != "pass" || ex.N() != 1 {
		t.Fatalf("o slot planejado antes da queda roda depois, com o MESMO result_id: %v", res)
	}
}

func TestReinicioComSlotPlanejadoForaDaJanelaViraSkipped(t *testing.T) {
	dir := t.TempDir()
	rel := relogio.NovoFalso(epoch.Add(time.Second))
	a := novoEm(t, dir, rel, bundleCom(url, 3, epoch.Add(24*time.Hour)), &ExecutorFalso{})
	if _, _, err := a.co.planejar(context.Background(), a.co.Bundle().Assignments[0], 0); err != nil {
		t.Fatal(err)
	}
	a.sp.Fechar()

	rel2 := relogio.NovoFalso(epoch.Add(20 * time.Second)) // a janela do slot 0 fechou em +8 s
	ex := &ExecutorFalso{}
	b := novoEm(t, dir, rel2, bundleCom(url, 3, epoch.Add(24*time.Hour)), ex)
	b.co.Reconciliar(context.Background())

	res := b.pendentes(t)
	if len(res) != 1 || res[0]["outcome"] != "skipped" || res[0]["reason_code"] != "schedule_missed" || ex.N() != 0 {
		t.Fatalf("slot que não rodou a tempo é skipped, sem executar atrasado: %v", res)
	}
}

// ---- Central fora → spool → reenvio na volta, sem perder e sem duplicar ----

func TestCentralForaSpoolEReenvioSemPerdaNemDuplicata(t *testing.T) {
	central := testes.Nova("segredo")
	defer central.Close()
	a := novo(t, bundleCom(url, 3, epoch.Add(24*time.Hour)))
	env := &envio.Enviador{
		Spool: a.sp, Cliente: &envio.Cliente{Base: central.URL(), Token: "segredo"}, Relogio: a.rel,
		Aleatorio: func() float64 { return 0.5 },
	}
	ctx := context.Background()

	// 1) A central cai. A sonda continua medindo (10 slots, 5 minutos) e o spool acumula.
	central.DefinirModo(testes.Fora, 0)
	a.rel.Avancar(time.Second)
	for i := 0; i < 10; i++ {
		a.rel.Avancar(30 * time.Second)
		a.passo(t)
		env.Ciclo(ctx) // tenta e falha, recuando
	}
	if central.Gravados() != 0 {
		t.Fatal("a central estava fora")
	}
	e, _ := a.sp.Estatisticas(ctx)
	if e.Linhas != 10 {
		t.Fatalf("o spool devia ter os 10 resultados: %+v", e)
	}

	// 2) A central volta, mas a PRIMEIRA resposta se perde depois do commit.
	central.DefinirModo(testes.CairAposCommit, 0)
	a.rel.Avancar(10 * time.Minute)
	env.Ciclo(ctx)
	if central.Gravados() == 0 {
		t.Fatal("a central devia ter gravado o lote antes de a conexão cair")
	}
	if e, _ := a.sp.Estatisticas(ctx); e.Linhas != 10 {
		t.Fatalf("a sonda não sabe que a central gravou: nada sai do spool: %+v", e)
	}

	// 3) A rede normaliza. O reenvio é `duplicate` e o spool esvazia.
	central.DefinirModo(testes.Normal, 0)
	a.rel.Avancar(10 * time.Minute)
	r, err := env.Ciclo(ctx)
	if err != nil || r.Confirmados != 10 {
		t.Fatalf("%+v %v", r, err)
	}
	e, _ = a.sp.Estatisticas(ctx)
	if e.Linhas != 0 || central.Gravados() != 10 {
		t.Fatalf("spool=%d central=%d: nenhum resultado perdido e nenhum duplicado", e.Linhas, central.Gravados())
	}
}
