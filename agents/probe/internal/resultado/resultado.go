// Package resultado é o resultado normalizado de uma execução, no formato do contrato
// (contracts/schemas/result.schema.json; 06 §5). É uma observação IMUTÁVEL: depois de finalizado,
// nunca é reexecutado com o mesmo result_id nem reescrito (04 §3).
//
// O resultado NÃO carrega corpo HTTP, header secreto nem credencial. O que a sonda relata em
// `integrity` é relato, não veredito: a central refaz a avaliação.
package resultado

import (
	"encoding/json"
	"time"
)

// Versão do schema do contrato.
const SchemaVersion = "1.0"

// Resultados possíveis (14 §3).
const (
	Pass                  = "pass"
	Fail                  = "fail"
	ExecutorError         = "executor_error"
	ConfigError           = "config_error"
	PolicyDenied          = "policy_denied"
	CapabilityUnavailable = "capability_unavailable"
	Skipped               = "skipped"
)

// Códigos de razão que a sonda produz sozinha (os demais vêm do executor).
const (
	RazaoPoliticaNegou     = "policy_denied"
	RazaoAgendaPerdida     = "schedule_missed"
	RazaoExecutorIndisp    = "executor_unavailable"
	RazaoConfigExpirada    = "config_expired"
	RazaoSucessoHTTP       = "http_assertions_passed"
	RazaoTimeoutDoAlvo     = "target_timeout"
	RazaoStatusInesperado  = "unexpected_status"
	RazaoConexaoRecusada   = "connection_refused"
	RazaoFamiliaIndisp     = "family_unavailable"
	RazaoRelogioNaoConfiav = "clock_untrusted"
)

// Destino é o destino EFETIVO da conexão. Nulo quando nenhuma conexão foi tentada.
type Destino struct {
	IP       string  `json:"ip"`
	Port     int     `json:"port"`
	Hostname *string `json:"hostname"`
}

// Metricas das medições. Valor desconhecido é nulo, nunca zero.
type Metricas struct {
	HTTPStatus      *int   `json:"http_status"`
	TotalDurationMS *int64 `json:"total_duration_ms"`
	ICMPSent        *int   `json:"icmp_sent"`
	ICMPReceived    *int   `json:"icmp_received"`
}

// Relogio é o estado do relógio da sonda. Desconhecido NUNCA aparece como offset zero (INV-02).
type Relogio struct {
	Status        string `json:"status"`
	OffsetMS      *int64 `json:"offset_ms"`
	ReferenceKind string `json:"reference_kind"`
}

// Integridade é o relato da sonda sobre si mesma.
type Integridade struct {
	ProbeAssessment     string   `json:"probe_assessment"`
	ReasonCodes         []string `json:"reason_codes"`
	LocalPolicyRevision string   `json:"local_policy_revision"`
}

// Caminho é a evidência de caminho (upstream). Nesta fatia: não exigida.
type Caminho struct {
	Status string `json:"status"`
}

// Executor identifica quem mediu. O executor é sempre `native` (C-05, D-02).
type Executor struct {
	Name  string `json:"name"`
	Build string `json:"build"`
}

// Resultado é o corpo de um item de POST /api/v1/probe/results.
type Resultado struct {
	SchemaVersion     string      `json:"schema_version"`
	ResultID          string      `json:"result_id"`
	ProbeID           string      `json:"probe_id"`
	AssignmentID      string      `json:"assignment_id"`
	ConfigGeneration  int         `json:"config_generation"`
	PolicyRevision    string      `json:"policy_revision"`
	ExecutionKind     string      `json:"execution_kind"`
	ManualRequestID   string      `json:"manual_request_id,omitempty"`
	ScheduledAt       *Instante   `json:"scheduled_at"`
	StartedAt         Instante    `json:"started_at"`
	FinishedAt        Instante    `json:"finished_at"`
	DurationMS        int64       `json:"duration_ms"`
	IPFamily          string      `json:"ip_family"`
	Outcome           string      `json:"outcome"`
	ReasonCode        string      `json:"reason_code"`
	ActualDestination *Destino    `json:"actual_destination"`
	Metrics           Metricas    `json:"metrics"`
	Clock             Relogio     `json:"clock"`
	Integrity         Integridade `json:"integrity"`
	PathEvidence      Caminho     `json:"path_evidence"`
	Executor          Executor    `json:"executor"`
}

// Instante é um instante em UTC serializado como RFC 3339 com `Z` e milissegundos só quando existem (14 §6).
type Instante struct{ time.Time }

// Em cria um Instante em UTC.
func Em(t time.Time) Instante { return Instante{t.UTC()} }

// MarshalJSON escreve sempre em UTC com `Z`.
func (i Instante) MarshalJSON() ([]byte, error) {
	t := i.UTC()
	layout := "2006-01-02T15:04:05Z"
	if t.Nanosecond() != 0 {
		layout = "2006-01-02T15:04:05.000Z"
	}
	return json.Marshal(t.Format(layout))
}

// UnmarshalJSON lê RFC 3339.
func (i *Instante) UnmarshalJSON(b []byte) error {
	var s string
	if err := json.Unmarshal(b, &s); err != nil {
		return err
	}
	t, err := time.Parse(time.RFC3339Nano, s)
	if err != nil {
		return err
	}
	i.Time = t.UTC()
	return nil
}

// Ponteiro de conveniência.
func Ptr[T any](v T) *T { return &v }
