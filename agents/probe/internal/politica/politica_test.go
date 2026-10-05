package politica

import (
	"encoding/json"
	"net"
	"strings"
	"testing"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/bundle"
)

const lab = "../../politica/lab.json"

func carregar(t *testing.T) *Politica {
	t.Helper()
	p, err := Carregar(lab)
	if err != nil {
		t.Fatal(err)
	}
	return p
}

func assignment(url string, intervalo int, kind string) bundle.Assignment {
	alvo, _ := json.Marshal(map[string]any{"url": url, "method": "GET", "allowed_status_codes": []int{200}, "follow_redirects": false})
	a := bundle.Assignment{AssignmentID: "a", CheckID: "c", Kind: kind, IPFamily: "ipv4", ConfigGeneration: 1, PolicyRevision: "p", Target: alvo}
	a.Schedule.IntervaloS = intervalo
	a.Schedule.TimeoutMS = 1000
	return a
}

func TestArquivoDeLaboratorioCarrega(t *testing.T) {
	p := carregar(t)
	if p.Revision != "local-lab-v1" || len(p.Hash()) != 64 {
		t.Fatalf("política: %+v hash=%q", p, p.Hash())
	}
}

func TestHashMudaComOConteudo(t *testing.T) {
	a, _ := Ler([]byte(`{"revision":"r","allowed_kinds":["http"],"min_interval_s":10,"max_assignments":5}`))
	b, _ := Ler([]byte(`{"revision":"r","allowed_kinds":["http"],"min_interval_s":11,"max_assignments":5}`))
	if a.Hash() == b.Hash() {
		t.Fatal("conteúdos diferentes, mesmo hash")
	}
}

func TestAutorizaSoOQueAPoliticaCobre(t *testing.T) {
	p := carregar(t)
	casos := []struct {
		nome string
		a    bundle.Assignment
		ok   bool
	}{
		{"destino e porta permitidos", assignment("https://portal.monitor.example/health", 30, "http"), true},
		{"porta padrão do http", assignment("http://portal.monitor.example/", 30, "http"), true},
		{"curinga de subdomínio", assignment("https://app.lab.monitor.example:8080/", 30, "http"), true},
		{"destino fora da lista", assignment("https://evil.example/", 30, "http"), false},
		{"porta fora da lista", assignment("https://portal.monitor.example:22/", 30, "http"), false},
		{"o curinga não casa o próprio sufixo", assignment("https://lab.monitor.example/", 30, "http"), false},
		{"host que é só o sufixo, com o ponto, não casa o curinga", assignment("https://.lab.monitor.example/", 30, "http"), false},
		{"sufixo parecido não casa", assignment("https://x.notlab.monitor.example/", 30, "http"), false},
		{"intervalo abaixo do mínimo local", assignment("https://portal.monitor.example/", 5, "http"), false},
		{"espécie não permitida", assignment("https://portal.monitor.example/", 30, "tcp"), false},
		{"host em maiúsculas é o mesmo host", assignment("https://PORTAL.monitor.example/", 30, "http"), true},
	}
	for _, c := range casos {
		err := p.Autoriza(c.a)
		if c.ok && err != nil {
			t.Errorf("%s: devia ser permitido: %v", c.nome, err)
		}
		if !c.ok && err == nil {
			t.Errorf("%s: devia ser NEGADO", c.nome)
		}
	}
}

func TestAutorizaIPPorCIDR(t *testing.T) {
	p := carregar(t)
	if err := p.AutorizaIP(net.ParseIP("203.0.113.10")); err != nil {
		t.Fatalf("203.0.113.10 devia passar: %v", err)
	}
	for _, ip := range []string{"10.0.0.1", "169.254.169.254", "192.168.1.1", "8.8.8.8", "::1"} {
		if err := p.AutorizaIP(net.ParseIP(ip)); err == nil {
			t.Errorf("%s devia ser negado", ip)
		}
	}
}

func TestPoliticaMalFormadaNaoCarrega(t *testing.T) {
	ruins := map[string]string{
		"campo desconhecido": `{"revision":"r","allowed_kinds":["http"],"min_interval_s":1,"max_assignments":1,"permit_all":true}`,
		"sem revisão":        `{"allowed_kinds":["http"],"min_interval_s":1,"max_assignments":1}`,
		"nada permitido":     `{"revision":"r","allowed_kinds":[],"min_interval_s":1,"max_assignments":1}`,
		"CIDR inválido":      `{"revision":"r","allowed_kinds":["http"],"min_interval_s":1,"max_assignments":1,"allowed_cidrs":["10.0.0.0/99"]}`,
		"porta inválida":     `{"revision":"r","allowed_kinds":["http"],"min_interval_s":1,"max_assignments":1,"allowed_destinations":[{"host":"x","ports":[70000]}]}`,
		"destino sem porta":  `{"revision":"r","allowed_kinds":["http"],"min_interval_s":1,"max_assignments":1,"allowed_destinations":[{"host":"x","ports":[]}]}`,
		"CIDR que é tudo":    `{"revision":"r","allowed_kinds":["http"],"min_interval_s":1,"max_assignments":1,"allowed_cidrs":["0.0.0.0/0"]}`,
		"CIDR largo demais":  `{"revision":"r","allowed_kinds":["http"],"min_interval_s":1,"max_assignments":1,"allowed_cidrs":["10.0.0.0/7"]}`,
		"IPv6 que é tudo":    `{"revision":"r","allowed_kinds":["http"],"min_interval_s":1,"max_assignments":1,"allowed_cidrs":["::/0"]}`,
		"intervalo zero":     `{"revision":"r","allowed_kinds":["http"],"min_interval_s":0,"max_assignments":1}`,
	}
	for nome, conteudo := range ruins {
		if _, err := Ler([]byte(conteudo)); err == nil {
			t.Errorf("%s: devia falhar", nome)
		} else if strings.TrimSpace(err.Error()) == "" {
			t.Errorf("%s: erro sem mensagem", nome)
		}
	}
}
