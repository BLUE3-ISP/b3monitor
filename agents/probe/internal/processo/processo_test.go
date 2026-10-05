package processo

import (
	"context"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/coordenador"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/executor"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/politica"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/relogio"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/spool"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/testes"
)

const (
	probeID = "33333333-3333-4333-8333-333333333333"
	asgID   = "44444444-4444-4444-8444-444444444444"
	token   = "b3m_p_SEGREDO-DE-TESTE-NAO-VAZAR"
)

var epoch = time.Date(2026, 9, 28, 14, 0, 0, 0, time.UTC)

// ---- apoio ----

type log struct {
	mu     sync.Mutex
	linhas []string
}

func (l *log) Logf(f string, a ...any) {
	l.mu.Lock()
	l.linhas = append(l.linhas, fmt.Sprintf(f, a...))
	l.mu.Unlock()
}
func (l *log) Tudo() string { l.mu.Lock(); defer l.mu.Unlock(); return strings.Join(l.linhas, "\n") }

// alvoLocal sobe um alvo HTTP em 127.0.0.1 e devolve a porta.
func alvoLocal(t *testing.T) int {
	t.Helper()
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(200) }))
	t.Cleanup(s.Close)
	return s.Listener.Addr().(*net.TCPAddr).Port
}

// ambiente prepara o diretório de uma sonda: política, token e a configuração local.
func ambiente(t *testing.T, centralURL string, porta int) (Config, string) {
	t.Helper()
	dir := t.TempDir()
	pol := filepath.Join(dir, "politica.json")
	os.WriteFile(pol, []byte(fmt.Sprintf(`{"revision":"local-teste","allowed_kinds":["http"],"min_interval_s":1,"max_assignments":10,
		"allowed_destinations":[{"host":"127.0.0.1","ports":[%d]}],"allowed_cidrs":["127.0.0.1/32"]}`, porta)), 0o600)
	tok := filepath.Join(dir, "token")
	os.WriteFile(tok, []byte(token+"\n"), 0o600)
	off := int64(0)
	return Config{
		ProbeID: probeID, CentralURL: centralURL, TokenFile: tok, PolicyFile: pol,
		SpoolPath: filepath.Join(dir, "estado", "spool.db"), BundleCachePath: filepath.Join(dir, "estado", "bundle.json"),
		StepIntervalMS: 100, HeartbeatS: 30, ConfigRefreshS: 60,
		Clock:        RelogioDeclarado{Status: "synchronized", OffsetMS: &off, ReferenceKind: "lab_declared"},
		Capabilities: Capacidades{IPv4: "available"},
	}, dir
}

func bundleJSON(porta int, geracao int, sonda string, extra map[string]any) []byte {
	b := map[string]any{
		"schema_version": "1.0", "probe_id": sonda, "bundle_revision": 3,
		"issued_at": "2026-09-28T14:00:00Z", "expires_at": "2026-09-29T14:00:00Z",
		"assignments": []any{map[string]any{
			"assignment_id": asgID, "config_generation": geracao, "check_id": "22222222-2222-4222-8222-222222222222",
			"kind": "http", "ip_family": "ipv4",
			"target":          map[string]any{"url": fmt.Sprintf("http://127.0.0.1:%d/health", porta), "method": "GET", "allowed_status_codes": []int{200}, "follow_redirects": false},
			"schedule":        map[string]any{"epoch": "2026-09-28T14:00:00Z", "interval_s": 30, "jitter_offset_ms": 2000, "start_tolerance_ms": 6000, "timeout_ms": 5000},
			"policy_revision": "critical-v1", "path_profile_id": nil, "credential_ref": nil,
		}},
	}
	for k, v := range extra {
		b[k] = v
	}
	out, _ := json.Marshal(b)
	return out
}

func novoProcesso(t *testing.T, cfg Config, rel *relogio.Falso, l *log) *Processo {
	t.Helper()
	p := &Processo{Cfg: cfg, Versao: "0.0.1-teste", Commit: "abc123", Relogio: rel, Logf: l.Logf}
	t.Cleanup(p.Fechar)
	return p
}

// ---- configuração ----

func TestConfigEstritaEPadroes(t *testing.T) {
	ok := `{"probe_id":"p","central_url":"https://c.example","token_file":"t","policy_file":"p","spool_path":"s","bundle_cache_path":"b"}`
	c, err := LerConfig([]byte(ok))
	if err != nil {
		t.Fatal(err)
	}
	if c.StepIntervalMS != 1000 || c.HeartbeatS != 30 || c.ConfigRefreshS != 60 {
		t.Fatalf("padrões: %+v", c)
	}
	if c.Clock.Status != "unknown" || c.Clock.OffsetMS != nil {
		t.Fatalf("sem declaração o relógio é DESCONHECIDO, com offset nulo: %+v", c.Clock)
	}

	ruins := map[string]string{
		"campo desconhecido":              strings.Replace(ok, "}", `,"allow_all":true}`, 1),
		"sem central":                     strings.Replace(ok, `"central_url":"https://c.example",`, "", 1),
		"url sem esquema":                 strings.Replace(ok, "https://c.example", "c.example", 1),
		"sem token":                       strings.Replace(ok, `"token_file":"t",`, "", 1),
		"passo curto demais":              strings.Replace(ok, "}", `,"step_interval_ms":10}`, 1),
		"relógio declarado sem origem":    strings.Replace(ok, "}", `,"clock":{"status":"synchronized","offset_ms":0}}`, 1),
		"relógio sincronizado sem offset": strings.Replace(ok, "}", `,"clock":{"status":"synchronized","reference_kind":"x"}}`, 1),
		"relógio desconhecido com offset": strings.Replace(ok, "}", `,"clock":{"status":"unknown","offset_ms":0}}`, 1),
		"estado de relógio inventado":     strings.Replace(ok, "}", `,"clock":{"status":"perfeito","offset_ms":0,"reference_kind":"x"}}`, 1),
	}
	for nome, cfg := range ruins {
		if _, err := LerConfig([]byte(cfg)); err == nil {
			t.Errorf("%s: devia ser recusada", nome)
		}
	}
}

func TestTokenSoComModoRestrito(t *testing.T) {
	dir := t.TempDir()
	f := filepath.Join(dir, "t")
	os.WriteFile(f, []byte("SEGREDO-NAO-VAZAR\n"), 0o644)
	_, err := LerToken(f)
	if err == nil {
		t.Fatal("token legível por todos tem de ser recusado")
	}
	if strings.Contains(err.Error(), "SEGREDO-NAO-VAZAR") {
		t.Fatalf("a mensagem de erro vazou o token: %v", err)
	}
	os.Chmod(f, 0o640)
	if _, err := LerToken(f); err == nil {
		t.Fatal("0640 (grupo lê) tem de ser recusado")
	}
	os.Chmod(f, 0o600)
	if got, err := LerToken(f); err != nil || got != "SEGREDO-NAO-VAZAR" {
		t.Fatalf("0600 devia servir: %q %v", got, err)
	}
	os.Chmod(f, 0o400)
	if _, err := LerToken(f); err != nil {
		t.Fatalf("0400 também serve: %v", err)
	}
	vazio := filepath.Join(dir, "vazio")
	os.WriteFile(vazio, []byte("  \n"), 0o600)
	if _, err := LerToken(vazio); err == nil {
		t.Fatal("token vazio tem de ser recusado")
	}
	if _, err := LerToken(filepath.Join(dir, "nao-existe")); err == nil {
		t.Fatal("arquivo ausente tem de ser recusado")
	}
}

// ---- o fluxo completo, com relógio falso ----

func TestFluxoCompletoBuscaConfigMedeEnviaEMandaHeartbeat(t *testing.T) {
	porta := alvoLocal(t)
	central := testes.Nova(token)
	defer central.Close()
	central.DefinirBundle(bundleJSON(porta, 3, probeID, nil))
	cfg, _ := ambiente(t, central.URL(), porta)
	rel := relogio.NovoFalso(epoch.Add(3 * time.Second)) // dentro da janela do slot 0 (+2..+8 s)
	l := &log{}
	p := novoProcesso(t, cfg, rel, l)

	if err := p.Iniciar(context.Background()); err != nil {
		t.Fatal(err)
	}
	if err := p.Tick(context.Background()); err != nil {
		t.Fatal(err)
	}
	if err := p.Tick(context.Background()); err != nil { // repetido no mesmo instante: nada novo
		t.Fatal(err)
	}

	// A configuração veio da central e foi para o disco, com modo 0600.
	info, err := os.Stat(cfg.BundleCachePath)
	if err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("o bundle tem de estar em disco com 0600: %v %v", info, err)
	}
	// O slot foi medido, o resultado entregue UMA vez, e o heartbeat chegou.
	if central.Gravados() != 1 {
		t.Fatalf("a central devia ter 1 resultado: %d", central.Gravados())
	}
	if len(central.Heartbeats()) != 1 {
		t.Fatalf("heartbeats: %d", len(central.Heartbeats()))
	}
	var h map[string]any
	json.Unmarshal(central.Heartbeats()[0], &h)
	if h["probe_id"] != probeID || h["applied"].(map[string]any)["bundle_revision"] != float64(3) {
		t.Fatalf("heartbeat: %v", h)
	}
	if h["build"].(map[string]any)["commit"] != "abc123" {
		t.Fatalf("build: %v", h["build"])
	}
	if h["clock"].(map[string]any)["reference_kind"] != "lab_declared" {
		t.Fatalf("o relógio declarado tem de dizer que é declarado: %v", h["clock"])
	}
	if e, _ := p.sp.Estatisticas(context.Background()); e.Linhas != 0 {
		t.Fatalf("o spool devia estar vazio: %+v", e)
	}
}

func TestHeartbeatSoNaHora(t *testing.T) {
	porta := alvoLocal(t)
	central := testes.Nova(token)
	defer central.Close()
	central.DefinirBundle(bundleJSON(porta, 3, probeID, nil))
	cfg, _ := ambiente(t, central.URL(), porta)
	rel := relogio.NovoFalso(epoch.Add(time.Second))
	p := novoProcesso(t, cfg, rel, &log{})
	p.Iniciar(context.Background())

	p.Tick(context.Background()) // t=1: primeiro heartbeat
	rel.Avancar(10 * time.Second)
	p.Tick(context.Background()) // t=11: ainda não
	rel.Avancar(10 * time.Second)
	p.Tick(context.Background()) // t=21: ainda não
	if n := len(central.Heartbeats()); n != 1 {
		t.Fatalf("heartbeats antes da hora: %d", n)
	}
	rel.Avancar(10 * time.Second)
	p.Tick(context.Background()) // t=31: agora
	if n := len(central.Heartbeats()); n != 2 {
		t.Fatalf("heartbeats na hora: %d", n)
	}
}

func TestSemACentralNaPartidaUsaAUltimaConfiguracaoValida(t *testing.T) {
	porta := alvoLocal(t)
	central := testes.Nova(token)
	defer central.Close()
	central.DefinirBundle(bundleJSON(porta, 3, probeID, nil))
	cfg, _ := ambiente(t, central.URL(), porta)

	// 1ª vida da sonda: pega a configuração da central e a guarda.
	p1 := novoProcesso(t, cfg, relogio.NovoFalso(epoch.Add(time.Second)), &log{})
	if err := p1.Iniciar(context.Background()); err != nil {
		t.Fatal(err)
	}
	p1.Fechar()

	// 2ª vida: a central NÃO tem bundle (503) e a rede dela está fora. A sonda mede com a configuração em disco.
	central.DefinirBundle(nil)
	central.DefinirModo(testes.Fora, 0)
	rel := relogio.NovoFalso(epoch.Add(3 * time.Second))
	l := &log{}
	p2 := novoProcesso(t, cfg, rel, l)
	if err := p2.Iniciar(context.Background()); err != nil {
		t.Fatal(err)
	}
	p2.Tick(context.Background())

	if e, _ := p2.sp.Estatisticas(context.Background()); e.Linhas != 1 {
		t.Fatalf("mediu com a configuração em disco e guardou o resultado: %+v", e)
	}
	if central.Gravados() != 0 {
		t.Fatal("a central estava fora")
	}

	// A central volta: o que ficou no spool é entregue, uma vez.
	central.DefinirModo(testes.Normal, 0)
	rel.Avancar(time.Minute)
	p2.Tick(context.Background())
	// Um minuto depois: o resultado do slot 0 (que estava no spool), o `skipped` do slot 1 (a janela fechou) e o do slot 2, medido agora.
	if central.Gravados() != 3 {
		t.Fatalf("o spool devia ter sido entregue por inteiro (3 resultados): %d", central.Gravados())
	}
	if e, _ := p2.sp.Estatisticas(context.Background()); e.Linhas != 0 {
		t.Fatalf("nada devia sobrar no spool: %+v", e)
	}
	if !strings.Contains(l.Tudo(), "config:") {
		t.Fatalf("o log tem de dizer que a configuração da central não foi obtida:\n%s", l.Tudo())
	}
}

func TestBundleInvalidoNaoEAplicadoNemGravadoEAVigenteContinua(t *testing.T) {
	porta := alvoLocal(t)
	central := testes.Nova(token)
	defer central.Close()
	central.DefinirBundle(bundleJSON(porta, 3, probeID, nil))
	cfg, _ := ambiente(t, central.URL(), porta)
	rel := relogio.NovoFalso(epoch.Add(3 * time.Second))
	l := &log{}
	p := novoProcesso(t, cfg, rel, l)
	if err := p.Iniciar(context.Background()); err != nil {
		t.Fatal(err)
	}
	boa, _ := os.ReadFile(cfg.BundleCachePath)

	for nome, ruim := range map[string][]byte{
		"campo extra (callback)": bundleJSON(porta, 3, probeID, map[string]any{"callback_url": "https://evil.example/"}),
		"de outra sonda":         bundleJSON(porta, 3, "99999999-9999-4999-8999-999999999999", nil),
		"não é JSON":             []byte("<html>proxy captivo</html>"),
		"geração zero":           bundleJSON(porta, 0, probeID, nil),
	} {
		central.DefinirBundle(ruim)
		rel.Avancar(61 * time.Second)
		p.Tick(context.Background())

		depois, _ := os.ReadFile(cfg.BundleCachePath)
		if string(depois) != string(boa) {
			t.Fatalf("%s: o bundle recusado SOBRESCREVEU o bom em disco", nome)
		}
		if p.co.Bundle() == nil || p.co.Bundle().Assignments[0].ConfigGeneration != 3 {
			t.Fatalf("%s: a configuração vigente mudou", nome)
		}
	}
	if !strings.Contains(l.Tudo(), "RECUSADO") {
		t.Fatalf("a recusa tem de aparecer no log:\n%s", l.Tudo())
	}
}

func TestBundleNovoValidoSubstituiEGrava(t *testing.T) {
	porta := alvoLocal(t)
	central := testes.Nova(token)
	defer central.Close()
	central.DefinirBundle(bundleJSON(porta, 3, probeID, nil))
	cfg, _ := ambiente(t, central.URL(), porta)
	rel := relogio.NovoFalso(epoch.Add(time.Second))
	p := novoProcesso(t, cfg, rel, &log{})
	p.Iniciar(context.Background())

	central.DefinirBundle(bundleJSON(porta, 4, probeID, nil))
	rel.Avancar(61 * time.Second)
	p.Tick(context.Background())

	if g := p.co.Bundle().Assignments[0].ConfigGeneration; g != 4 {
		t.Fatalf("geração aplicada = %d", g)
	}
	em, _ := os.ReadFile(cfg.BundleCachePath)
	if !strings.Contains(string(em), `"config_generation":4`) {
		t.Fatalf("o bundle novo tem de estar em disco: %s", em)
	}
}

func TestConfiguracaoSoESBuscadaNaHora(t *testing.T) {
	porta := alvoLocal(t)
	central := testes.Nova(token)
	defer central.Close()
	central.DefinirBundle(bundleJSON(porta, 3, probeID, nil))
	cfg, _ := ambiente(t, central.URL(), porta)
	rel := relogio.NovoFalso(epoch.Add(time.Second))
	p := novoProcesso(t, cfg, rel, &log{})
	p.Iniciar(context.Background()) // busca 1

	rel.Avancar(30 * time.Second)
	p.Tick(context.Background())
	if central.Configs() != 1 {
		t.Fatalf("buscou antes da hora: %d", central.Configs())
	}
	rel.Avancar(31 * time.Second)
	p.Tick(context.Background())
	if central.Configs() != 2 {
		t.Fatalf("na hora devia buscar de novo: %d", central.Configs())
	}
}

func TestTokenNuncaApareceNoLog(t *testing.T) {
	porta := alvoLocal(t)
	central := testes.Nova("outro-token") // a central recusa esta sonda: 401 em tudo
	defer central.Close()
	cfg, _ := ambiente(t, central.URL(), porta)
	rel := relogio.NovoFalso(epoch.Add(3 * time.Second))
	l := &log{}
	p := novoProcesso(t, cfg, rel, l)
	p.Iniciar(context.Background())
	p.Tick(context.Background())
	rel.Avancar(time.Minute)
	p.Tick(context.Background())

	if strings.Contains(l.Tudo(), token) || strings.Contains(l.Tudo(), "SEGREDO") {
		t.Fatalf("o token vazou para o log:\n%s", l.Tudo())
	}
	if l.Tudo() == "" {
		t.Fatal("o 401 devia ter sido registrado")
	}
}

func TestConfigExpiradaAvisaUmaVezPorMinuto(t *testing.T) {
	porta := alvoLocal(t)
	central := testes.Nova(token)
	defer central.Close()
	central.DefinirBundle(bundleJSON(porta, 3, probeID, map[string]any{"expires_at": "2026-09-28T14:00:10Z"}))
	cfg, _ := ambiente(t, central.URL(), porta)
	cfg.ConfigRefreshS = 3600
	rel := relogio.NovoFalso(epoch.Add(time.Minute)) // já expirou
	l := &log{}
	p := novoProcesso(t, cfg, rel, l)
	p.Iniciar(context.Background())

	for i := 0; i < 30; i++ {
		p.Tick(context.Background())
		rel.Avancar(time.Second)
	}
	n := strings.Count(l.Tudo(), "expirou")
	if n < 1 || n > 2 {
		t.Fatalf("o aviso de configuração vencida deve sair 1 vez por minuto, saiu %d:\n%s", n, l.Tudo())
	}
	if central.Gravados() != 0 {
		t.Fatal("configuração vencida não executa")
	}
}

func TestEncerrarFechaOSpoolENadaFicaPelaMetade(t *testing.T) {
	porta := alvoLocal(t)
	central := testes.Nova(token)
	defer central.Close()
	central.DefinirBundle(bundleJSON(porta, 3, probeID, nil))
	cfg, _ := ambiente(t, central.URL(), porta)
	p := &Processo{Cfg: cfg, Versao: "t", Logf: (&log{}).Logf}

	ctx, cancela := context.WithCancel(context.Background())
	feito := make(chan error, 1)
	go func() { feito <- p.Rodar(ctx) }()
	time.Sleep(400 * time.Millisecond)
	cancela()

	select {
	case err := <-feito:
		if err != nil {
			t.Fatalf("encerrar com o contexto cancelado é o caminho normal: %v", err)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("o processo não encerrou")
	}
	// O spool pode ser reaberto, e não há slot incompleto.
	sp, err := spool.Abrir(cfg.SpoolPath, spool.Limites{}, relogio.Sistema{})
	if err != nil {
		t.Fatalf("reabrir depois de encerrar: %v", err)
	}
	defer sp.Fechar()
	if inc, _ := sp.Incompletos(context.Background()); len(inc) != 0 {
		t.Fatalf("slots incompletos depois de um encerramento limpo: %+v", inc)
	}
}

func TestFalhaDeInicioNaoDeixaSpoolAberto(t *testing.T) {
	porta := alvoLocal(t)
	cfg, _ := ambiente(t, "http://127.0.0.1:1", porta)
	cfg.PolicyFile = filepath.Join(filepath.Dir(cfg.PolicyFile), "nao-existe.json")
	p := &Processo{Cfg: cfg}

	if err := p.Rodar(context.Background()); err == nil {
		t.Fatal("sem a política local a sonda não pode subir")
	}
}

// ---- executor por socket ----

func TestExecutorPorSocketUnix(t *testing.T) {
	porta := alvoLocal(t)
	central := testes.Nova(token)
	defer central.Close()
	central.DefinirBundle(bundleJSON(porta, 3, probeID, nil))
	cfg, dir := ambiente(t, central.URL(), porta)

	pol, err := politica.Carregar(cfg.PolicyFile)
	if err != nil {
		t.Fatal(err)
	}
	cfg.ExecutorSocket = filepath.Join(dir, "executor.sock")
	l, err := executor.Ouvir(cfg.ExecutorSocket)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancela := context.WithCancel(context.Background())
	feito := make(chan struct{})
	go func() {
		executor.Servir(ctx, l, &executor.HTTP{Politica: pol, Relogio: relogio.Sistema{}})
		close(feito)
	}()
	defer func() { cancela(); <-feito }()

	p := novoProcesso(t, cfg, relogio.NovoFalso(epoch.Add(3*time.Second)), &log{})
	p.Iniciar(context.Background())
	p.Tick(context.Background())

	if central.Gravados() != 1 {
		t.Fatalf("o resultado medido pelo executor do socket devia chegar: %d", central.Gravados())
	}
}

// SIGTERM no MEIO de uma medição: o passo em andamento termina (o slot é finalizado e entregue), e a sonda sai com código 0. Cancelar a
// medição no meio deixaria um slot `executando` à toa e faria o processo sair com erro.
func TestEncerrarNoMeioDeUmaMedicaoTerminaOPassoENaoDeixaSlotIncompleto(t *testing.T) {
	var emVoo sync.WaitGroup
	emVoo.Add(1)
	var uma sync.Once
	lento := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		uma.Do(emVoo.Done) // a requisição CHEGOU: o executor está no meio da medição
		time.Sleep(400 * time.Millisecond)
		w.WriteHeader(200)
	}))
	defer lento.Close()
	porta := lento.Listener.Addr().(*net.TCPAddr).Port

	central := testes.Nova(token)
	defer central.Close()
	agora := time.Now().UTC().Truncate(time.Second)
	b := map[string]any{}
	json.Unmarshal(bundleJSON(porta, 1, probeID, nil), &b)
	asg := b["assignments"].([]any)[0].(map[string]any)
	asg["schedule"] = map[string]any{"epoch": agora.Add(-2 * time.Second).Format(time.RFC3339), "interval_s": 1, "jitter_offset_ms": 0, "start_tolerance_ms": 900, "timeout_ms": 900}
	b["issued_at"] = agora.Add(-time.Minute).Format(time.RFC3339)
	b["expires_at"] = agora.Add(time.Hour).Format(time.RFC3339)
	corpo, _ := json.Marshal(b)
	central.DefinirBundle(corpo)
	cfg, _ := ambiente(t, central.URL(), porta)
	cfg.StepIntervalMS = 50
	p := &Processo{Cfg: cfg, Versao: "t", Logf: (&log{}).Logf}

	ctx, cancela := context.WithCancel(context.Background())
	feito := make(chan error, 1)
	go func() { feito <- p.Rodar(ctx) }()
	emVoo.Wait()
	cancela() // SIGTERM com a medição em andamento

	select {
	case err := <-feito:
		if err != nil {
			t.Fatalf("encerrar no meio de uma medição devia sair sem erro: %v", err)
		}
	case <-time.After(10 * time.Second):
		t.Fatal("o processo não encerrou")
	}
	sp, err := spool.Abrir(cfg.SpoolPath, spool.Limites{}, relogio.Sistema{})
	if err != nil {
		t.Fatal(err)
	}
	defer sp.Fechar()
	if inc, _ := sp.Incompletos(context.Background()); len(inc) != 0 {
		t.Fatalf("a medição em andamento tinha de ser FINALIZADA antes de sair: %+v", inc)
	}
}

// ---- o que o processo faz na PARTIDA e na PARADA, e que os pacotes não enxergam ----

// Um slot que ficou `executando` por uma queda é resolvido na partida, antes do primeiro passo: vira `executor_error` com a identidade
// original e chega à central, e o executor NÃO é chamado de novo.
func TestPartidaReconciliaOQueAQuedaDeixouPelaMetade(t *testing.T) {
	porta := alvoLocal(t)
	central := testes.Nova(token)
	defer central.Close()
	central.DefinirBundle(bundleJSON(porta, 3, probeID, nil))
	cfg, _ := ambiente(t, central.URL(), porta)

	// A vida anterior: planejou o slot 0, começou a executar e morreu.
	if err := os.MkdirAll(filepath.Dir(cfg.SpoolPath), 0o700); err != nil {
		t.Fatal(err)
	}
	rel := relogio.NovoFalso(epoch.Add(3 * time.Second))
	sp, err := spool.Abrir(cfg.SpoolPath, spool.Limites{}, rel)
	if err != nil {
		t.Fatal(err)
	}
	var b map[string]any
	json.Unmarshal(bundleJSON(porta, 3, probeID, nil), &b)
	asg, _ := json.Marshal(b["assignments"].([]any)[0])
	it := spool.ItemAgenda{AssignmentID: asgID, Geracao: 3, Slot: epoch, ResultID: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", Contexto: asg}
	sp.Planejar(context.Background(), it)
	sp.Iniciar(context.Background(), it)
	sp.Fechar()

	contador := &contaExecucoes{}
	p := novoProcesso(t, cfg, rel, &log{})
	p.Executor = contador
	if err := p.Iniciar(context.Background()); err != nil {
		t.Fatal(err)
	}
	p.Tick(context.Background())

	if central.Gravados() != 1 || !central.TemResultado("aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa") {
		t.Fatalf("o slot interrompido tem de chegar à central com a identidade original: %d", central.Gravados())
	}
	if contador.n != 0 {
		t.Fatalf("o executor foi chamado %d vez(es) para um slot já resolvido", contador.n)
	}
}

type contaExecucoes struct{ n int }

func (c *contaExecucoes) Executar(context.Context, coordenador.Execucao) coordenador.Saida {
	c.n++
	return coordenador.Saida{Outcome: "pass", Reason: "http_assertions_passed"}
}

// A falha da rede NUNCA derruba o processo: o passo devolve nil, o spool guarda e o log diz o que houve.
func TestFalhaDeRedeNaoEFatal(t *testing.T) {
	porta := alvoLocal(t)
	central := testes.Nova(token)
	defer central.Close()
	central.DefinirBundle(bundleJSON(porta, 3, probeID, nil))
	cfg, _ := ambiente(t, central.URL(), porta)
	rel := relogio.NovoFalso(epoch.Add(3 * time.Second))
	p := novoProcesso(t, cfg, rel, &log{})
	p.Iniciar(context.Background())

	central.DefinirModo(testes.Fora, 0)
	for i := 0; i < 3; i++ {
		if err := p.Tick(context.Background()); err != nil {
			t.Fatalf("erro de rede não pode ser fatal: %v", err)
		}
		rel.Avancar(31 * time.Second)
	}
	if e, _ := p.sp.Estatisticas(context.Background()); e.Linhas == 0 {
		t.Fatal("o spool devia estar guardando o que não saiu")
	}
}

// O executor configurado por SOCKET é o que mede: o resultado traz o que o executor do socket devolveu, e não o do executor embutido.
func TestOExecutorDoSocketEOQueMede(t *testing.T) {
	porta := alvoLocal(t)
	central := testes.Nova(token)
	defer central.Close()
	central.DefinirBundle(bundleJSON(porta, 3, probeID, nil))
	cfg, dir := ambiente(t, central.URL(), porta)
	cfg.ExecutorSocket = filepath.Join(dir, "executor.sock")

	l, err := executor.Ouvir(cfg.ExecutorSocket)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancela := context.WithCancel(context.Background())
	feito := make(chan struct{})
	go func() { executor.Servir(ctx, l, marcador{}); close(feito) }()
	defer func() { cancela(); <-feito }()

	p := novoProcesso(t, cfg, relogio.NovoFalso(epoch.Add(3*time.Second)), &log{})
	p.Iniciar(context.Background())
	p.Tick(context.Background())

	// O executor embutido mediria o alvo local e daria `pass`; o do socket devolve `capability_unavailable`.
	itens, _ := p.sp.Proximos(context.Background(), 10, 1<<20)
	if len(itens) != 0 {
		t.Fatalf("já entregue: %d", len(itens))
	}
	corpo := central.Corpo(0)
	if !strings.Contains(corpo, `"outcome":"capability_unavailable"`) {
		t.Fatalf("o resultado não veio do executor do socket: %s", corpo)
	}
}

type marcador struct{}

func (marcador) Executar(context.Context, coordenador.Execucao) coordenador.Saida {
	return coordenador.Saida{Outcome: "capability_unavailable", Reason: "family_unavailable"}
}

func TestSpoolFechaAoSairEDepoisDeUmaFalhaDeInicio(t *testing.T) {
	porta := alvoLocal(t)
	central := testes.Nova(token)
	defer central.Close()
	central.DefinirBundle(bundleJSON(porta, 3, probeID, nil))

	// Saída normal: o spool é fechado.
	cfg, _ := ambiente(t, central.URL(), porta)
	p := &Processo{Cfg: cfg, Logf: (&log{}).Logf}
	ctx, cancela := context.WithCancel(context.Background())
	feito := make(chan error, 1)
	go func() { feito <- p.Rodar(ctx) }()
	time.Sleep(300 * time.Millisecond)
	cancela()
	<-feito
	if p.sp != nil {
		t.Fatal("o spool continuou aberto depois de a sonda sair")
	}

	// Falha DEPOIS de o spool abrir (a CA de TLS não existe): o spool não pode ficar aberto.
	cfg2, _ := ambiente(t, central.URL(), porta)
	cfg2.TLSCAFile = filepath.Join(filepath.Dir(cfg2.SpoolPath), "nao-existe.pem")
	p2 := &Processo{Cfg: cfg2}
	if err := p2.Rodar(context.Background()); err == nil {
		t.Fatal("CA inexistente devia impedir a partida")
	}
	if p2.sp != nil {
		t.Fatal("a partida falhou com o spool aberto")
	}
}
