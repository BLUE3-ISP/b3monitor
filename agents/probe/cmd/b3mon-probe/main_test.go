package main

import (
	"context"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/relogio"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/spool"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/testes"
)

const (
	probeID = "33333333-3333-4333-8333-333333333333"
	asgID   = "44444444-4444-4444-8444-444444444444"
	token   = "b3m_p_TOKEN-DO-BINARIO"
)

func compilar(t *testing.T) string {
	t.Helper()
	bin := filepath.Join(t.TempDir(), "b3mon-probe")
	out, err := exec.Command("go", "build", "-trimpath", "-ldflags", "-X main.versao=9.9.9-teste -X main.commit=cafe", "-o", bin, ".").CombinedOutput()
	if err != nil {
		t.Fatalf("go build: %v\n%s", err, out)
	}
	return bin
}

func TestVersaoECodigosDeSaida(t *testing.T) {
	bin := compilar(t)
	out, err := exec.Command(bin, "version").CombinedOutput()
	if err != nil || !strings.Contains(string(out), "9.9.9-teste (cafe)") {
		t.Fatalf("version: %v %s", err, out)
	}
	for nome, args := range map[string][]string{
		"sem comando":       {},
		"comando inventado": {"destruir"},
		"run sem -config":   {"run"},
		"executor sem args": {"executor"},
	} {
		if err := exec.Command(bin, args...).Run(); err == nil {
			t.Errorf("%s devia sair com erro", nome)
		}
	}
	// Configuração inexistente e malformada: erro, e SEM abrir nada.
	dir := t.TempDir()
	if err := exec.Command(bin, "run", "-config", filepath.Join(dir, "nao-existe.json")).Run(); err == nil {
		t.Error("config inexistente devia falhar")
	}
	ruim := filepath.Join(dir, "ruim.json")
	os.WriteFile(ruim, []byte(`{"permitir_tudo": true}`), 0o600)
	cmd := exec.Command(bin, "run", "-config", ruim)
	saida, err := cmd.CombinedOutput()
	if err == nil || !strings.Contains(string(saida), "configuração") {
		t.Errorf("config malformada devia falhar dizendo o motivo: %v %s", err, saida)
	}
}

// O binário de verdade, com o relógio de verdade: sobe, pega a configuração da central, mede um alvo local, entrega os resultados e
// o heartbeat, e termina LIMPO em SIGTERM, com o spool reabrível e sem slot incompleto.
func TestBinarioSobeMedeEntregaEParaLimpo(t *testing.T) {
	if testing.Short() {
		t.Skip("usa o relógio real")
	}
	bin := compilar(t)
	alvo := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(200) }))
	defer alvo.Close()
	porta := alvo.Listener.Addr().(*net.TCPAddr).Port

	central := testes.Nova(token)
	defer central.Close()
	epoch := time.Now().UTC().Add(-3 * time.Second).Truncate(time.Second)
	bundle, _ := json.Marshal(map[string]any{
		"schema_version": "1.0", "probe_id": probeID, "bundle_revision": 1,
		"issued_at": epoch.Format(time.RFC3339), "expires_at": epoch.Add(time.Hour).Format(time.RFC3339),
		"assignments": []any{map[string]any{
			"assignment_id": asgID, "config_generation": 1, "check_id": "22222222-2222-4222-8222-222222222222",
			"kind": "http", "ip_family": "ipv4",
			"target":          map[string]any{"url": fmt.Sprintf("http://127.0.0.1:%d/", porta), "method": "GET", "allowed_status_codes": []int{200}, "follow_redirects": false},
			"schedule":        map[string]any{"epoch": epoch.Format(time.RFC3339), "interval_s": 1, "jitter_offset_ms": 0, "start_tolerance_ms": 900, "timeout_ms": 500},
			"policy_revision": "critical-v1", "path_profile_id": nil, "credential_ref": nil,
		}},
	})
	central.DefinirBundle(bundle)

	dir := t.TempDir()
	pol := filepath.Join(dir, "politica.json")
	os.WriteFile(pol, []byte(fmt.Sprintf(`{"revision":"local-teste","allowed_kinds":["http"],"min_interval_s":1,"max_assignments":5,
		"allowed_destinations":[{"host":"127.0.0.1","ports":[%d]}],"allowed_cidrs":["127.0.0.1/32"]}`, porta)), 0o600)
	tok := filepath.Join(dir, "token")
	os.WriteFile(tok, []byte(token), 0o600)
	cfgPath := filepath.Join(dir, "probe.json")
	spoolPath := filepath.Join(dir, "estado", "spool.db")
	cachePath := filepath.Join(dir, "estado", "bundle.json")
	cfg, _ := json.Marshal(map[string]any{
		"probe_id": probeID, "central_url": central.URL(), "token_file": tok, "policy_file": pol,
		"spool_path": spoolPath, "bundle_cache_path": cachePath, "step_interval_ms": 100, "heartbeat_interval_s": 1,
		"clock": map[string]any{"status": "synchronized", "offset_ms": 0, "reference_kind": "lab_declared"},
	})
	os.WriteFile(cfgPath, cfg, 0o600)

	cmd := exec.Command(bin, "run", "-config", cfgPath)
	var saida strings.Builder
	cmd.Stderr = &saida
	if err := cmd.Start(); err != nil {
		t.Fatal(err)
	}
	pronto := make(chan error, 1)
	go func() { pronto <- cmd.Wait() }()
	defer func() { cmd.Process.Kill() }()

	// Espera 3 resultados na central e ao menos 1 heartbeat (até 20 s).
	limite := time.Now().Add(20 * time.Second)
	for time.Now().Before(limite) && (central.Gravados() < 3 || len(central.Heartbeats()) < 1) {
		time.Sleep(100 * time.Millisecond)
	}
	if central.Gravados() < 3 || len(central.Heartbeats()) < 1 {
		t.Fatalf("resultados=%d heartbeats=%d\nlog do processo:\n%s", central.Gravados(), len(central.Heartbeats()), saida.String())
	}

	// SIGTERM: termina limpo (código 0).
	cmd.Process.Signal(syscall.SIGTERM)
	select {
	case err := <-pronto:
		if err != nil {
			t.Fatalf("SIGTERM devia terminar com código 0: %v\n%s", err, saida.String())
		}
	case <-time.After(10 * time.Second):
		t.Fatalf("o processo não terminou com SIGTERM\n%s", saida.String())
	}

	// O token não está no log, e o spool reabre sem slot incompleto; o bundle ficou em disco com 0600.
	if strings.Contains(saida.String(), token) {
		t.Fatalf("o token vazou para o log:\n%s", saida.String())
	}
	sp, err := spool.Abrir(spoolPath, spool.Limites{}, relogio.Sistema{})
	if err != nil {
		t.Fatalf("reabrir o spool: %v", err)
	}
	defer sp.Fechar()
	if inc, _ := sp.Incompletos(context.Background()); len(inc) != 0 {
		t.Fatalf("slots incompletos depois de SIGTERM: %+v", inc)
	}
	if info, err := os.Stat(cachePath); err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("bundle em disco: %v %v", info, err)
	}
}

// O modo `executor` escuta SÓ em socket Unix 0600, e responde pelo mesmo protocolo.
func TestModoExecutorEscutaSoNoSocketUnix(t *testing.T) {
	bin := compilar(t)
	dir := t.TempDir()
	pol := filepath.Join(dir, "p.json")
	os.WriteFile(pol, []byte(`{"revision":"r","allowed_kinds":["http"],"min_interval_s":1,"max_assignments":1,"allowed_destinations":[{"host":"x.example","ports":[80]}],"allowed_cidrs":["203.0.113.0/24"]}`), 0o600)
	sock := filepath.Join(dir, "e.sock")
	cmd := exec.Command(bin, "executor", "-policy", pol, "-socket", sock)
	if err := cmd.Start(); err != nil {
		t.Fatal(err)
	}
	defer func() { cmd.Process.Signal(syscall.SIGTERM); cmd.Wait() }()

	var info os.FileInfo
	var err error
	for i := 0; i < 50; i++ {
		if info, err = os.Stat(sock); err == nil {
			break
		}
		time.Sleep(100 * time.Millisecond)
	}
	if err != nil || info.Mode()&os.ModeSocket == 0 || info.Mode().Perm() != 0o600 {
		t.Fatalf("socket: %v %v", info, err)
	}
	c, err := net.DialTimeout("unix", sock, time.Second)
	if err != nil {
		t.Fatal(err)
	}
	c.Close()
}

// Uma falha DEPOIS de a configuração ser lida (aqui, a CA de TLS não existe) sai com código 1 e diz o motivo, sem token no log.
func TestFalhaNaPartidaSaiComCodigo1(t *testing.T) {
	bin := compilar(t)
	dir := t.TempDir()
	pol := filepath.Join(dir, "p.json")
	os.WriteFile(pol, []byte(`{"revision":"r","allowed_kinds":["http"],"min_interval_s":1,"max_assignments":1}`), 0o600)
	tok := filepath.Join(dir, "token")
	os.WriteFile(tok, []byte(token), 0o600)
	cfg, _ := json.Marshal(map[string]any{
		"probe_id": probeID, "central_url": "https://central.invalido", "token_file": tok, "policy_file": pol,
		"spool_path": filepath.Join(dir, "estado", "spool.db"), "bundle_cache_path": filepath.Join(dir, "estado", "bundle.json"),
		"tls_ca_file": filepath.Join(dir, "nao-existe.pem"),
	})
	cfgPath := filepath.Join(dir, "probe.json")
	os.WriteFile(cfgPath, cfg, 0o600)

	cmd := exec.Command(bin, "run", "-config", cfgPath)
	saida, err := cmd.CombinedOutput()

	ee, ok := err.(*exec.ExitError)
	if !ok || ee.ExitCode() != 1 {
		t.Fatalf("esperava código de saída 1: %v\n%s", err, saida)
	}
	if !strings.Contains(string(saida), "erro fatal") || strings.Contains(string(saida), token) {
		t.Fatalf("a saída tem de dizer o erro e NÃO conter o token:\n%s", saida)
	}
}

func TestComandoDesconhecidoSaiComCodigo2(t *testing.T) {
	bin := compilar(t)
	err := exec.Command(bin, "destruir").Run()
	if ee, ok := err.(*exec.ExitError); !ok || ee.ExitCode() != 2 {
		t.Fatalf("esperava 2: %v", err)
	}
	err = exec.Command(bin, "run").Run()
	if ee, ok := err.(*exec.ExitError); !ok || ee.ExitCode() != 2 {
		t.Fatalf("run sem -config: esperava 2: %v", err)
	}
}
