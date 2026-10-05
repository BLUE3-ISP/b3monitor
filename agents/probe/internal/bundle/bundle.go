// Package bundle lê e valida a configuração que a central envia à sonda
// (contracts/schemas/bundle.schema.json; 06 §4).
//
// O bundle SELECIONA operações já autorizadas pela política local da VM; ele nunca a amplia (INV-09), e por isso
// não traz política de redes. Segredos entram por referência, nunca em texto. Não há campo para URL de callback
// nem instrução executável: a leitura recusa qualquer campo desconhecido, em todo nível (06 §11).
package bundle

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/url"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/resultado"
)

// Cronograma de um assignment: slots absolutos `epoch + n × intervalo` (14 §2).
type Cronograma struct {
	Epoch            resultado.Instante `json:"epoch"`
	IntervaloS       int                `json:"interval_s"`
	JitterOffsetMS   int64              `json:"jitter_offset_ms"`
	ToleranciaInicio int64              `json:"start_tolerance_ms"`
	TimeoutMS        int64              `json:"timeout_ms"`
}

// AlvoHTTP é o alvo da única espécie detalhada nesta fatia (F0-01).
type AlvoHTTP struct {
	URL                string `json:"url"`
	Method             string `json:"method"`
	AllowedStatusCodes []int  `json:"allowed_status_codes"`
	FollowRedirects    bool   `json:"follow_redirects"`
}

// Assignment é uma verificação atribuída a esta sonda numa família IP.
type Assignment struct {
	AssignmentID     string          `json:"assignment_id"`
	ConfigGeneration int             `json:"config_generation"`
	CheckID          string          `json:"check_id"`
	Kind             string          `json:"kind"`
	IPFamily         string          `json:"ip_family"`
	Target           json.RawMessage `json:"target"`
	Schedule         Cronograma      `json:"schedule"`
	PolicyRevision   string          `json:"policy_revision"`
	PathProfileID    *string         `json:"path_profile_id"`
	CredentialRef    *string         `json:"credential_ref"`
}

// Bundle é a configuração completa, com validade.
type Bundle struct {
	SchemaVersion string             `json:"schema_version"`
	ProbeID       string             `json:"probe_id"`
	Revision      int                `json:"bundle_revision"`
	IssuedAt      resultado.Instante `json:"issued_at"`
	ExpiresAt     resultado.Instante `json:"expires_at"`
	Assignments   []Assignment       `json:"assignments"`
}

// Ler decodifica e valida. Campo desconhecido, campo obrigatório ausente e regra violada são erro: uma configuração
// duvidosa não é aplicada pela metade.
func Ler(r io.Reader) (*Bundle, error) {
	dados, err := io.ReadAll(io.LimitReader(r, 4<<20))
	if err != nil {
		return nil, err
	}
	// O contrato tem `additionalProperties: false` em todo nível; DisallowUnknownFields cobre os structs. O que não é
	// struct (o `target`) é decodificado depois, também em modo estrito.
	if err := exigirCampos(dados, "schema_version", "probe_id", "bundle_revision", "issued_at", "expires_at", "assignments"); err != nil {
		return nil, err
	}
	dec := json.NewDecoder(bytes.NewReader(dados))
	dec.DisallowUnknownFields()
	var b Bundle
	if err := dec.Decode(&b); err != nil {
		return nil, fmt.Errorf("bundle: %w", err)
	}
	if err := b.validar(); err != nil {
		return nil, err
	}
	return &b, nil
}

func exigirCampos(dados []byte, campos ...string) error {
	var m map[string]json.RawMessage
	if err := json.Unmarshal(dados, &m); err != nil {
		return fmt.Errorf("bundle: %w", err)
	}
	for _, c := range campos {
		if _, ok := m[c]; !ok {
			return fmt.Errorf("bundle: campo obrigatório ausente: %s", c)
		}
	}
	return nil
}

func (b *Bundle) validar() error {
	if b.SchemaVersion != resultado.SchemaVersion {
		return fmt.Errorf("bundle: schema_version %q não suportada", b.SchemaVersion)
	}
	if b.Revision < 1 {
		return errors.New("bundle: bundle_revision tem de ser >= 1")
	}
	if len(b.Assignments) > 1000 {
		return errors.New("bundle: no máximo 1000 assignments")
	}
	vistos := map[string]bool{}
	for i, a := range b.Assignments {
		if err := a.validar(); err != nil {
			return fmt.Errorf("bundle: assignment %d: %w", i, err)
		}
		if vistos[a.AssignmentID] {
			return fmt.Errorf("bundle: assignment %d: assignment_id repetido", i)
		}
		vistos[a.AssignmentID] = true
	}
	return nil
}

func (a Assignment) validar() error {
	if a.AssignmentID == "" || a.CheckID == "" {
		return errors.New("assignment_id e check_id são obrigatórios")
	}
	if a.ConfigGeneration < 1 {
		return errors.New("config_generation tem de ser >= 1")
	}
	if a.IPFamily != "ipv4" && a.IPFamily != "ipv6" {
		return fmt.Errorf("ip_family %q inválida", a.IPFamily)
	}
	if a.Schedule.IntervaloS < 1 {
		return errors.New("interval_s tem de ser >= 1")
	}
	if a.Schedule.TimeoutMS < 1 {
		return errors.New("timeout_ms tem de ser >= 1")
	}
	if a.Schedule.JitterOffsetMS < 0 || a.Schedule.ToleranciaInicio < 0 {
		return errors.New("jitter_offset_ms e start_tolerance_ms não podem ser negativos")
	}
	if a.PolicyRevision == "" {
		return errors.New("policy_revision é obrigatória")
	}
	if a.Kind != "http" {
		// tcp, icmp, dns e tls ganham o alvo próprio em F0-03. Até lá, a sonda não os executa e diz isso.
		return fmt.Errorf("kind %q ainda não é suportado pela sonda (F0-03)", a.Kind)
	}
	_, err := a.AlvoHTTP()
	return err
}

// AlvoHTTP decodifica e valida o alvo HTTP, também em modo estrito.
func (a Assignment) AlvoHTTP() (AlvoHTTP, error) {
	var t AlvoHTTP
	dec := json.NewDecoder(bytes.NewReader(a.Target))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&t); err != nil {
		return t, fmt.Errorf("target http: %w", err)
	}
	u, err := url.Parse(t.URL)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return t, fmt.Errorf("target http: url %q tem de ser http(s) com host", t.URL)
	}
	if t.Method != "GET" && t.Method != "HEAD" {
		return t, fmt.Errorf("target http: method %q não permitido (GET ou HEAD)", t.Method)
	}
	if len(t.AllowedStatusCodes) == 0 {
		return t, errors.New("target http: allowed_status_codes não pode ser vazio")
	}
	for _, c := range t.AllowedStatusCodes {
		if c < 100 || c > 599 {
			return t, fmt.Errorf("target http: status %d fora de 100–599", c)
		}
	}
	return t, nil
}

// Expirado diz se a configuração já não vale. Uma sonda não executa com configuração vencida (reason `config_expired`).
func (b *Bundle) Expirado(agora time.Time) bool { return !agora.Before(b.ExpiresAt.Time) }
