package executor

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/bundle"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/coordenador"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/politica"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/relogio"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/resultado"
)

// ---- apoio ----

// politicaCom monta uma política de teste: destinos {host, portas} e CIDRs.
func politicaCom(t *testing.T, destinos []map[string]any, cidrs ...string) *politica.Politica {
	t.Helper()
	b, _ := json.Marshal(map[string]any{
		"revision": "teste", "allowed_kinds": []string{"http"}, "min_interval_s": 1, "max_assignments": 10,
		"allowed_destinations": destinos, "allowed_cidrs": cidrs,
	})
	p, err := politica.Ler(b)
	if err != nil {
		t.Fatal(err)
	}
	return p
}

func destino(host string, portas ...int) map[string]any {
	return map[string]any{"host": host, "ports": portas}
}

// resolverFalso devolve uma SEQUÊNCIA de respostas por nome: a 1ª consulta devolve a 1ª, a 2ª a 2ª, e a última se repete.
// É assim que se simula o rebinding (T-052): o nome muda de resposta entre uma consulta e outra.
type resolverFalso struct {
	mu        sync.Mutex
	respostas map[string][][]string
	chamadas  map[string]int
}

func novoResolver(r map[string][][]string) *resolverFalso {
	return &resolverFalso{respostas: r, chamadas: map[string]int{}}
}

func (r *resolverFalso) LookupIP(_ context.Context, host string) ([]net.IP, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	seq, ok := r.respostas[host]
	if !ok {
		return nil, &net.DNSError{Err: "no such host", Name: host, IsNotFound: true}
	}
	i := r.chamadas[host]
	r.chamadas[host]++
	if i >= len(seq) {
		i = len(seq) - 1
	}
	var out []net.IP
	for _, s := range seq[i] {
		out = append(out, net.ParseIP(s))
	}
	return out, nil
}

func (r *resolverFalso) Consultas(host string) int {
	r.mu.Lock()
	defer r.mu.Unlock()
	return r.chamadas[host]
}

// servidor sobe um HTTP em `ip:porta` (porta 0 = qualquer) e conta as requisições que RECEBEU.
func servidor(t *testing.T, ip string, porta int, h http.HandlerFunc) (int, *atomic.Int64) {
	t.Helper()
	var hits atomic.Int64
	l, err := net.Listen("tcp", net.JoinHostPort(ip, fmt.Sprint(porta)))
	if err != nil {
		t.Skipf("não foi possível escutar em %s: %v", ip, err)
	}
	s := &httptest.Server{Listener: l, Config: &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits.Add(1)
		h(w, r)
	})}}
	s.Start()
	t.Cleanup(s.Close)
	return l.Addr().(*net.TCPAddr).Port, &hits
}

func ok200(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(200) }

// rastreio observa as conexões que o executor TENTOU abrir.
type rastreio struct {
	mu    sync.Mutex
	redes []string
	alvos []string
}

func (r *rastreio) discar(ctx context.Context, rede, endereco string) (net.Conn, error) {
	r.mu.Lock()
	r.redes = append(r.redes, rede)
	r.alvos = append(r.alvos, endereco)
	r.mu.Unlock()
	var d net.Dialer
	return d.DialContext(ctx, rede, endereco)
}

func (r *rastreio) N() int { r.mu.Lock(); defer r.mu.Unlock(); return len(r.alvos) }

func executar(t *testing.T, h *HTTP, url, familia string, seguir bool, aceitos ...int) coordenador.Saida {
	t.Helper()
	if len(aceitos) == 0 {
		aceitos = []int{200}
	}
	if h.Relogio == nil {
		h.Relogio = relogio.Sistema{}
	}
	ctx, cancela := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancela()
	return h.Executar(ctx, coordenador.Execucao{
		Assignment: bundle.Assignment{IPFamily: familia},
		Alvo:       bundle.AlvoHTTP{URL: url, Method: "GET", AllowedStatusCodes: aceitos, FollowRedirects: seguir},
		Timeout:    5 * time.Second,
	})
}

func exigir(t *testing.T, s coordenador.Saida, outcome, razao string) {
	t.Helper()
	if s.Outcome != outcome || s.Reason != razao {
		t.Fatalf("outcome=%s reason=%s, esperava %s/%s", s.Outcome, s.Reason, outcome, razao)
	}
}

// ---- o caso LEGÍTIMO ----

func TestCasoLegitimoPassaEReportaODestinoEfetivo(t *testing.T) {
	porta, hits := servidor(t, "127.0.0.1", 0, ok200)
	rt := &rastreio{}
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("127.0.0.1", porta)}, "127.0.0.1/32"), Discador: rt.discar}

	s := executar(t, h, fmt.Sprintf("http://127.0.0.1:%d/health", porta), "ipv4", false)

	exigir(t, s, resultado.Pass, resultado.RazaoSucessoHTTP)
	if s.HTTPStatus == nil || *s.HTTPStatus != 200 {
		t.Fatalf("status: %v", s.HTTPStatus)
	}
	if s.Destino == nil || s.Destino.IP != "127.0.0.1" || s.Destino.Port != porta || s.Destino.Hostname != nil {
		t.Fatalf("o destino efetivo é o IP e a porta da CONEXÃO (e sem hostname para IP literal): %+v", s.Destino)
	}
	if hits.Load() != 1 {
		t.Fatalf("o alvo recebeu %d requisições", hits.Load())
	}
	if len(rt.redes) != 1 || rt.redes[0] != "tcp4" {
		t.Fatalf("IPv4 explícito, sem fallback de família: a rede discada foi %v", rt.redes)
	}
}

func TestNomeResolvidoPassaEOHostnameFicaNoDestino(t *testing.T) {
	porta, _ := servidor(t, "127.0.0.1", 0, ok200)
	res := novoResolver(map[string][][]string{"alvo.example": {{"127.0.0.1"}}})
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("alvo.example", porta)}, "127.0.0.1/32"), Resolver: res}

	s := executar(t, h, fmt.Sprintf("http://alvo.example:%d/", porta), "ipv4", false)

	exigir(t, s, resultado.Pass, resultado.RazaoSucessoHTTP)
	if s.Destino == nil || s.Destino.IP != "127.0.0.1" || s.Destino.Hostname == nil || *s.Destino.Hostname != "alvo.example" {
		t.Fatalf("destino: %+v", s.Destino)
	}
}

func TestStatusInesperadoEAceitos(t *testing.T) {
	porta, _ := servidor(t, "127.0.0.1", 0, func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(503) })
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("127.0.0.1", porta)}, "127.0.0.1/32")}
	u := fmt.Sprintf("http://127.0.0.1:%d/", porta)

	s := executar(t, h, u, "ipv4", false)
	exigir(t, s, resultado.Fail, resultado.RazaoStatusInesperado)
	if s.HTTPStatus == nil || *s.HTTPStatus != 503 {
		t.Fatalf("status: %v", s.HTTPStatus)
	}
	exigir(t, executar(t, h, u, "ipv4", false, 200, 503), resultado.Pass, resultado.RazaoSucessoHTTP)
}

func TestHEADFunciona(t *testing.T) {
	var metodo atomic.Value
	porta, _ := servidor(t, "127.0.0.1", 0, func(w http.ResponseWriter, r *http.Request) { metodo.Store(r.Method) })
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("127.0.0.1", porta)}, "127.0.0.1/32"), Relogio: relogio.Sistema{}}
	s := h.Executar(context.Background(), coordenador.Execucao{
		Assignment: bundle.Assignment{IPFamily: "ipv4"},
		Alvo:       bundle.AlvoHTTP{URL: fmt.Sprintf("http://127.0.0.1:%d/", porta), Method: "HEAD", AllowedStatusCodes: []int{200}},
	})
	exigir(t, s, resultado.Pass, resultado.RazaoSucessoHTTP)
	if metodo.Load() != "HEAD" {
		t.Fatalf("método: %v", metodo.Load())
	}
}

// ---- DNS REBINDING (T-052) ----

// O nome muda de resposta entre a 1ª consulta e a 2ª: de um IP permitido para um IP de serviço interno. Uma implementação que
// VALIDA e depois RESOLVE DE NOVO para conectar cairia no interno. Aqui a resolução é uma só, dentro do dial.
func TestRebindingEntreValidacaoEConexaoNaoAcontece(t *testing.T) {
	porta, hitsA := servidor(t, "127.0.0.1", 0, ok200)
	_, hitsB := servidor(t, "127.0.0.2", porta, func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte("SERVIÇO INTERNO"))
	})
	res := novoResolver(map[string][][]string{
		"alvo.example": {{"127.0.0.1"}, {"127.0.0.2"}}, // a 2ª consulta já aponta para o interno
	})
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("alvo.example", porta)}, "127.0.0.1/32"), Resolver: res}

	s := executar(t, h, fmt.Sprintf("http://alvo.example:%d/", porta), "ipv4", false)

	exigir(t, s, resultado.Pass, resultado.RazaoSucessoHTTP)
	if hitsB.Load() != 0 {
		t.Fatalf("REBINDING: o serviço interno recebeu %d requisição(ões)", hitsB.Load())
	}
	if hitsA.Load() != 1 || s.Destino.IP != "127.0.0.1" {
		t.Fatalf("a conexão tem de ir ao IP VALIDADO: hitsA=%d destino=%+v", hitsA.Load(), s.Destino)
	}
	if n := res.Consultas("alvo.example"); n != 1 {
		t.Fatalf("o nome foi resolvido %d vezes; a resolução tem de ser UMA, dentro do dial", n)
	}
}

func TestPrimeiraRespostaProibidaEhNegadaMesmoSeASegundaPrestava(t *testing.T) {
	porta, hitsA := servidor(t, "127.0.0.1", 0, ok200)
	_, hitsB := servidor(t, "127.0.0.2", porta, ok200)
	res := novoResolver(map[string][][]string{"alvo.example": {{"127.0.0.2"}, {"127.0.0.1"}}})
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("alvo.example", porta)}, "127.0.0.1/32"), Resolver: res}

	s := executar(t, h, fmt.Sprintf("http://alvo.example:%d/", porta), "ipv4", false)

	exigir(t, s, resultado.PolicyDenied, resultado.RazaoPoliticaNegou)
	if hitsA.Load()+hitsB.Load() != 0 {
		t.Fatal("negado: nenhuma conexão pode ter sido feita")
	}
	if s.Destino != nil {
		t.Fatalf("sem conexão não há destino efetivo: %+v", s.Destino)
	}
}

// Round-robin malicioso: a MESMA resposta traz um IP bom e um proibido. Escolher "o bom" deixa a decisão ao atacante.
func TestRespostaMistaComIPProibidoEhNegadaInteira(t *testing.T) {
	porta, hitsA := servidor(t, "127.0.0.1", 0, ok200)
	_, hitsB := servidor(t, "127.0.0.2", porta, ok200)
	res := novoResolver(map[string][][]string{"alvo.example": {{"127.0.0.1", "127.0.0.2"}}})
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("alvo.example", porta)}, "127.0.0.1/32"), Resolver: res}

	for i := 0; i < 20; i++ { // a ordem pode variar: nenhuma vez pode conectar
		s := executar(t, h, fmt.Sprintf("http://alvo.example:%d/", porta), "ipv4", false)
		exigir(t, s, resultado.PolicyDenied, resultado.RazaoPoliticaNegou)
	}
	if hitsA.Load()+hitsB.Load() != 0 {
		t.Fatal("uma resposta com IP proibido não pode conectar a NENHUM dos endereços")
	}
}

// ---- IPs que nunca devem ser alvo ----

func TestIPsProibidosResolvidosPorUmNomePermitidoSaoNegadosSemConectar(t *testing.T) {
	proibidos := []string{
		"10.0.0.5", "10.255.255.254", "172.16.9.9", "172.31.0.1", "192.168.1.1", // RFC 1918
		"100.64.0.1",             // CGNAT
		"127.0.0.1", "127.1.2.3", // loopback (a política só permite o que está nos CIDRs)
		"169.254.169.254", "169.254.0.1", // link-local e o metadado de nuvem
		"0.0.0.0", "0.1.2.3", // não especificado e 0.0.0.0/8
		"224.0.0.1", "239.255.255.250", // multicast
		"255.255.255.255",                     // broadcast
		"::ffff:127.0.0.1", "::ffff:10.0.0.1", // IPv4 mapeado em IPv6: é o IPv4 de dentro
	}
	for _, ip := range proibidos {
		rt := &rastreio{}
		res := novoResolver(map[string][][]string{"interno.example": {{ip}}})
		// A política só permite 203.0.113.0/24: nada do que está acima.
		h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("interno.example", 80)}, "203.0.113.0/24"), Resolver: res, Discador: rt.discar}

		s := executar(t, h, "http://interno.example/", "ipv4", false)

		exigir(t, s, resultado.PolicyDenied, resultado.RazaoPoliticaNegou)
		if rt.N() != 0 {
			t.Errorf("%s: o executor chegou a tentar conectar (%v)", ip, rt.alvos)
		}
	}
}

// O piso vale mesmo que a política o cubra POR ENGANO: metadado de nuvem nunca é alvo de monitoramento.
func TestPisoProibidoVenceUmaPoliticaQueCobreLinkLocal(t *testing.T) {
	rt := &rastreio{}
	res := novoResolver(map[string][][]string{"meta.example": {{"169.254.169.254"}}})
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("meta.example", 80)}, "169.254.0.0/16"), Resolver: res, Discador: rt.discar}

	s := executar(t, h, "http://meta.example/latest/meta-data/", "ipv4", false)

	exigir(t, s, resultado.PolicyDenied, resultado.RazaoPoliticaNegou)
	if rt.N() != 0 {
		t.Fatal("o metadado de nuvem não pode ser discado, nem com a política cobrindo")
	}
}

func TestPisoProibidoUnitario(t *testing.T) {
	for _, ip := range []string{"0.0.0.0", "0.9.9.9", "224.0.0.1", "169.254.169.254", "255.255.255.255", "fe80::1", "ff02::1", "::"} {
		if PisoProibido(net.ParseIP(ip)) == nil {
			t.Errorf("%s devia estar no piso proibido", ip)
		}
	}
	for _, ip := range []string{"127.0.0.1", "10.0.0.1", "203.0.113.10", "8.8.8.8", "2001:db8::1"} {
		if err := PisoProibido(net.ParseIP(ip)); err != nil {
			t.Errorf("%s não é do piso (quem decide é a política): %v", ip, err)
		}
	}
}

// ---- Host e porta fora da política, antes de qualquer DNS ----

func TestFormasEstranhasDeEnderecarOMesmoHostSaoNegadas(t *testing.T) {
	porta, hits := servidor(t, "127.0.0.1", 0, ok200)
	rt := &rastreio{}
	res := novoResolver(map[string][][]string{}) // qualquer consulta de nome falharia: o que importa é nem chegar lá
	// A política só conhece "alvo.example" na porta 80 e o CIDR 127.0.0.1/32.
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("alvo.example", 80)}, "127.0.0.1/32"), Resolver: res, Discador: rt.discar}

	urls := []string{
		fmt.Sprintf("http://127.0.0.1:%d/", porta),              // IP literal que não está na lista de hosts
		fmt.Sprintf("http://2130706433:%d/", porta),             // 127.0.0.1 em decimal
		fmt.Sprintf("http://0x7f000001:%d/", porta),             // em hexadecimal
		fmt.Sprintf("http://017700000001:%d/", porta),           // em octal
		fmt.Sprintf("http://127.1:%d/", porta),                  // abreviado
		fmt.Sprintf("http://[::1]:%d/", porta),                  // IPv6 loopback
		fmt.Sprintf("http://[::ffff:127.0.0.1]:%d/", porta),     // IPv4 mapeado
		fmt.Sprintf("http://localhost:%d/", porta),              // nome local
		fmt.Sprintf("http://alvo.example@127.0.0.1:%d/", porta), // credencial na URL: o host de verdade é o depois do @
		"http://alvo.example.evil.com/",                         // sufixo parecido
		"http://evilalvo.example/",                              // prefixo parecido
		"http://alvo.example:22/",                               // porta fora da lista
		"http://alvo.example:8080/",
	}
	for _, u := range urls {
		s := executar(t, h, u, "ipv4", false)
		exigir(t, s, resultado.PolicyDenied, resultado.RazaoPoliticaNegou)
	}
	if rt.N() != 0 || hits.Load() != 0 {
		t.Fatalf("nada podia ser conectado: dials=%d hits=%d", rt.N(), hits.Load())
	}
}

// ---- Redirecionamento ----

func TestRedirectParaDestinoNaoPermitidoEhNegadoESemConexao(t *testing.T) {
	var portaB int
	portaA, _ := servidor(t, "127.0.0.1", 0, func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, fmt.Sprintf("http://127.0.0.2:%d/segredo", portaB), http.StatusFound)
	})
	portaB, hitsB := servidor(t, "127.0.0.2", 0, ok200)
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("127.0.0.1", portaA)}, "127.0.0.1/32")}

	s := executar(t, h, fmt.Sprintf("http://127.0.0.1:%d/", portaA), "ipv4", true)

	exigir(t, s, resultado.PolicyDenied, resultado.RazaoPoliticaNegou)
	if hitsB.Load() != 0 {
		t.Fatalf("o redirect levou a uma conexão ao destino proibido (%d)", hitsB.Load())
	}
}

func TestRedirectParaOutraPortaDoMesmoHostEhNegado(t *testing.T) {
	var portaB int
	portaA, _ := servidor(t, "127.0.0.1", 0, func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, fmt.Sprintf("http://127.0.0.1:%d/", portaB), http.StatusFound)
	})
	portaB, hitsB := servidor(t, "127.0.0.1", 0, ok200)
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("127.0.0.1", portaA)}, "127.0.0.1/32")}

	s := executar(t, h, fmt.Sprintf("http://127.0.0.1:%d/", portaA), "ipv4", true)

	exigir(t, s, resultado.PolicyDenied, resultado.RazaoPoliticaNegou)
	if hitsB.Load() != 0 {
		t.Fatal("o redirect para uma porta fora da lista foi seguido")
	}
}

func TestRedirectNaoSeguidoEhOStatus(t *testing.T) {
	var portaB int
	portaA, _ := servidor(t, "127.0.0.1", 0, func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, fmt.Sprintf("http://127.0.0.2:%d/", portaB), http.StatusFound)
	})
	portaB, hitsB := servidor(t, "127.0.0.2", 0, ok200)
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("127.0.0.1", portaA)}, "127.0.0.1/32")}

	s := executar(t, h, fmt.Sprintf("http://127.0.0.1:%d/", portaA), "ipv4", false)

	exigir(t, s, resultado.Fail, resultado.RazaoStatusInesperado)
	if s.HTTPStatus == nil || *s.HTTPStatus != 302 || hitsB.Load() != 0 {
		t.Fatalf("com follow_redirects=false o 302 é a resposta: status=%v hitsB=%d", s.HTTPStatus, hitsB.Load())
	}
}

func TestRedirectPermitidoNoMesmoDestinoEhSeguido(t *testing.T) {
	porta, hits := servidor(t, "127.0.0.1", 0, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/velho" {
			http.Redirect(w, r, "/novo", http.StatusMovedPermanently)
			return
		}
		w.WriteHeader(200)
	})
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("127.0.0.1", porta)}, "127.0.0.1/32")}

	s := executar(t, h, fmt.Sprintf("http://127.0.0.1:%d/velho", porta), "ipv4", true)

	exigir(t, s, resultado.Pass, resultado.RazaoSucessoHTTP)
	if hits.Load() != 2 {
		t.Fatalf("esperava 2 requisições (a original e o redirect): %d", hits.Load())
	}
}

func TestLacoDeRedirectEhLimitado(t *testing.T) {
	porta, hits := servidor(t, "127.0.0.1", 0, func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, r.URL.Path+"x", http.StatusFound)
	})
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("127.0.0.1", porta)}, "127.0.0.1/32")}

	s := executar(t, h, fmt.Sprintf("http://127.0.0.1:%d/a", porta), "ipv4", true)

	exigir(t, s, resultado.PolicyDenied, resultado.RazaoPoliticaNegou)
	if hits.Load() > int64(maxRedirecionamentos)+1 {
		t.Fatalf("seguiu %d redirecionamentos (teto %d)", hits.Load(), maxRedirecionamentos)
	}
}

func TestRedirectDeHTTPSParaHTTPEhNegado(t *testing.T) {
	porta, hitsHTTP := servidor(t, "127.0.0.1", 0, ok200)
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, fmt.Sprintf("http://example.com:%d/", porta), http.StatusFound)
	}))
	defer srv.Close()
	_, portaTLSTxt, _ := net.SplitHostPort(srv.Listener.Addr().String())
	var portaTLS int
	fmt.Sscan(portaTLSTxt, &portaTLS)
	pool := x509.NewCertPool()
	pool.AddCert(srv.Certificate())
	res := novoResolver(map[string][][]string{"example.com": {{"127.0.0.1"}}})
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("example.com", portaTLS, porta)}, "127.0.0.1/32"), Resolver: res, RaizesTLS: pool}

	s := executar(t, h, fmt.Sprintf("https://example.com:%d/", portaTLS), "ipv4", true)

	exigir(t, s, resultado.PolicyDenied, resultado.RazaoPoliticaNegou)
	if hitsHTTP.Load() != 0 {
		t.Fatal("o redirect rebaixou de https para http")
	}
}

// ---- Família IP: sem fallback ----

func TestSemFallbackDeFamilia(t *testing.T) {
	rt := &rastreio{}
	// O nome só tem AAAA; o assignment é IPv4. NÃO se cai para IPv6.
	res := novoResolver(map[string][][]string{"so6.example": {{"2001:db8::1"}}})
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("so6.example", 80)}, "203.0.113.0/24", "2001:db8::/32"), Resolver: res, Discador: rt.discar}

	s := executar(t, h, "http://so6.example/", "ipv4", false)

	exigir(t, s, resultado.Fail, resultado.RazaoFamiliaIndisp)
	if rt.N() != 0 {
		t.Fatalf("houve fallback de família: %v", rt.alvos)
	}
	if s.Destino != nil {
		t.Fatalf("sem conexão, sem destino: %+v", s.Destino)
	}

	// E o inverso: assignment IPv6 e nome só com A.
	res2 := novoResolver(map[string][][]string{"so4.example": {{"203.0.113.9"}}})
	h2 := &HTTP{Politica: politicaCom(t, []map[string]any{destino("so4.example", 80)}, "203.0.113.0/24", "2001:db8::/32"), Resolver: res2, Discador: rt.discar}
	exigir(t, executar(t, h2, "http://so4.example/", "ipv6", false), resultado.Fail, resultado.RazaoFamiliaIndisp)
	if rt.N() != 0 {
		t.Fatalf("houve fallback de família (IPv6 → IPv4): %v", rt.alvos)
	}
}

// ---- Proxy do ambiente não desvia ----

func TestProxyDoAmbienteNaoDesviaAConexao(t *testing.T) {
	portaProxy, hitsProxy := servidor(t, "127.0.0.1", 0, ok200)
	porta, hitsAlvo := servidor(t, "127.0.0.1", 0, ok200)
	// O Go NÃO usa proxy para loopback, então o alvo é um NOME (que a resolução falsa leva a 127.0.0.1): com `ProxyFromEnvironment` a
	// requisição iria ao proxy, e aqui não pode ir.
	for _, v := range []string{"HTTP_PROXY", "http_proxy", "HTTPS_PROXY", "https_proxy"} {
		t.Setenv(v, fmt.Sprintf("http://127.0.0.1:%d", portaProxy))
	}
	res := novoResolver(map[string][][]string{"alvo.example": {{"127.0.0.1"}}})
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("alvo.example", porta)}, "127.0.0.1/32"), Resolver: res}

	s := executar(t, h, fmt.Sprintf("http://alvo.example:%d/", porta), "ipv4", false)

	exigir(t, s, resultado.Pass, resultado.RazaoSucessoHTTP)
	if hitsAlvo.Load() != 1 || hitsProxy.Load() != 0 {
		t.Fatalf("alvo=%d proxy=%d: o proxy do ambiente desviou a conexão", hitsAlvo.Load(), hitsProxy.Load())
	}
}

// ---- Falhas do alvo, classificadas ----

func TestTimeoutDoAlvo(t *testing.T) {
	porta, _ := servidor(t, "127.0.0.1", 0, func(w http.ResponseWriter, r *http.Request) { time.Sleep(2 * time.Second) })
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("127.0.0.1", porta)}, "127.0.0.1/32"), Relogio: relogio.Sistema{}}
	ctx, cancela := context.WithTimeout(context.Background(), 200*time.Millisecond)
	defer cancela()

	s := h.Executar(ctx, coordenador.Execucao{
		Assignment: bundle.Assignment{IPFamily: "ipv4"},
		Alvo:       bundle.AlvoHTTP{URL: fmt.Sprintf("http://127.0.0.1:%d/", porta), Method: "GET", AllowedStatusCodes: []int{200}},
	})

	exigir(t, s, resultado.Fail, resultado.RazaoTimeoutDoAlvo)
}

func TestConexaoRecusada(t *testing.T) {
	l, _ := net.Listen("tcp", "127.0.0.1:0")
	porta := l.Addr().(*net.TCPAddr).Port
	l.Close() // ninguém escuta mais
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("127.0.0.1", porta)}, "127.0.0.1/32")}

	s := executar(t, h, fmt.Sprintf("http://127.0.0.1:%d/", porta), "ipv4", false)

	exigir(t, s, resultado.Fail, resultado.RazaoConexaoRecusada)
	if s.Destino != nil {
		t.Fatalf("a conexão não se completou: sem destino efetivo")
	}
}

func TestNomeQueNaoResolveEhFalhaDeDNS(t *testing.T) {
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("naoexiste.example", 80)}, "203.0.113.0/24"), Resolver: novoResolver(map[string][][]string{})}
	exigir(t, executar(t, h, "http://naoexiste.example/", "ipv4", false), resultado.Fail, "dns_servfail")
}

// ---- TLS: SNI e verificação pelo NOME, mesmo conectando num IP fixado ----

func servidorTLS(t *testing.T) (porta int, sni *atomic.Value, pool *x509.CertPool) {
	t.Helper()
	sni = &atomic.Value{}
	srv := httptest.NewUnstartedServer(http.HandlerFunc(ok200))
	srv.TLS = &tls.Config{GetConfigForClient: func(h *tls.ClientHelloInfo) (*tls.Config, error) {
		sni.Store(h.ServerName)
		return nil, nil
	}}
	srv.StartTLS()
	t.Cleanup(srv.Close)
	_, p, _ := net.SplitHostPort(srv.Listener.Addr().String())
	fmt.Sscan(p, &porta)
	pool = x509.NewCertPool()
	pool.AddCert(srv.Certificate())
	return porta, sni, pool
}

func TestTLSUsaOHostnameNoSNIEVerificaOCertificadoPorEle(t *testing.T) {
	porta, sni, pool := servidorTLS(t)
	// O certificado do httptest vale para example.com e 127.0.0.1; o IP fixado é 127.0.0.1, o NOME é example.com.
	res := novoResolver(map[string][][]string{"example.com": {{"127.0.0.1"}}})
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("example.com", porta)}, "127.0.0.1/32"), Resolver: res, RaizesTLS: pool}

	s := executar(t, h, fmt.Sprintf("https://example.com:%d/", porta), "ipv4", false)

	exigir(t, s, resultado.Pass, resultado.RazaoSucessoHTTP)
	if sni.Load() != "example.com" {
		t.Fatalf("o SNI tem de ser o hostname, não o IP: %v", sni.Load())
	}
}

func TestCertificadoQueNaoCasaComOHostnameEhFalha(t *testing.T) {
	porta, _, pool := servidorTLS(t)
	res := novoResolver(map[string][][]string{"outro.example": {{"127.0.0.1"}}})
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("outro.example", porta)}, "127.0.0.1/32"), Resolver: res, RaizesTLS: pool}

	s := executar(t, h, fmt.Sprintf("https://outro.example:%d/", porta), "ipv4", false)

	exigir(t, s, resultado.Fail, "tls_certificate_invalid")
}

func TestCertificadoDeAutoridadeDesconhecidaEhFalha(t *testing.T) {
	porta, _, _ := servidorTLS(t)
	res := novoResolver(map[string][][]string{"example.com": {{"127.0.0.1"}}})
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("example.com", porta)}, "127.0.0.1/32"), Resolver: res} // sem RaizesTLS

	exigir(t, executar(t, h, fmt.Sprintf("https://example.com:%d/", porta), "ipv4", false), resultado.Fail, "tls_certificate_invalid")
}

func TestServidorQueNaoFalaTLSEhFalhaDeHandshake(t *testing.T) {
	porta, _ := servidor(t, "127.0.0.1", 0, ok200) // HTTP puro, e o alvo diz https
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("127.0.0.1", porta)}, "127.0.0.1/32")}

	s := executar(t, h, fmt.Sprintf("https://127.0.0.1:%d/", porta), "ipv4", false)

	exigir(t, s, resultado.Fail, "tls_handshake_failed")
}

// ---- O corpo não é lido sem limite ----

func TestCorpoInfinitoNaoPrendeAMedicao(t *testing.T) {
	porta, _ := servidor(t, "127.0.0.1", 0, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		chunk := []byte(strings.Repeat("x", 32<<10))
		for { // um corpo que nunca acaba: só termina quando o cliente desiste
			if _, err := w.Write(chunk); err != nil {
				return
			}
			if f, ok := w.(http.Flusher); ok {
				f.Flush()
			}
			time.Sleep(5 * time.Millisecond)
		}
	})
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("127.0.0.1", porta)}, "127.0.0.1/32")}
	inicio := time.Now()

	s := executar(t, h, fmt.Sprintf("http://127.0.0.1:%d/", porta), "ipv4", false)

	// Só o status importa: o corpo é lido até um teto e descartado. Sem o teto, a leitura ficaria presa até o tempo limite.
	exigir(t, s, resultado.Pass, resultado.RazaoSucessoHTTP)
	if d := time.Since(inicio); d > 2*time.Second {
		t.Fatalf("a medição ficou presa lendo o corpo (%v)", d)
	}
}

// ---- O piso proibido vale mesmo quando a política cobre a faixa ----

func TestPisoProibidoVenceUmaPoliticaQueCobreMulticastBroadcastENaoEspecificado(t *testing.T) {
	casos := map[string]string{
		"224.0.0.1": "224.0.0.0/8", "239.255.255.250": "239.0.0.0/8", "255.255.255.255": "255.0.0.0/8",
		"0.0.0.0": "0.0.0.0/8", "0.1.2.3": "0.0.0.0/8", "169.254.1.1": "169.254.0.0/16",
	}
	for ip, cidr := range casos {
		rt := &rastreio{}
		res := novoResolver(map[string][][]string{"x.example": {{ip}}})
		h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("x.example", 80)}, cidr), Resolver: res, Discador: rt.discar}
		exigir(t, executar(t, h, "http://x.example/", "ipv4", false), resultado.PolicyDenied, resultado.RazaoPoliticaNegou)
		if rt.N() != 0 {
			t.Errorf("%s (política cobrindo %s): tentou conectar", ip, cidr)
		}
	}
}

// ---- IPv6 de verdade ----

func TestIPv6ConectaPorTcp6QuandoOAssignmentEIPv6(t *testing.T) {
	porta, hits := servidor(t, "::1", 0, ok200)
	rt := &rastreio{}
	res := novoResolver(map[string][][]string{"alvo6.example": {{"::1"}}})
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("alvo6.example", porta)}, "::1/128"), Resolver: res, Discador: rt.discar}

	s := executar(t, h, fmt.Sprintf("http://alvo6.example:%d/", porta), "ipv6", false)

	exigir(t, s, resultado.Pass, resultado.RazaoSucessoHTTP)
	if hits.Load() != 1 || len(rt.redes) != 1 || rt.redes[0] != "tcp6" || s.Destino == nil || s.Destino.IP != "::1" {
		t.Fatalf("hits=%d redes=%v destino=%+v", hits.Load(), rt.redes, s.Destino)
	}
}

// O Go guarda a configuração de proxy do ambiente na PRIMEIRA vez que a lê e nunca mais a relê, então o teste de comportamento acima
// não enxerga uma variável definida depois. A garantia estrutural: o transporte não tem função de proxy nenhuma.
func TestTransporteNaoTemFuncaoDeProxy(t *testing.T) {
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("x.example", 80)}, "203.0.113.0/24")}
	var d *resultado.Destino
	tr, ok := h.cliente("ipv4", false, &d).Transport.(*http.Transport)
	if !ok {
		t.Fatal("o transporte não é um *http.Transport")
	}
	if tr.Proxy != nil {
		t.Fatal("o transporte tem função de proxy: HTTP_PROXY do ambiente poderia desviar a conexão validada")
	}
	if !tr.DisableKeepAlives {
		t.Fatal("conexões não podem ser reaproveitadas entre destinos")
	}
}

// A SEGUNDA barreira, no socket: nenhum teste de ponta a ponta a exerce sozinho (a primeira barreira decide antes dela), então ela é
// testada diretamente, e o fato de o discador real a USAR é conferido à parte.
func TestSegundaBarreiraNoSocket(t *testing.T) {
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("x.example", 80)}, "203.0.113.0/24")}

	for _, ok := range []string{"203.0.113.9:80"} {
		if err := h.controleDoSocket("tcp4", ok, nil); err != nil {
			t.Errorf("%s devia passar: %v", ok, err)
		}
	}
	for _, ruim := range []string{"127.0.0.1:80", "10.0.0.1:80", "169.254.169.254:80", "0.0.0.0:80", "224.0.0.1:80", "[::1]:80", "semporta", "nomequalquer:80"} {
		if err := h.controleDoSocket("tcp4", ruim, nil); err == nil {
			t.Errorf("%s devia ser negado no socket", ruim)
		}
	}

	d := h.novoDialer()
	if d.Control == nil {
		t.Fatal("o discador real tem de usar a segunda barreira")
	}
	if err := d.Control("tcp4", "127.0.0.1:80", nil); err == nil {
		t.Fatal("o Control do discador real não nega 127.0.0.1")
	}
}
