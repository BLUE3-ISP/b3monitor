package executor

import (
	"context"
	"encoding/json"
	"fmt"
	"path/filepath"
	"testing"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/bundle"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/coordenador"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/relogio"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/resultado"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/spool"
)

const (
	probeID = "33333333-3333-4333-8333-333333333333"
	asgID   = "44444444-4444-4444-8444-444444444444"
)

var epoch = time.Date(2026, 9, 28, 14, 0, 0, 0, time.UTC)

// O coordenador de verdade com o executor de verdade, contra servidores locais: o que a sonda grava no spool é o que a central
// vai ler.
func TestCoordenadorComExecutorNativoGravaODestinoEfetivo(t *testing.T) {
	porta, _ := servidor(t, "127.0.0.1", 0, ok200)
	rel := relogio.NovoFalso(epoch.Add(3 * time.Second))
	sp, err := spool.Abrir(filepath.Join(t.TempDir(), "s.db"), spool.Limites{}, rel)
	if err != nil {
		t.Fatal(err)
	}
	defer sp.Fechar()
	pol := politicaCom(t, []map[string]any{destino("127.0.0.1", porta)}, "127.0.0.1/32")
	ex := &HTTP{Politica: pol, Relogio: rel}
	co, err := coordenador.Novo(coordenador.Config{ProbeID: probeID, Versao: "t", Politica: pol, Spool: sp, Executor: ex, Relogio: rel})
	if err != nil {
		t.Fatal(err)
	}
	alvo, _ := json.Marshal(map[string]any{"url": fmt.Sprintf("http://127.0.0.1:%d/health", porta), "method": "GET", "allowed_status_codes": []int{200}, "follow_redirects": false})
	co.AplicarBundle(&bundle.Bundle{SchemaVersion: "1.0", ProbeID: probeID, Revision: 1, IssuedAt: resultado.Em(epoch), ExpiresAt: resultado.Em(epoch.Add(time.Hour)),
		Assignments: []bundle.Assignment{{
			AssignmentID: asgID, ConfigGeneration: 1, CheckID: "22222222-2222-4222-8222-222222222222", Kind: "http", IPFamily: "ipv4", Target: alvo,
			Schedule: bundle.Cronograma{Epoch: resultado.Em(epoch), IntervaloS: 30, JitterOffsetMS: 2000, ToleranciaInicio: 6000, TimeoutMS: 3000}, PolicyRevision: "critical-v1",
		}}})

	if err := co.Passo(context.Background()); err != nil {
		t.Fatal(err)
	}

	itens, _ := sp.Proximos(context.Background(), 10, 1<<20)
	if len(itens) != 1 {
		t.Fatalf("1 resultado esperado: %d", len(itens))
	}
	var r map[string]any
	json.Unmarshal(itens[0].Envelope, &r)
	dest, _ := r["actual_destination"].(map[string]any)
	if r["outcome"] != "pass" || dest == nil || dest["ip"] != "127.0.0.1" || dest["port"] != float64(porta) {
		t.Fatalf("o resultado tem de trazer o destino EFETIVO da conexão: %v", r)
	}
	if r["metrics"].(map[string]any)["http_status"] != float64(200) {
		t.Fatalf("métricas: %v", r["metrics"])
	}
}
