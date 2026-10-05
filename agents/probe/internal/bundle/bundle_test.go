package bundle

import (
	"encoding/json"
	"os"
	"strings"
	"testing"
	"time"
)

const dir = "../../../../contracts/fixtures/bundle/"

func abrir(t *testing.T, nome string) *Bundle {
	t.Helper()
	f, err := os.Open(dir + nome)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	b, err := Ler(f)
	if err != nil {
		t.Fatalf("%s devia ser válido: %v", nome, err)
	}
	return b
}

func TestFixtureValidaDoContratoELida(t *testing.T) {
	b := abrir(t, "valido-http.json")
	if b.Revision != 3 || len(b.Assignments) != 1 {
		t.Fatalf("lido errado: %+v", b)
	}
	a := b.Assignments[0]
	alvo, err := a.AlvoHTTP()
	if err != nil || alvo.URL != "https://portal.monitor.example/health" || alvo.Method != "GET" {
		t.Fatalf("alvo: %+v %v", alvo, err)
	}
	if a.Schedule.IntervaloS != 30 || a.Schedule.JitterOffsetMS != 2000 || a.Schedule.ToleranciaInicio != 6000 {
		t.Fatalf("cronograma: %+v", a.Schedule)
	}
}

// Cada fixture INVÁLIDA do contrato tem de ser recusada pela sonda, com o motivo certo. É o espelho, no Go, do que a central faz
// com o schema.
func TestFixturesInvalidasDoContratoSaoRecusadas(t *testing.T) {
	casos := map[string]string{
		"invalido-callback.json":       "unknown field",
		"invalido-geracao-zero.json":   "config_generation",
		"invalido-intervalo-zero.json": "interval_s",
		"invalido-metodo-post.json":    "method",
		"invalido-sem-expiracao.json":  "expires_at",
		"invalido-url-ftp.json":        "url",
	}
	for nome, trecho := range casos {
		f, err := os.Open(dir + nome)
		if err != nil {
			t.Fatal(err)
		}
		_, err = Ler(f)
		f.Close()
		if err == nil {
			t.Errorf("%s devia ser recusado", nome)
			continue
		}
		if !strings.Contains(err.Error(), trecho) {
			t.Errorf("%s recusado pelo motivo errado: %v (esperava citar %q)", nome, err, trecho)
		}
	}
}

func TestToleraSoOQueOContratoPermite(t *testing.T) {
	base, _ := os.ReadFile(dir + "valido-http.json")
	var m map[string]any
	_ = json.Unmarshal(base, &m)

	cenarios := map[string]func(map[string]any){
		"campo extra no topo":       func(m map[string]any) { m["policy_override"] = true },
		"campo extra no assignment": func(m map[string]any) { m["assignments"].([]any)[0].(map[string]any)["shell"] = "id" },
		"campo extra no alvo": func(m map[string]any) {
			m["assignments"].([]any)[0].(map[string]any)["target"].(map[string]any)["proxy"] = "x"
		},
		"kind ainda não suportado": func(m map[string]any) { m["assignments"].([]any)[0].(map[string]any)["kind"] = "tcp" },
		"família inválida":         func(m map[string]any) { m["assignments"].([]any)[0].(map[string]any)["ip_family"] = "ipv5" },
		"assignment repetido":      func(m map[string]any) { a := m["assignments"].([]any); m["assignments"] = append(a, a[0]) },
		"revisão zero":             func(m map[string]any) { m["bundle_revision"] = 0 },
	}
	for nome, mexe := range cenarios {
		var c map[string]any
		_ = json.Unmarshal(base, &c)
		mexe(c)
		b, _ := json.Marshal(c)
		if _, err := Ler(strings.NewReader(string(b))); err == nil {
			t.Errorf("%s: devia ser recusado", nome)
		}
	}
}

func TestExpiracao(t *testing.T) {
	b := abrir(t, "valido-http.json")
	if b.Expirado(time.Date(2026, 9, 29, 13, 59, 59, 0, time.UTC)) {
		t.Fatal("ainda vale")
	}
	if !b.Expirado(time.Date(2026, 9, 29, 14, 0, 0, 0, time.UTC)) {
		t.Fatal("no instante de expiração já não vale")
	}
}
