package envio

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/relogio"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/spool"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/testes"
)

var t0 = time.Date(2026, 9, 28, 15, 0, 0, 0, time.UTC)

type amb struct {
	sp  *spool.Spool
	rel *relogio.Falso
	c   *testes.Central
	env *Enviador
}

func novo(t *testing.T) *amb {
	t.Helper()
	rel := relogio.NovoFalso(t0)
	sp, err := spool.Abrir(filepath.Join(t.TempDir(), "s.db"), spool.Limites{}, rel)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { sp.Fechar() })
	c := testes.Nova("segredo")
	t.Cleanup(c.Close)
	return &amb{sp: sp, rel: rel, c: c, env: &Enviador{
		Spool: sp, Cliente: &Cliente{Base: c.URL(), Token: "segredo"}, Relogio: rel,
		Aleatorio: func() float64 { return 0.5 }, // jitter neutro: 0,8 + 0,4 × 0,5 = 1,0
	}}
}

func (a *amb) enfileira(t *testing.T, n int) string {
	t.Helper()
	id := fmt.Sprintf("00000000-0000-4000-8000-%012d", n)
	it := spool.ItemAgenda{AssignmentID: "a1", Geracao: 1, Slot: t0.Add(time.Duration(n) * 30 * time.Second), ResultID: id}
	ctx := context.Background()
	if _, _, err := a.sp.Planejar(ctx, it); err != nil {
		t.Fatal(err)
	}
	env := fmt.Sprintf(`{"result_id":"%s","n":%d}`, id, n)
	if err := a.sp.Finalizar(ctx, it, spool.Concluido, []byte(env)); err != nil {
		t.Fatal(err)
	}
	return id
}

func (a *amb) pendentes(t *testing.T) int {
	e, err := a.sp.Estatisticas(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	return int(e.Linhas)
}

func TestEnvioFeliz(t *testing.T) {
	a := novo(t)
	for i := 1; i <= 3; i++ {
		a.enfileira(t, i)
	}
	r, err := a.env.Ciclo(context.Background())
	if err != nil || r.Confirmados != 3 {
		t.Fatalf("%+v %v", r, err)
	}
	if a.c.Gravados() != 3 || a.pendentes(t) != 0 {
		t.Fatalf("central=%d spool=%d", a.c.Gravados(), a.pendentes(t))
	}
}

func TestCentralForaMantemTudoNoSpoolERecuaComBackoff(t *testing.T) {
	a := novo(t)
	a.enfileira(t, 1)
	a.c.DefinirModo(testes.Fora, 0)

	_, err := a.env.Ciclo(context.Background())
	var el *ErroDeLote
	if !errors.As(err, &el) || el.Status != 503 {
		t.Fatalf("esperava 503: %v", err)
	}
	if a.pendentes(t) != 1 || a.c.Gravados() != 0 {
		t.Fatal("o resultado não pode sair do spool com a central fora")
	}
	// Backoff: 1 s, depois 2 s, 4 s… (jitter neutro). Dentro da espera, nem tenta.
	if got := a.env.ProximoEnvio().Sub(t0); got != time.Second {
		t.Fatalf("1ª espera = %v", got)
	}
	chamadas := a.c.Lotes()
	a.env.Ciclo(context.Background())
	if a.c.Lotes() != chamadas {
		t.Fatal("o enviador tentou DENTRO da espera do backoff")
	}
	a.rel.Avancar(time.Second)
	a.env.Ciclo(context.Background())
	if got := a.env.ProximoEnvio().Sub(a.rel.Agora()); got != 2*time.Second {
		t.Fatalf("2ª espera = %v", got)
	}
	a.rel.Avancar(2 * time.Second)
	a.env.Ciclo(context.Background())
	if got := a.env.ProximoEnvio().Sub(a.rel.Agora()); got != 4*time.Second {
		t.Fatalf("3ª espera = %v", got)
	}
}

func TestBackoffTemTeto(t *testing.T) {
	a := novo(t)
	a.env.Teto = 30 * time.Second
	for n := 1; n <= 20; n++ {
		if d := a.env.espera(n); d > 30*time.Second+time.Millisecond {
			t.Fatalf("espera %d = %v passou do teto", n, d)
		}
	}
	if d := a.env.espera(20); d != 30*time.Second {
		t.Fatalf("no teto: %v", d)
	}
}

func TestJitterVariaDentroDaFaixa(t *testing.T) {
	a := novo(t)
	a.env.Aleatorio = func() float64 { return 0 }
	min := a.env.espera(3) // 4 s × 0,8
	a.env.Aleatorio = func() float64 { return 0.999999 }
	max := a.env.espera(3) // 4 s × ~1,2
	if min != 3200*time.Millisecond || max < 4790*time.Millisecond || max > 4800*time.Millisecond {
		t.Fatalf("jitter fora de ±20%%: %v .. %v", min, max)
	}
}

func TestRetryAfterDaCentralVenceOBackoffCurto(t *testing.T) {
	a := novo(t)
	a.enfileira(t, 1)
	a.c.DefinirModo(testes.Fora, 90)
	a.env.Ciclo(context.Background())
	if got := a.env.ProximoEnvio().Sub(t0); got != 90*time.Second {
		t.Fatalf("Retry-After de 90 s ignorado: %v", got)
	}
}

func TestRespostaPerdidaDepoisDoCommitVirouDuplicateENaoDuplica(t *testing.T) {
	a := novo(t)
	id := a.enfileira(t, 1)
	a.c.DefinirModo(testes.CairAposCommit, 0)

	_, err := a.env.Ciclo(context.Background())
	if err == nil {
		t.Fatal("a conexão caiu: devia haver erro de transporte")
	}
	// A central JÁ gravou, mas a sonda não sabe: o resultado continua no spool.
	if !a.c.TemResultado(id) || a.pendentes(t) != 1 {
		t.Fatalf("central tem=%v spool=%d", a.c.TemResultado(id), a.pendentes(t))
	}

	a.c.DefinirModo(testes.Normal, 0)
	a.rel.Avancar(time.Minute)
	r, err := a.env.Ciclo(context.Background())
	if err != nil || r.Confirmados != 1 {
		t.Fatalf("o reenvio devia ser confirmado: %+v %v", r, err)
	}
	// UM resultado na central, spool vazio: pelo menos uma vez no transporte, exatamente uma vez no resultado.
	if a.c.Gravados() != 1 || a.pendentes(t) != 0 {
		t.Fatalf("central=%d spool=%d", a.c.Gravados(), a.pendentes(t))
	}
}

func TestConfirmacaoEPorItemELoteParcial(t *testing.T) {
	a := novo(t)
	ids := []string{}
	for i := 1; i <= 4; i++ {
		ids = append(ids, a.enfileira(t, i))
	}
	a.c.ItemRetryavel(ids[1], true)
	a.c.ItemTerminal(ids[2], true)

	r, err := a.env.Ciclo(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if r.Confirmados != 2 || r.Reagendados != 1 || r.Quarentena != 1 {
		t.Fatalf("%+v", r)
	}
	// HTTP 200 do lote NÃO apagou tudo: o retryable continua; o terminal foi para a quarentena.
	e, _ := a.sp.Estatisticas(context.Background())
	if e.Linhas != 1 || e.Quarentena != 1 {
		t.Fatalf("spool: %+v", e)
	}
	if a.c.TemResultado(ids[1]) || a.c.TemResultado(ids[2]) {
		t.Fatal("a central não devia ter gravado o retryable nem o terminal")
	}
}

func TestRetryableVoltaDepoisDaEsperaPorItemEFinalmenteEConfirma(t *testing.T) {
	a := novo(t)
	id := a.enfileira(t, 1)
	a.c.ItemRetryavel(id, true)
	a.env.Ciclo(context.Background())

	// Dentro da espera do item, ele não está pronto.
	if r, _ := a.env.Ciclo(context.Background()); r.Confirmados+r.Reagendados != 0 {
		t.Fatalf("o item não devia estar pronto: %+v", r)
	}
	a.c.ItemRetryavel(id, false)
	a.rel.Avancar(5 * time.Second)
	r, _ := a.env.Ciclo(context.Background())
	if r.Confirmados != 1 || a.pendentes(t) != 0 {
		t.Fatalf("%+v pendentes=%d", r, a.pendentes(t))
	}
}

func TestItemNaoCitadoNaRespostaNaoEApagado(t *testing.T) {
	a := novo(t)
	a.enfileira(t, 1)
	a.enfileira(t, 2)
	// Um cliente cuja resposta só cita UM dos dois itens.
	a.env.Cliente = clienteQueCitaSo{ate: 1, real: a.env.Cliente}
	r, err := a.env.Ciclo(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if r.Confirmados != 1 || r.Sem != 1 || a.pendentes(t) != 1 {
		t.Fatalf("%+v pendentes=%d", r, a.pendentes(t))
	}
}

type clienteQueCitaSo struct {
	ate  int
	real interface {
		Enviar(ctx context.Context, itens []spool.ItemFila) (string, []RespostaItem, error)
	}
}

func (c clienteQueCitaSo) Enviar(ctx context.Context, itens []spool.ItemFila) (string, []RespostaItem, error) {
	id, resp, err := c.real.Enviar(ctx, itens)
	if len(resp) > c.ate {
		resp = resp[:c.ate]
	}
	return id, resp, err
}

func TestAcceptedSemDurableNaoApaga(t *testing.T) {
	a := novo(t)
	a.enfileira(t, 1)
	a.env.Cliente = clienteFixo{func(it []spool.ItemFila) []RespostaItem {
		return []RespostaItem{{ResultID: it[0].ResultID, Status: spool.Accepted, Durable: false}}
	}}
	r, _ := a.env.Ciclo(context.Background())
	if r.Confirmados != 0 || a.pendentes(t) != 1 {
		t.Fatalf("`accepted` sem durável não pode tirar o item do spool: %+v", r)
	}
}

type clienteFixo struct {
	f func([]spool.ItemFila) []RespostaItem
}

func (c clienteFixo) Enviar(ctx context.Context, itens []spool.ItemFila) (string, []RespostaItem, error) {
	return "b", c.f(itens), nil
}

func TestLoteEnvenenadoPor403EPartidoEIsolado(t *testing.T) {
	a := novo(t)
	ids := []string{}
	for i := 1; i <= 8; i++ {
		ids = append(ids, a.enfileira(t, i))
	}
	veneno := ids[5]
	// A central derruba o lote INTEIRO se ele contém o assignment que não é desta sonda (403 do contrato).
	a.c.RecusarLoteSe(func(lote []string) bool {
		for _, id := range lote {
			if id == veneno {
				return true
			}
		}
		return false
	})

	r, err := a.env.Ciclo(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if r.Confirmados != 7 || r.Quarentena != 1 {
		t.Fatalf("7 saudáveis confirmados e o envenenado isolado: %+v", r)
	}
	e, _ := a.sp.Estatisticas(context.Background())
	if e.Linhas != 0 || e.Quarentena != 1 || a.c.Gravados() != 7 {
		t.Fatalf("spool=%+v central=%d", e, a.c.Gravados())
	}
}

func Test401NaoCulpaItemNenhum(t *testing.T) {
	a := novo(t)
	a.enfileira(t, 1)
	a.env.Cliente = &Cliente{Base: a.c.URL(), Token: "token-errado"}

	_, err := a.env.Ciclo(context.Background())
	var el *ErroDeLote
	if !errors.As(err, &el) || el.Status != http.StatusUnauthorized {
		t.Fatalf("esperava 401: %v", err)
	}
	e, _ := a.sp.Estatisticas(context.Background())
	if e.Linhas != 1 || e.Quarentena != 0 {
		t.Fatalf("credencial errada não é culpa do resultado, que fica no spool: %+v", e)
	}
}

func TestLoteGrandeVaiComGzipELimitaItens(t *testing.T) {
	a := novo(t)
	for i := 1; i <= 120; i++ {
		a.enfileira(t, i)
	}
	r, err := a.env.Ciclo(context.Background())
	if err != nil || r.Confirmados != MaxItensPorLote {
		t.Fatalf("um ciclo manda no máximo %d itens: %+v %v", MaxItensPorLote, r, err)
	}
	// UM pedido, e COMPRIMIDO: sem isto, um gzip quebrado seria escondido pela partição do lote (a central recusa o corpo e a
	// sonda parte e reenvia item a item, e o resultado final seria o mesmo).
	if a.c.Lotes() != 1 || a.c.LotesGzip() != 1 {
		t.Fatalf("pedidos=%d gzip=%d, esperava 1 pedido e 1 comprimido", a.c.Lotes(), a.c.LotesGzip())
	}
	r, _ = a.env.Ciclo(context.Background())
	if r.Confirmados != 20 || a.c.Gravados() != 120 {
		t.Fatalf("o resto no ciclo seguinte: %+v central=%d", r, a.c.Gravados())
	}
}

func TestMaisRecentesPrimeiro(t *testing.T) {
	a := novo(t)
	for i := 1; i <= 3; i++ {
		a.enfileira(t, i)
	}
	var ordem []string
	a.env.Cliente = clienteFixo{func(it []spool.ItemFila) []RespostaItem {
		out := []RespostaItem{}
		for _, x := range it {
			ordem = append(ordem, x.ResultID[len(x.ResultID)-3:])
			out = append(out, RespostaItem{ResultID: x.ResultID, Status: spool.Accepted, Durable: true})
		}
		return out
	}}
	a.env.Ciclo(context.Background())
	if strings.Join(ordem, ",") != "003,002,001" {
		t.Fatalf("o dado corrente tem de ir primeiro: %v", ordem)
	}
}

func TestSucessoZeraOContadorDeFalhasEOBackoffRecomeca(t *testing.T) {
	a := novo(t)
	a.enfileira(t, 1)
	ctx := context.Background()

	a.c.DefinirModo(testes.Fora, 0)
	for i := 0; i < 3; i++ {
		a.env.Ciclo(ctx)
		a.rel.Avancar(time.Hour)
	}
	if a.env.Falhas() != 3 {
		t.Fatalf("falhas = %d", a.env.Falhas())
	}

	a.c.DefinirModo(testes.Normal, 0)
	if _, err := a.env.Ciclo(ctx); err != nil {
		t.Fatal(err)
	}
	if a.env.Falhas() != 0 {
		t.Fatalf("o sucesso tem de zerar as falhas: %d", a.env.Falhas())
	}

	// A próxima queda recua desde o começo (1 s), e não de 8 s.
	a.enfileira(t, 2)
	a.c.DefinirModo(testes.Fora, 0)
	a.env.Ciclo(ctx)
	if got := a.env.ProximoEnvio().Sub(a.rel.Agora()); got != time.Second {
		t.Fatalf("o backoff devia recomeçar em 1 s: %v", got)
	}
}

func TestBuscarBundle(t *testing.T) {
	a := novo(t)
	cli := &Cliente{Base: a.c.URL(), Token: "segredo"}
	ctx := context.Background()

	// Sem bundle na central: 503, e o erro diz o status (quem chamou decide se usa o que tem em disco).
	_, err := cli.BuscarBundle(ctx)
	var el *ErroDeLote
	if !errors.As(err, &el) || el.Status != http.StatusServiceUnavailable {
		t.Fatalf("esperava 503: %v", err)
	}

	// Credencial errada: 401, e nada do corpo vaza como bundle.
	a.c.DefinirBundle([]byte(`{"ok":true}`))
	_, err = (&Cliente{Base: a.c.URL(), Token: "errado"}).BuscarBundle(ctx)
	if !errors.As(err, &el) || el.Status != http.StatusUnauthorized {
		t.Fatalf("esperava 401: %v", err)
	}

	// 200: devolve os bytes, sem interpretar.
	got, err := cli.BuscarBundle(ctx)
	if err != nil || string(got) != `{"ok":true}` {
		t.Fatalf("%q %v", got, err)
	}

	// Acima de 4 MiB: erro, em vez de ler sem limite.
	a.c.DefinirBundle([]byte(strings.Repeat("x", 5<<20)))
	if _, err := cli.BuscarBundle(ctx); err == nil {
		t.Fatal("um bundle de 5 MiB devia ser recusado")
	}
}
