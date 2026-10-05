// Package executor é o executor HTTP NATIVO da sonda (D-02; C-05; C-06; 04 §4; 07). Ele mede um alvo HTTP e devolve o
// resultado normalizado. Não há Blackbox Exporter: o que mede é `net/http` com a conexão controlada daqui.
//
// A defesa é **estrutural**, não uma lista de casos. O ponto é que o IP que foi VALIDADO é o IP em que se CONECTA:
//
//   - a resolução de nomes acontece **dentro do `DialContext`**, uma vez por conexão, e o que foi resolvido ali é validado e
//     discado ali. Não existe um segundo `lookup` entre a validação e a conexão, então o **rebinding de DNS (T-052)** não
//     tem onde acontecer: um nome que muda de resposta na segunda consulta nunca é consultado uma segunda vez;
//   - o `Dialer` ainda confere o endereço no próprio socket (`Control`), com a mesma regra, como segunda barreira;
//   - cada **redirecionamento** abre uma conexão nova pelo mesmo caminho, então é validado do mesmo jeito (host, porta e IP);
//   - **IPv4 e IPv6 nunca se misturam**: a família do assignment decide a rede (`tcp4` ou `tcp6`) e as respostas DNS que
//     filtram a outra; sem fallback de família (INV-03);
//   - `Proxy` é nulo: variável de ambiente `HTTP_PROXY` não desvia a conexão para outro lugar.
//
// A ordem de decisão para um destino é: o host e a porta estão na política local? → todos os IPs da resposta (da família) estão
// nos CIDRs da política e fora do piso proibido? → só então conecta. Qualquer "não" é `policy_denied`, **sem conexão**.
package executor

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/coordenador"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/politica"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/relogio"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/resultado"
)

// Resolver resolve um nome. É uma interface para o teste poder simular o rebinding.
type Resolver interface {
	LookupIP(ctx context.Context, host string) ([]net.IP, error)
}

// ResolverSistema usa o resolvedor do sistema.
type ResolverSistema struct{}

// LookupIP devolve os endereços do nome.
func (ResolverSistema) LookupIP(ctx context.Context, host string) ([]net.IP, error) {
	addrs, err := net.DefaultResolver.LookupIPAddr(ctx, host)
	if err != nil {
		return nil, err
	}
	out := make([]net.IP, 0, len(addrs))
	for _, a := range addrs {
		out = append(out, a.IP)
	}
	return out, nil
}

// Limites de segurança fixos.
const (
	maxRedirecionamentos = 3
	maxCorpoLido         = 64 << 10
	maxCabecalhos        = 32 << 10
)

// HTTP é o executor HTTP nativo.
type HTTP struct {
	Politica *politica.Politica
	Resolver Resolver
	Relogio  relogio.Relogio
	// RaizesTLS substitui as raízes do sistema (testes e laboratório). Nulo usa as do sistema.
	RaizesTLS *x509.CertPool
	// Discador permite ao teste observar a rede e o endereço efetivamente discados; nulo usa o padrão.
	Discador func(ctx context.Context, rede, endereco string) (net.Conn, error)
}

var _ coordenador.Executor = (*HTTP)(nil)

// negado é o erro que marca "a política não permite": vira `policy_denied`, e nunca houve conexão.
type negado struct{ motivo string }

func (n negado) Error() string { return "negado pela política: " + n.motivo }

// semFamilia: o nome não tem endereço da família pedida (e a outra NÃO é usada).
type semFamilia struct{ familia string }

func (s semFamilia) Error() string { return "o destino não tem endereço " + s.familia }

// Executar mede o alvo. Nunca entra em pânico e sempre devolve uma Saída que o coordenador sabe gravar.
func (h *HTTP) Executar(ctx context.Context, e coordenador.Execucao) coordenador.Saida {
	inicio := h.Relogio.Agora()
	fim := func() time.Time { return h.Relogio.Agora() }
	saida := func(outcome, razao string, d *resultado.Destino, st *int) coordenador.Saida {
		return coordenador.Saida{Outcome: outcome, Reason: razao, Destino: d, HTTPStatus: st, Inicio: inicio, Fim: fim()}
	}

	alvo := e.Alvo
	u, err := url.Parse(alvo.URL)
	if err != nil || u.Hostname() == "" {
		return saida(resultado.ConfigError, resultado.RazaoPoliticaNegou, nil, nil)
	}
	familia := e.Assignment.IPFamily

	var conectado *resultado.Destino
	cliente := h.cliente(familia, alvo.FollowRedirects, &conectado)
	req, err := http.NewRequestWithContext(ctx, alvo.Method, alvo.URL, nil)
	if err != nil {
		return saida(resultado.ConfigError, resultado.RazaoPoliticaNegou, nil, nil)
	}
	req.Header.Set("User-Agent", "b3mon-probe")
	req.Header.Set("Accept", "*/*")

	resp, err := cliente.Do(req)
	if err != nil {
		return h.classificar(err, conectado, inicio, fim())
	}
	defer resp.Body.Close()
	// Não se lê o corpo (só o status importa) além de um teto; o corpo NÃO vai ao resultado (06 §5).
	io.CopyN(io.Discard, resp.Body, maxCorpoLido)

	status := resp.StatusCode
	for _, ok := range alvo.AllowedStatusCodes {
		if ok == status {
			return coordenador.Saida{Outcome: resultado.Pass, Reason: resultado.RazaoSucessoHTTP, Destino: conectado, HTTPStatus: &status, Inicio: inicio, Fim: fim()}
		}
	}
	return coordenador.Saida{Outcome: resultado.Fail, Reason: resultado.RazaoStatusInesperado, Destino: conectado, HTTPStatus: &status, Inicio: inicio, Fim: fim()}
}

// cliente monta um http.Client COM A CONEXÃO CONTROLADA.
func (h *HTTP) cliente(familia string, seguir bool, conectado **resultado.Destino) *http.Client {
	rede := "tcp4"
	if familia == "ipv6" {
		rede = "tcp6"
	}
	tr := &http.Transport{
		// Proxy nulo: HTTP_PROXY/HTTPS_PROXY do ambiente não desviam a conexão (o desvio contornaria o dial validado).
		Proxy: nil,
		DialContext: func(ctx context.Context, _, endereco string) (net.Conn, error) {
			return h.discar(ctx, rede, familia, endereco, conectado)
		},
		TLSClientConfig:        &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: h.RaizesTLS},
		DisableKeepAlives:      true, // uma conexão por medição: nada de reaproveitar uma conexão validada para outro destino
		DisableCompression:     true,
		ForceAttemptHTTP2:      false,
		MaxResponseHeaderBytes: maxCabecalhos,
		TLSHandshakeTimeout:    10 * time.Second,
	}
	return &http.Client{
		Transport: tr,
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			if !seguir {
				// 3xx não seguido é a RESPOSTA: o status entra na asserção (e, normalmente, é inesperado).
				return http.ErrUseLastResponse
			}
			if len(via) > maxRedirecionamentos {
				return negado{"redirecionamentos demais"}
			}
			// Rebaixar de https para http não é permitido.
			if via[0].URL.Scheme == "https" && req.URL.Scheme != "https" {
				return negado{"redirecionamento rebaixa de https para http"}
			}
			// Host e porta do destino do redirecionamento passam pela MESMA política, antes de qualquer conexão. O IP é conferido
			// de novo no dial, que é uma conexão nova.
			if err := h.Politica.AutorizaHostPorta(req.URL.Hostname(), portaDe(req.URL)); err != nil {
				return negado{"redirecionamento: " + err.Error()}
			}
			return nil
		},
	}
}

// discar é o coração da defesa: resolve, valida e disca o MESMO endereço.
func (h *HTTP) discar(ctx context.Context, rede, familia, endereco string, conectado **resultado.Destino) (net.Conn, error) {
	host, portaTxt, err := net.SplitHostPort(endereco)
	if err != nil {
		return nil, err
	}
	porta, _ := strconv.Atoi(portaTxt)
	if err := h.Politica.AutorizaHostPorta(host, porta); err != nil {
		return nil, negado{err.Error()}
	}

	ips, err := h.resolver().LookupIP(ctx, host)
	if err != nil {
		return nil, err
	}
	candidatos := filtrarFamilia(ips, familia)
	if len(candidatos) == 0 {
		return nil, semFamilia{familia}
	}
	// TODOS os endereços da família têm de ser permitidos: uma resposta com um IP bom e um proibido é o truque do rebinding por
	// round-robin, e escolher "o bom" deixaria a decisão ao acaso do atacante.
	for _, ip := range candidatos {
		if err := h.validarIP(ip); err != nil {
			return nil, negado{fmt.Sprintf("%s resolve para %s: %v", host, ip, err)}
		}
	}
	alvo := candidatos[0]
	enderecoFixo := net.JoinHostPort(alvo.String(), portaTxt)

	var conn net.Conn
	if h.Discador != nil {
		conn, err = h.Discador(ctx, rede, enderecoFixo)
	} else {
		d := h.novoDialer()
		conn, err = d.DialContext(ctx, rede, enderecoFixo)
	}
	if err != nil {
		return nil, err
	}
	nome := host
	if net.ParseIP(host) != nil {
		*conectado = &resultado.Destino{IP: alvo.String(), Port: porta, Hostname: nil}
	} else {
		*conectado = &resultado.Destino{IP: alvo.String(), Port: porta, Hostname: &nome}
	}
	return conn, nil
}

// novoDialer é o discador real, com a SEGUNDA barreira: o endereço que o kernel vai conectar passa pela mesma regra, no próprio socket.
func (h *HTTP) novoDialer() net.Dialer {
	return net.Dialer{Timeout: 10 * time.Second, Control: h.controleDoSocket}
}

func (h *HTTP) controleDoSocket(_, address string, _ syscall.RawConn) error {
	ipTxt, _, err := net.SplitHostPort(address)
	if err != nil {
		return err
	}
	if ip := net.ParseIP(ipTxt); ip == nil || h.validarIP(ip) != nil {
		return negado{"o endereço do socket não é permitido: " + address}
	}
	return nil
}

func (h *HTTP) resolver() Resolver {
	if h.Resolver != nil {
		return h.Resolver
	}
	return ResolverSistema{}
}

// validarIP: o IP está nos CIDRs da política E fora do piso proibido. O piso vale mesmo que a política o cubra por engano.
func (h *HTTP) validarIP(ip net.IP) error {
	if err := PisoProibido(ip); err != nil {
		return err
	}
	return h.Politica.AutorizaIP(ip)
}

// PisoProibido recusa o que NUNCA é um destino de monitoramento legítimo, mesmo que a política local o cubra por engano:
// não especificado, multicast, broadcast, link-local (inclui 169.254.169.254, o metadado de nuvem) e a faixa 0.0.0.0/8.
// Loopback e redes privadas NÃO estão aqui de propósito: o laboratório e alguns alvos reais os usam, e quem os permite é a
// política, com CIDR explícito (e a política recusa CIDR largo demais).
func PisoProibido(ip net.IP) error {
	if v4 := ip.To4(); v4 != nil {
		ip = v4
	}
	switch {
	case ip.IsUnspecified():
		return errors.New("endereço não especificado")
	case ip.IsMulticast():
		return errors.New("endereço multicast")
	case ip.IsLinkLocalUnicast() || ip.IsLinkLocalMulticast():
		return errors.New("endereço link-local (inclui o metadado de nuvem)")
	}
	if v4 := ip.To4(); v4 != nil {
		if v4[0] == 0 {
			return errors.New("faixa 0.0.0.0/8")
		}
		if v4.Equal(net.IPv4bcast) {
			return errors.New("endereço de broadcast")
		}
	}
	return nil
}

func filtrarFamilia(ips []net.IP, familia string) []net.IP {
	var out []net.IP
	for _, ip := range ips {
		ehV4 := ip.To4() != nil
		if (familia == "ipv4" && ehV4) || (familia == "ipv6" && !ehV4) {
			out = append(out, ip)
		}
	}
	return out
}

func portaDe(u *url.URL) int {
	if p := u.Port(); p != "" {
		n, _ := strconv.Atoi(p)
		return n
	}
	if u.Scheme == "https" {
		return 443
	}
	return 80
}

// classificar traduz o erro de transporte para o outcome e a razão do contrato.
func (h *HTTP) classificar(err error, conectado *resultado.Destino, inicio, fim time.Time) coordenador.Saida {
	s := coordenador.Saida{Destino: conectado, Inicio: inicio, Fim: fim}
	var neg negado
	var sf semFamilia
	var ue *url.Error
	if errors.As(err, &ue) {
		err = ue.Err
	}
	var certInvalido x509.CertificateInvalidError
	var certHost x509.HostnameError
	var certAutoridade x509.UnknownAuthorityError
	var tlsRegistro tls.RecordHeaderError
	switch {
	case errors.As(err, &neg):
		// Política negou: não houve conexão ao destino negado. Destino nulo.
		s.Outcome, s.Reason, s.Destino = resultado.PolicyDenied, resultado.RazaoPoliticaNegou, nil
	case errors.As(err, &sf):
		s.Outcome, s.Reason, s.Destino = resultado.Fail, resultado.RazaoFamiliaIndisp, nil
	case errors.As(err, &certInvalido), errors.As(err, &certHost), errors.As(err, &certAutoridade):
		s.Outcome, s.Reason = resultado.Fail, "tls_certificate_invalid"
	case errors.As(err, &tlsRegistro), strings.Contains(err.Error(), "tls:"), strings.Contains(err.Error(), "HTTP response to HTTPS client"):
		s.Outcome, s.Reason = resultado.Fail, "tls_handshake_failed"
	case isTimeout(err):
		s.Outcome, s.Reason = resultado.Fail, resultado.RazaoTimeoutDoAlvo
	case errors.Is(err, syscall.ECONNREFUSED):
		s.Outcome, s.Reason = resultado.Fail, resultado.RazaoConexaoRecusada
	default:
		var dns *net.DNSError
		if errors.As(err, &dns) {
			s.Outcome, s.Reason, s.Destino = resultado.Fail, "dns_servfail", nil
			return s
		}
		s.Outcome, s.Reason = resultado.Fail, resultado.RazaoConexaoRecusada
	}
	return s
}

func isTimeout(err error) bool {
	if errors.Is(err, context.DeadlineExceeded) {
		return true
	}
	var ne net.Error
	return errors.As(err, &ne) && ne.Timeout()
}
