package main

import (
	"encoding/json"
	"io"
	"log"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

func novo(t *testing.T) (*httptest.Server, *httptest.Server, *Alvo) {
	t.Helper()
	a := NovoAlvo(150*time.Millisecond, nil)
	alvo := httptest.NewServer(a.TratadorAlvo())
	ctl := httptest.NewServer(a.TratadorControle())
	t.Cleanup(alvo.Close)
	t.Cleanup(ctl.Close)
	return alvo, ctl, a
}

func ler(t *testing.T, r *http.Response) (map[string]any, http.Header) {
	t.Helper()
	defer r.Body.Close()
	b, _ := io.ReadAll(r.Body)
	var m map[string]any
	if err := json.Unmarshal(b, &m); err != nil {
		t.Fatalf("corpo não é JSON: %q", b)
	}
	return m, r.Header
}

func trocar(t *testing.T, ctl *httptest.Server, modo string) *http.Response {
	t.Helper()
	r, err := http.Post(ctl.URL+"/modo/"+modo, "", nil)
	if err != nil {
		t.Fatal(err)
	}
	return r
}

// TODA resposta se identifica como laboratório: no cabeçalho e, no corpo, quando há corpo.
func TestTodaRespostaSeIdentificaComoLab(t *testing.T) {
	alvo, ctl, _ := novo(t)
	for nome, url := range map[string]string{
		"alvo /health": alvo.URL + "/health", "alvo 404": alvo.URL + "/qualquer", "controle GET": ctl.URL + "/modo", "controle 404": ctl.URL + "/x",
	} {
		r, err := http.Get(url)
		if err != nil {
			t.Fatal(err)
		}
		m, h := ler(t, r)
		if h.Get("X-B3mon-Lab") != "lab" || h.Get("Server") != "b3mon-lab-target" {
			t.Errorf("%s: sem a marca de laboratório nos cabeçalhos: %v", nome, h)
		}
		if m["lab"] != true || m["service"] != "b3mon-lab-target" {
			t.Errorf("%s: sem a marca de laboratório no corpo: %v", nome, m)
		}
	}
	// Inclusive a resposta de erro do controle e a troca de modo.
	r := trocar(t, ctl, "invalido")
	m, h := ler(t, r)
	if r.StatusCode != 400 || h.Get("X-B3mon-Lab") != "lab" || m["lab"] != true {
		t.Errorf("400 do controle: %d %v %v", r.StatusCode, h, m)
	}
	r = trocar(t, ctl, "falha")
	_, h = ler(t, r)
	if h.Get("X-B3mon-Lab") != "lab" {
		t.Error("a resposta da troca de modo não leva a marca")
	}
}

func TestModoOkFalhaELento(t *testing.T) {
	alvo, ctl, _ := novo(t)

	r, _ := http.Get(alvo.URL + "/health")
	m, _ := ler(t, r)
	if r.StatusCode != 200 || m["mode"] != "ok" {
		t.Fatalf("modo ok: %d %v", r.StatusCode, m)
	}

	trocar(t, ctl, "falha")
	r, _ = http.Get(alvo.URL + "/health")
	m, _ = ler(t, r)
	if r.StatusCode != 503 || m["mode"] != "falha" {
		t.Fatalf("modo falha: %d %v", r.StatusCode, m)
	}

	trocar(t, ctl, "lento")
	t0 := time.Now()
	r, _ = http.Get(alvo.URL + "/health")
	ler(t, r)
	if d := time.Since(t0); d < 140*time.Millisecond || r.StatusCode != 200 {
		t.Fatalf("modo lento: %d em %v (devia demorar ~150 ms e responder 200)", r.StatusCode, d)
	}

	trocar(t, ctl, "ok")
	r, _ = http.Get(alvo.URL + "/health")
	ler(t, r)
	if r.StatusCode != 200 {
		t.Fatal("voltou ao modo ok")
	}
}

func TestControleLeETrocaEDiz_oAnterior(t *testing.T) {
	_, ctl, a := novo(t)
	r, _ := http.Get(ctl.URL + "/modo")
	m, _ := ler(t, r)
	if m["mode"] != "ok" {
		t.Fatalf("%v", m)
	}
	r = trocar(t, ctl, "falha")
	m, _ = ler(t, r)
	if m["mode"] != "falha" || m["anterior"] != "ok" || a.Modo() != "falha" {
		t.Fatalf("%v modo=%s", m, a.Modo())
	}
}

func TestModoInvalidoNaoMudaNada(t *testing.T) {
	_, ctl, a := novo(t)
	trocar(t, ctl, "falha")
	for _, ruim := range []string{"", "OK", "derrubar", "ok/../falha"} {
		r := trocar(t, ctl, ruim)
		r.Body.Close()
		if r.StatusCode == 200 {
			t.Errorf("modo %q devia ser recusado", ruim)
		}
	}
	if a.Modo() != "falha" {
		t.Fatalf("um modo inválido mudou o estado: %s", a.Modo())
	}
}

func TestSoPostTrocaOModo(t *testing.T) {
	_, ctl, a := novo(t)
	for _, m := range []string{http.MethodGet, http.MethodPut, http.MethodDelete} {
		req, _ := http.NewRequest(m, ctl.URL+"/modo/falha", nil)
		r, err := http.DefaultClient.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		r.Body.Close()
		if r.StatusCode == 200 {
			t.Errorf("%s /modo/falha não pode trocar o modo", m)
		}
	}
	if a.Modo() != "ok" {
		t.Fatal("o modo mudou por um método que não é POST")
	}
}

func TestSoEscutaEmLoopback(t *testing.T) {
	for _, bom := range []string{"127.0.0.1:18080", "[::1]:18080", "127.0.0.2:1"} {
		if err := SoLoopback(bom); err != nil {
			t.Errorf("%s devia servir: %v", bom, err)
		}
	}
	for _, ruim := range []string{"0.0.0.0:18080", ":18080", "192.168.1.5:18080", "10.0.0.1:1", "localhost:18080", "[::]:18080", "semporta"} {
		if err := SoLoopback(ruim); err == nil {
			t.Errorf("%s devia ser recusado: um alvo de laboratório não escuta em interface alcançável", ruim)
		}
	}
	if executar([]string{"-listen", "0.0.0.0:18080"}) != 2 {
		t.Error("o programa devia recusar subir em 0.0.0.0")
	}
	if executar([]string{"-control", "0.0.0.0:18081"}) != 2 {
		t.Error("o controle também só pode ser loopback")
	}
}

func TestCorridaDeTrocasENaoCorrompe(t *testing.T) {
	alvo, ctl, _ := novo(t)
	var wg sync.WaitGroup
	for i := 0; i < 20; i++ {
		wg.Add(2)
		go func(i int) {
			defer wg.Done()
			r := trocar(t, ctl, []string{"ok", "falha"}[i%2])
			r.Body.Close()
		}(i)
		go func() {
			defer wg.Done()
			r, err := http.Get(alvo.URL + "/health")
			if err == nil {
				if r.StatusCode != 200 && r.StatusCode != 503 {
					t.Errorf("status inesperado %d", r.StatusCode)
				}
				r.Body.Close()
			}
		}()
	}
	wg.Wait()
}

func TestLogSeIdentificaComoLab(t *testing.T) {
	var sb strings.Builder
	var mu sync.Mutex
	l := log.New(writer(func(p []byte) { mu.Lock(); sb.Write(p); mu.Unlock() }), "", 0)
	a := NovoAlvo(0, l)
	alvo := httptest.NewServer(a.TratadorAlvo())
	defer alvo.Close()
	ctl := httptest.NewServer(a.TratadorControle())
	defer ctl.Close()

	r, _ := http.Get(alvo.URL + "/health")
	r.Body.Close()
	trocar(t, ctl, "falha").Body.Close()
	r, _ = http.Get(alvo.URL + "/health")
	r.Body.Close()

	mu.Lock()
	saida := sb.String()
	mu.Unlock()
	linhas := strings.Split(strings.TrimSpace(saida), "\n")
	if len(linhas) != 3 {
		t.Fatalf("esperava 3 linhas de log: %q", saida)
	}
	for _, linha := range linhas {
		if !strings.HasPrefix(linha, "[lab-target]") {
			t.Errorf("toda linha de log começa com [lab-target]: %q", linha)
		}
	}
	if !strings.Contains(linhas[0], "-> 200 (modo=ok)") || !strings.Contains(linhas[1], "modo ok -> falha") || !strings.Contains(linhas[2], "-> 503 (modo=falha)") {
		t.Errorf("conteúdo do log: %q", saida)
	}
}

type writer func([]byte)

func (w writer) Write(p []byte) (int, error) { w(p); return len(p), nil }

func TestSoOHealthEMedido(t *testing.T) {
	alvo, ctl, _ := novo(t)
	trocar(t, ctl, "falha").Body.Close()
	for _, caminho := range []string{"/", "/qualquer", "/healthz", "/health/x"} {
		r, err := http.Get(alvo.URL + caminho)
		if err != nil {
			t.Fatal(err)
		}
		r.Body.Close()
		if r.StatusCode != http.StatusNotFound {
			t.Errorf("%s devia ser 404 (só /health é o alvo), veio %d", caminho, r.StatusCode)
		}
	}
}
