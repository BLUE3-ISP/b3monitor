package coordenador

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"reflect"
	"testing"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/envio"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/resultado"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/testes"
)

// formaDe reduz um JSON à sua FORMA: as chaves e o tipo de cada valor (null, número, texto, objeto, lista), sem os valores.
func formaDe(v any) any {
	switch x := v.(type) {
	case map[string]any:
		m := map[string]any{}
		for k, vv := range x {
			m[k] = formaDe(vv)
		}
		return m
	case []any:
		return "lista"
	case nil:
		return "nulo"
	case string:
		return "texto"
	case float64:
		return "número"
	default:
		return "outro"
	}
}

func comoMapa(t *testing.T, v any) map[string]any {
	t.Helper()
	b, err := json.Marshal(v)
	if err != nil {
		t.Fatal(err)
	}
	var m map[string]any
	if err := json.Unmarshal(b, &m); err != nil {
		t.Fatal(err)
	}
	return m
}

func discoFixo(a *amb) {
	a.co.cfg.DiscoLivre = func() (uint64, error) { return 10 << 30, nil }
}

func TestHeartbeatTemAFormaDaFixtureDoContrato(t *testing.T) {
	a := novo(t, bundleCom(url, 3, epoch.Add(24*time.Hour)))
	discoFixo(a)
	a.rel.Avancar(time.Second)
	a.passo(t) // para last_execution_at e schedule_lag_ms terem valor

	h, err := a.co.Heartbeat(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	b, _ := os.ReadFile("../../../../contracts/fixtures/heartbeat/valido.json")
	var fx map[string]any
	json.Unmarshal(b, &fx)

	obtido := formaDe(comoMapa(t, h)).(map[string]any)
	esperado := formaDe(fx).(map[string]any)
	// `spool.oldest_age_s` é nulo na fixture (spool vazio) e aqui tem 1 resultado: o tipo muda, a CHAVE não. Compara as chaves.
	if !reflect.DeepEqual(chaves(obtido), chaves(esperado)) {
		t.Fatalf("as chaves do heartbeat divergem do contrato.\nobtido:   %v\nesperado: %v", chaves(obtido), chaves(esperado))
	}
}

func chaves(m map[string]any) map[string]any {
	out := map[string]any{}
	for k, v := range m {
		if sub, ok := v.(map[string]any); ok {
			out[k] = chaves(sub)
		} else {
			out[k] = "·"
		}
	}
	return out
}

func TestHeartbeatDesconhecidoNaoViraZero(t *testing.T) {
	a := novo(t, nil) // sem bundle, sem execução, spool vazio
	discoFixo(a)
	h, err := a.co.Heartbeat(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	m := comoMapa(t, h)
	applied := m["applied"].(map[string]any)
	spool := m["spool"].(map[string]any)
	caps := m["capabilities"].(map[string]any)
	clock := m["clock"].(map[string]any)

	for nome, v := range map[string]any{
		"applied.bundle_revision": applied["bundle_revision"], "applied.config_hash": applied["config_hash"],
		"last_execution_at": m["last_execution_at"], "schedule_lag_ms": m["schedule_lag_ms"],
		"spool.oldest_age_s": spool["oldest_age_s"], "clock.offset_ms": clock["offset_ms"],
	} {
		if v != nil {
			t.Errorf("%s: desconhecido tem de ser null, veio %v", nome, v)
		}
	}
	for k, v := range caps {
		if v != "unknown" {
			t.Errorf("capacidade %s não medida é `unknown`, veio %v", k, v)
		}
	}
	if clock["status"] != "unknown" {
		t.Errorf("relógio: %v", clock)
	}
	if applied["local_policy_hash"] != politicaLab(t).Hash() {
		t.Errorf("o hash da política local é o do arquivo carregado: %v", applied["local_policy_hash"])
	}
}

func TestHeartbeatRefleteOEstadoReal(t *testing.T) {
	a := novo(t, bundleCom(url, 3, epoch.Add(24*time.Hour)))
	discoFixo(a)
	a.co.cfg.Capacidades = Capacidades{IPv4: "available", IPv6: "unavailable"}
	a.co.cfg.EstadoRelogio = func() resultado.Relogio {
		off := int64(12)
		return resultado.Relogio{Status: "synchronized", OffsetMS: &off, ReferenceKind: "external_ntp"}
	}
	a.rel.Avancar(time.Second)
	a.passo(t)
	a.rel.Avancar(90 * time.Second)

	h, _ := a.co.Heartbeat(context.Background())
	if h.UptimeS != 91 {
		t.Errorf("uptime = %d", h.UptimeS)
	}
	if h.Applied.BundleRevision == nil || *h.Applied.BundleRevision != 3 || h.Applied.ConfigHash == nil || len(*h.Applied.ConfigHash) != 64 {
		t.Errorf("applied: %+v", h.Applied)
	}
	if h.Spool.Rows != 1 || h.Spool.OldestAgeS == nil || *h.Spool.OldestAgeS != 90 {
		t.Errorf("spool: %+v", h.Spool)
	}
	if h.ScheduleLagMS == nil || *h.ScheduleLagMS != 0 {
		t.Errorf("atraso da agenda: %v", h.ScheduleLagMS)
	}
	if h.Capabilities.IPv4 != "available" || h.Capabilities.IPv6 != "unavailable" || h.Capabilities.ICMP != "unknown" {
		t.Errorf("capacidades: %+v", h.Capabilities)
	}
	if h.Clock.Status != "synchronized" {
		t.Errorf("relógio: %+v", h.Clock)
	}
}

func TestHeartbeatSemDiscoMedidoNaoInventaUmNumero(t *testing.T) {
	a := novo(t, nil)
	a.co.cfg.DiscoLivre = func() (uint64, error) { return 0, errors.New("statfs falhou") }
	if _, err := a.co.Heartbeat(context.Background()); err == nil {
		t.Fatal("sem medir o disco, o heartbeat não pode ser montado com um zero inventado")
	}
}

func TestHeartbeatChegaACentralFalsa(t *testing.T) {
	central := testes.Nova("segredo")
	defer central.Close()
	a := novo(t, bundleCom(url, 3, epoch.Add(24*time.Hour)))
	discoFixo(a)
	h, _ := a.co.Heartbeat(context.Background())
	cli := &envio.Cliente{Base: central.URL(), Token: "segredo"}
	if err := cli.EnviarHeartbeat(context.Background(), h); err != nil {
		t.Fatal(err)
	}
	if len(central.Heartbeats()) != 1 {
		t.Fatal("a central não recebeu o heartbeat")
	}
	// Credencial errada: erro, e nada quebra.
	ruim := &envio.Cliente{Base: central.URL(), Token: "x"}
	if err := ruim.EnviarHeartbeat(context.Background(), h); err == nil {
		t.Fatal("token errado devia falhar")
	}
}
