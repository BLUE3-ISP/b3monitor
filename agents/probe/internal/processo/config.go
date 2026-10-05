// Package processo é o PROCESSO da sonda: lê a configuração local, junta os pacotes (política, spool, coordenador, executor, envio) e
// roda o laço até receber SIGTERM. A lógica de medir, planejar e enviar mora nos pacotes; aqui só se decide a ordem em que as peças
// se ligam e o que fazer quando uma delas falha.
package processo

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"strings"
)

// RelogioDeclarado é o relógio que o OPERADOR declara. A sonda não mede NTP ainda (04 §8): sem declaração, o relógio é `unknown`, com
// offset nulo, e a central nega o voto temporal. Uma declaração de `synchronized` precisa dizer de onde vem (`reference_kind`), para
// um relógio declarado não passar por medido: o laboratório usa `lab_declared`.
type RelogioDeclarado struct {
	Status        string `json:"status"`
	OffsetMS      *int64 `json:"offset_ms"`
	ReferenceKind string `json:"reference_kind"`
}

// Capacidades declaradas. Sem declaração, `unknown`: capacidade não medida não é "disponível".
type Capacidades struct {
	IPv4 string `json:"ipv4"`
	IPv6 string `json:"ipv6"`
	ICMP string `json:"icmp"`
}

// Config é o arquivo de configuração LOCAL da sonda (lido em modo estrito). Não traz segredo: o token fica num arquivo à parte.
type Config struct {
	ProbeID         string           `json:"probe_id"`
	CentralURL      string           `json:"central_url"`
	TokenFile       string           `json:"token_file"`
	PolicyFile      string           `json:"policy_file"`
	SpoolPath       string           `json:"spool_path"`
	BundleCachePath string           `json:"bundle_cache_path"`
	ExecutorSocket  string           `json:"executor_socket"`
	TLSCAFile       string           `json:"tls_ca_file"`
	StepIntervalMS  int              `json:"step_interval_ms"`
	HeartbeatS      int              `json:"heartbeat_interval_s"`
	ConfigRefreshS  int              `json:"config_refresh_interval_s"`
	Clock           RelogioDeclarado `json:"clock"`
	Capabilities    Capacidades      `json:"capabilities"`
}

// CarregarConfig lê e valida o arquivo. Campo desconhecido é erro, e os padrões são preenchidos depois de validar.
func CarregarConfig(caminho string) (Config, error) {
	dados, err := os.ReadFile(caminho)
	if err != nil {
		return Config{}, err
	}
	return LerConfig(dados)
}

// LerConfig decodifica a configuração de bytes.
func LerConfig(dados []byte) (Config, error) {
	var c Config
	dec := json.NewDecoder(bytes.NewReader(dados))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&c); err != nil {
		return Config{}, fmt.Errorf("configuração: %w", err)
	}
	for nome, v := range map[string]string{
		"probe_id": c.ProbeID, "central_url": c.CentralURL, "token_file": c.TokenFile, "policy_file": c.PolicyFile,
		"spool_path": c.SpoolPath, "bundle_cache_path": c.BundleCachePath,
	} {
		if strings.TrimSpace(v) == "" {
			return Config{}, fmt.Errorf("configuração: %s é obrigatório", nome)
		}
	}
	if !strings.HasPrefix(c.CentralURL, "https://") && !strings.HasPrefix(c.CentralURL, "http://") {
		return Config{}, errors.New("configuração: central_url tem de começar com https:// ou http://")
	}
	if c.StepIntervalMS == 0 {
		c.StepIntervalMS = 1000
	}
	if c.HeartbeatS == 0 {
		c.HeartbeatS = 30
	}
	if c.ConfigRefreshS == 0 {
		c.ConfigRefreshS = 60
	}
	if c.StepIntervalMS < 50 || c.HeartbeatS < 1 || c.ConfigRefreshS < 1 {
		return Config{}, errors.New("configuração: intervalos abaixo do mínimo (passo 50 ms, heartbeat e atualização 1 s)")
	}
	switch c.Clock.Status {
	case "":
		c.Clock = RelogioDeclarado{Status: "unknown", OffsetMS: nil, ReferenceKind: "none"}
	case "unknown":
		if c.Clock.OffsetMS != nil {
			return Config{}, errors.New("configuração: relógio unknown exige offset_ms nulo (INV-02)")
		}
		if c.Clock.ReferenceKind == "" {
			c.Clock.ReferenceKind = "none"
		}
	case "synchronized", "unsynchronized":
		if c.Clock.OffsetMS == nil || c.Clock.ReferenceKind == "" {
			return Config{}, errors.New("configuração: relógio declarado exige offset_ms e reference_kind (diga de onde vem, p.ex. lab_declared)")
		}
	default:
		return Config{}, fmt.Errorf("configuração: estado de relógio %q inválido", c.Clock.Status)
	}
	return c, nil
}

// LerToken lê o token da credencial. O arquivo tem de ser do dono e SEM acesso de grupo ou outros (0600 ou mais restrito): um token
// legível por outro usuário é um token vazado. A mensagem de erro NUNCA contém o token.
func LerToken(caminho string) (string, error) {
	info, err := os.Stat(caminho)
	if err != nil {
		return "", fmt.Errorf("token: %w", err)
	}
	if info.Mode().Perm()&0o077 != 0 {
		return "", fmt.Errorf("token: %s tem modo %o; use 0600 (grupo e outros não podem ler a credencial)", caminho, info.Mode().Perm())
	}
	b, err := os.ReadFile(caminho)
	if err != nil {
		return "", fmt.Errorf("token: %w", err)
	}
	t := strings.TrimSpace(string(b))
	if t == "" {
		return "", fmt.Errorf("token: %s está vazio", caminho)
	}
	return t, nil
}
