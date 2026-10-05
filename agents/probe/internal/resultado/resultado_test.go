package resultado

import (
	"encoding/json"
	"os"
	"reflect"
	"testing"
	"time"
)

const fixtures = "../../../../contracts/fixtures/result/"

func lerFixture(t *testing.T, nome string) map[string]any {
	t.Helper()
	b, err := os.ReadFile(fixtures + nome)
	if err != nil {
		t.Fatal(err)
	}
	var m map[string]any
	if err := json.Unmarshal(b, &m); err != nil {
		t.Fatal(err)
	}
	return m
}

func paraMapa(t *testing.T, r Resultado) map[string]any {
	t.Helper()
	b, err := json.Marshal(r)
	if err != nil {
		t.Fatal(err)
	}
	var m map[string]any
	if err := json.Unmarshal(b, &m); err != nil {
		t.Fatal(err)
	}
	return m
}

func okFixture() Resultado {
	host := "portal.monitor.example"
	status := 200
	total := int64(120)
	offset := int64(12)
	agendado := Em(time.Date(2026, 9, 28, 15, 0, 0, 0, time.UTC))
	return Resultado{
		SchemaVersion: SchemaVersion, ResultID: "55555555-5555-4555-8555-555555555555",
		ProbeID: "33333333-3333-4333-8333-333333333333", AssignmentID: "44444444-4444-4444-8444-444444444444",
		ConfigGeneration: 3, PolicyRevision: "critical-v1", ExecutionKind: "scheduled", ScheduledAt: &agendado,
		StartedAt:  Em(time.Date(2026, 9, 28, 15, 0, 2, 0, time.UTC)),
		FinishedAt: Em(time.Date(2026, 9, 28, 15, 0, 2, 120_000_000, time.UTC)),
		DurationMS: 120, IPFamily: "ipv4", Outcome: Pass, ReasonCode: RazaoSucessoHTTP,
		ActualDestination: &Destino{IP: "203.0.113.10", Port: 443, Hostname: &host},
		Metrics:           Metricas{HTTPStatus: &status, TotalDurationMS: &total},
		Clock:             Relogio{Status: "synchronized", OffsetMS: &offset, ReferenceKind: "external_ntp"},
		Integrity:         Integridade{ProbeAssessment: "valid", ReasonCodes: []string{}, LocalPolicyRevision: "local-v1"},
		PathEvidence:      Caminho{Status: "not_required"},
		Executor:          Executor{Name: "native", Build: "lab-fixture"},
	}
}

// O resultado que a sonda produz tem de ser, byte a byte no sentido do JSON, o da fixture VÁLIDA do contrato: a mesma
// fonte de verdade é lida pela central. Se alguém renomeia um campo aqui, este teste quebra.
func TestResultadoIgualAFixtureValidaDoContrato(t *testing.T) {
	obtido := paraMapa(t, okFixture())
	esperado := lerFixture(t, "valido-http-ok.json")
	if !reflect.DeepEqual(obtido, esperado) {
		o, _ := json.MarshalIndent(obtido, "", " ")
		e, _ := json.MarshalIndent(esperado, "", " ")
		t.Fatalf("a sonda gera um resultado diferente da fixture do contrato.\nobtido:\n%s\nesperado:\n%s", o, e)
	}
}

func TestRelogioDesconhecidoTemOffsetNuloNuncaZero(t *testing.T) {
	r := okFixture()
	r.Clock = Relogio{Status: "unknown", OffsetMS: nil, ReferenceKind: "none"}
	m := paraMapa(t, r)
	c := m["clock"].(map[string]any)
	if v, ok := c["offset_ms"]; !ok || v != nil {
		t.Fatalf("offset_ms do relógio desconhecido tem de ser null (INV-02), veio %v", v)
	}
}

func TestResultadoSemConexaoTemDestinoNulo(t *testing.T) {
	r := okFixture()
	r.ActualDestination = nil
	if v, ok := paraMapa(t, r)["actual_destination"]; !ok || v != nil {
		t.Fatalf("sem conexão, actual_destination tem de ser null, veio %v", v)
	}
}

func TestMilissegundosSoQuandoExistem(t *testing.T) {
	b, _ := json.Marshal(Em(time.Date(2026, 1, 2, 3, 4, 5, 0, time.UTC)))
	if string(b) != `"2026-01-02T03:04:05Z"` {
		t.Fatalf("sem fração: %s", b)
	}
	b, _ = json.Marshal(Em(time.Date(2026, 1, 2, 3, 4, 5, 120_000_000, time.UTC)))
	if string(b) != `"2026-01-02T03:04:05.120Z"` {
		t.Fatalf("com fração: %s", b)
	}
}

func TestInstanteSempreEmUTC(t *testing.T) {
	sp := time.FixedZone("BRT", -3*3600)
	b, _ := json.Marshal(Em(time.Date(2026, 1, 2, 0, 0, 0, 0, sp)))
	if string(b) != `"2026-01-02T03:00:00Z"` {
		t.Fatalf("hora local vazou para o contrato: %s", b)
	}
}

func TestInstanteIdaEVolta(t *testing.T) {
	var i Instante
	if err := json.Unmarshal([]byte(`"2026-09-28T15:00:02.120Z"`), &i); err != nil {
		t.Fatal(err)
	}
	if i.UnixMilli() != time.Date(2026, 9, 28, 15, 0, 2, 120_000_000, time.UTC).UnixMilli() {
		t.Fatalf("leu errado: %v", i)
	}
	if err := json.Unmarshal([]byte(`"ontem"`), &i); err == nil {
		t.Fatal("instante inválido devia falhar")
	}
}
