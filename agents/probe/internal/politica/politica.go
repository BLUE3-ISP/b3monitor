// Package politica é a política LOCAL da sonda: o que esta VM aceita medir, num arquivo versionado junto do binário.
//
// A regra que importa (INV-09; 06 §4): a central SELECIONA operações dentro do que a política local autoriza e NUNCA a
// amplia. O bundle não traz política de redes; se pedir um destino, uma espécie de teste ou um intervalo que a política não
// cobre, o assignment é recusado aqui, e o resultado é `policy_denied`, sem conexão alguma.
//
// O arquivo é lido em modo estrito (campo desconhecido é erro) e tem um hash SHA-256 que o heartbeat informa
// (`applied.local_policy_hash`), para a central ver qual política está valendo sem poder alterá-la.
package politica

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/url"
	"os"
	"strconv"
	"strings"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/bundle"
)

// Destino é um host autorizado e as portas permitidas nele. O host é exato ou `*.sufixo`.
type Destino struct {
	Host  string `json:"host"`
	Ports []int  `json:"ports"`
}

// Politica local. Imutável depois de carregada.
type Politica struct {
	Revision       string    `json:"revision"`
	AllowedKinds   []string  `json:"allowed_kinds"`
	MinIntervalS   int       `json:"min_interval_s"`
	MaxAssignments int       `json:"max_assignments"`
	Destinations   []Destino `json:"allowed_destinations"`
	AllowedCIDRs   []string  `json:"allowed_cidrs"`
	cidrs          []*net.IPNet
	hash           string
}

// Carregar lê o arquivo, valida e calcula o hash do CONTEÚDO exato.
func Carregar(caminho string) (*Politica, error) {
	dados, err := os.ReadFile(caminho)
	if err != nil {
		return nil, err
	}
	return Ler(dados)
}

// Ler decodifica a política de bytes.
func Ler(dados []byte) (*Politica, error) {
	dec := json.NewDecoder(bytes.NewReader(dados))
	dec.DisallowUnknownFields()
	var p Politica
	if err := dec.Decode(&p); err != nil {
		return nil, fmt.Errorf("política local: %w", err)
	}
	if p.Revision == "" {
		return nil, errors.New("política local: revision é obrigatória")
	}
	if p.MinIntervalS < 1 {
		return nil, errors.New("política local: min_interval_s tem de ser >= 1")
	}
	if p.MaxAssignments < 1 {
		return nil, errors.New("política local: max_assignments tem de ser >= 1")
	}
	if len(p.AllowedKinds) == 0 {
		return nil, errors.New("política local: allowed_kinds vazio (nada seria permitido)")
	}
	for _, d := range p.Destinations {
		if d.Host == "" || len(d.Ports) == 0 {
			return nil, errors.New("política local: cada destino precisa de host e de portas")
		}
		for _, port := range d.Ports {
			if port < 1 || port > 65535 {
				return nil, fmt.Errorf("política local: porta %d inválida", port)
			}
		}
	}
	for _, c := range p.AllowedCIDRs {
		_, n, err := net.ParseCIDR(c)
		if err != nil {
			return nil, fmt.Errorf("política local: CIDR %q inválido", c)
		}
		// Um CIDR largo demais (0.0.0.0/0, 10.0.0.0/4…) transformaria a lista de permitidos em "tudo": é erro de configuração, e
		// não uma política. O piso é /8 em IPv4 e /16 em IPv6.
		um, bits := n.Mask.Size()
		if (bits == 32 && um < 8) || (bits == 128 && um < 16) {
			return nil, fmt.Errorf("política local: CIDR %q é largo demais (mínimo /8 em IPv4 e /16 em IPv6)", c)
		}
		p.cidrs = append(p.cidrs, n)
	}
	soma := sha256.Sum256(dados)
	p.hash = hex.EncodeToString(soma[:])
	return &p, nil
}

// Hash é o SHA-256 do arquivo, em hexadecimal minúsculo.
func (p *Politica) Hash() string { return p.hash }

// Autoriza diz se a política cobre o assignment. Quando não cobre, o erro diz qual regra negou.
func (p *Politica) Autoriza(a bundle.Assignment) error {
	if !contem(p.AllowedKinds, a.Kind) {
		return fmt.Errorf("espécie %q não é permitida pela política local", a.Kind)
	}
	if a.Schedule.IntervaloS < p.MinIntervalS {
		return fmt.Errorf("intervalo de %d s abaixo do mínimo local de %d s", a.Schedule.IntervaloS, p.MinIntervalS)
	}
	if a.Kind == "http" {
		alvo, err := a.AlvoHTTP()
		if err != nil {
			return err
		}
		u, _ := url.Parse(alvo.URL)
		return p.AutorizaHostPorta(u.Hostname(), portaDe(u))
	}
	return errors.New("sem regra local para esta espécie")
}

// AutorizaHostPorta confere o host e a porta contra a lista de destinos.
func (p *Politica) AutorizaHostPorta(host string, porta int) error {
	host = strings.ToLower(host)
	for _, d := range p.Destinations {
		if casaHost(strings.ToLower(d.Host), host) {
			for _, permitida := range d.Ports {
				if permitida == porta {
					return nil
				}
			}
			return fmt.Errorf("porta %d não é permitida para %s", porta, host)
		}
	}
	return fmt.Errorf("destino %s não é permitido pela política local", host)
}

// AutorizaIP confere um IP (o efetivamente resolvido) contra os CIDRs permitidos. A validação do IP pertence ao momento
// da conexão (B7): validar o nome e conectar noutro IP é o rebinding.
func (p *Politica) AutorizaIP(ip net.IP) error {
	for _, n := range p.cidrs {
		if n.Contains(ip) {
			return nil
		}
	}
	return fmt.Errorf("IP %s fora dos CIDRs permitidos", ip)
}

// MaxAtribuicoes é o teto de assignments que a sonda aceita de uma vez.
func (p *Politica) MaxAtribuicoes() int { return p.MaxAssignments }

func casaHost(padrao, host string) bool {
	if strings.HasPrefix(padrao, "*.") {
		return strings.HasSuffix(host, padrao[1:]) && len(host) > len(padrao)-1
	}
	return padrao == host
}

func contem(l []string, v string) bool {
	for _, x := range l {
		if x == v {
			return true
		}
	}
	return false
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
