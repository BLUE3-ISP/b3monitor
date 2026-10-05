package spool

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/relogio"
)

var t0 = time.Date(2026, 9, 28, 15, 0, 0, 0, time.UTC)

type amb struct {
	s   *Spool
	r   *relogio.Falso
	dir string
}

func novo(t *testing.T, l Limites) *amb {
	t.Helper()
	dir := t.TempDir()
	r := relogio.NovoFalso(t0)
	s, err := Abrir(filepath.Join(dir, "spool.db"), l, r)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { s.Fechar() })
	return &amb{s: s, r: r, dir: dir}
}

func item(n int) ItemAgenda {
	return ItemAgenda{AssignmentID: "a1", Geracao: 3, Slot: t0.Add(time.Duration(n) * 30 * time.Second), ResultID: fmt.Sprintf("r-%03d", n)}
}

// enfileira planeja e finaliza o slot n com um envelope do tamanho dado.
func (a *amb) enfileira(t *testing.T, n, tamanho int) ItemAgenda {
	t.Helper()
	it := item(n)
	ctx := context.Background()
	if _, _, err := a.s.Planejar(ctx, it); err != nil {
		t.Fatal(err)
	}
	if err := a.s.Finalizar(ctx, it, Concluido, []byte(strings.Repeat("x", tamanho))); err != nil {
		t.Fatal(err)
	}
	return it
}

func TestPlanejarEIdempotenteEMantemOResultID(t *testing.T) {
	a := novo(t, Limites{})
	ctx := context.Background()
	id, novo, err := a.s.Planejar(ctx, item(1))
	if err != nil || !novo || id != "r-001" {
		t.Fatalf("1ª vez: %q %v %v", id, novo, err)
	}
	// Reinício: o MESMO slot, com outro result_id candidato, devolve o original (identidade estável).
	outro := item(1)
	outro.ResultID = "r-OUTRO"
	id, novo, err = a.s.Planejar(ctx, outro)
	if err != nil || novo || id != "r-001" {
		t.Fatalf("2ª vez devia devolver o result_id original: %q %v %v", id, novo, err)
	}
}

func TestFinalizarGuardaNaFilaEMarcaOSlotNaMesmaTransacao(t *testing.T) {
	a := novo(t, Limites{})
	ctx := context.Background()
	it := item(1)
	a.s.Planejar(ctx, it)
	if err := a.s.Finalizar(ctx, it, Concluido, []byte(`{"x":1}`)); err != nil {
		t.Fatal(err)
	}
	inc, _ := a.s.Incompletos(ctx)
	if len(inc) != 0 {
		t.Fatalf("o slot finalizado não é incompleto: %+v", inc)
	}
	prox, _ := a.s.Proximos(ctx, 10, 1<<20)
	if len(prox) != 1 || prox[0].ResultID != "r-001" || len(prox[0].Hash) != 64 {
		t.Fatalf("fila: %+v", prox)
	}
}

func TestResultadoFinalizadoEImutavel(t *testing.T) {
	a := novo(t, Limites{})
	ctx := context.Background()
	it := a.enfileira(t, 1, 10)
	if err := a.s.Finalizar(ctx, it, Concluido, []byte("outro conteúdo")); err == nil {
		t.Fatal("finalizar de novo o mesmo slot devia ser erro")
	}
	prox, _ := a.s.Proximos(ctx, 10, 1<<20)
	if len(prox) != 1 || string(prox[0].Envelope) != strings.Repeat("x", 10) {
		t.Fatalf("o resultado original foi reescrito: %+v", prox)
	}
}

func TestFinalizarSlotNuncaPlanejadoFalha(t *testing.T) {
	a := novo(t, Limites{})
	if err := a.s.Finalizar(context.Background(), item(9), Concluido, []byte("x")); err == nil {
		t.Fatal("finalizar sem planejar devia falhar")
	}
	if e, _ := a.s.Estatisticas(context.Background()); e.Linhas != 0 {
		t.Fatalf("ficou lixo na fila: %+v", e)
	}
}

func TestIncompletosSaoOQueOReinicioReconcilia(t *testing.T) {
	a := novo(t, Limites{})
	ctx := context.Background()
	a.s.Planejar(ctx, item(1)) // planejado
	a.s.Planejar(ctx, item(2)) // executando
	a.s.Iniciar(ctx, item(2))
	a.enfileira(t, 3, 5) // concluído
	inc, _ := a.s.Incompletos(ctx)
	if len(inc) != 2 || inc[0].Estado != Planejado || inc[1].Estado != Executando {
		t.Fatalf("incompletos: %+v", inc)
	}
	// Iniciar só vale uma vez.
	if ok, _ := a.s.Iniciar(ctx, item(2)); ok {
		t.Fatal("Iniciar de um slot já em execução devia devolver falso")
	}
}

func TestConfirmarPorItem(t *testing.T) {
	a := novo(t, Limites{})
	ctx := context.Background()
	for i := 1; i <= 6; i++ {
		a.enfileira(t, i, 10)
	}
	depois := t0.Add(time.Minute)
	casos := []struct{ id, status string }{
		{"r-001", Accepted}, {"r-002", Duplicate}, {"r-003", Retryable}, {"r-004", RejectedTerminal}, {"r-005", Conflict},
	}
	for _, c := range casos {
		if err := a.s.Confirmar(ctx, c.id, c.status, "motivo", depois); err != nil {
			t.Fatalf("%s: %v", c.status, err)
		}
	}
	// Só r-003 (retryable) e r-006 (sem resposta) continuam na fila; r-001/002 saíram; r-004/005 foram para a quarentena.
	e, _ := a.s.Estatisticas(ctx)
	if e.Linhas != 2 || e.Quarentena != 2 {
		t.Fatalf("fila=%d quarentena=%d", e.Linhas, e.Quarentena)
	}
	// r-003 só volta depois da próxima tentativa.
	prox, _ := a.s.Proximos(ctx, 10, 1<<20)
	if len(prox) != 1 || prox[0].ResultID != "r-006" {
		t.Fatalf("antes da hora só o r-006 está pronto: %+v", prox)
	}
	a.r.Avancar(2 * time.Minute)
	prox, _ = a.s.Proximos(ctx, 10, 1<<20)
	if len(prox) != 2 {
		t.Fatalf("depois da hora o r-003 volta: %+v", prox)
	}
	for _, p := range prox {
		if p.ResultID == "r-003" && p.Tentativas != 1 {
			t.Fatalf("tentativas = %d", p.Tentativas)
		}
	}
}

func TestStatusDesconhecidoNaoApagaNada(t *testing.T) {
	a := novo(t, Limites{})
	a.enfileira(t, 1, 10)
	if err := a.s.Confirmar(context.Background(), "r-001", "tudo_certo", "", t0); err == nil {
		t.Fatal("status desconhecido devia ser erro")
	}
	if e, _ := a.s.Estatisticas(context.Background()); e.Linhas != 1 {
		t.Fatal("um status desconhecido apagou o resultado")
	}
}

func TestProximosOsMaisRecentesPrimeiroELimitePorBytes(t *testing.T) {
	a := novo(t, Limites{})
	ctx := context.Background()
	for i := 1; i <= 5; i++ {
		a.enfileira(t, i, 100)
	}
	prox, _ := a.s.Proximos(ctx, 10, 250)
	if len(prox) != 2 || prox[0].ResultID != "r-005" || prox[1].ResultID != "r-004" {
		t.Fatalf("esperava os 2 mais recentes (200 bytes ≤ 250): %+v", prox)
	}
	// Ao menos um item sai, mesmo maior que o limite.
	prox, _ = a.s.Proximos(ctx, 10, 10)
	if len(prox) != 1 {
		t.Fatalf("devia sair 1 item mesmo acima do limite: %d", len(prox))
	}
	prox, _ = a.s.Proximos(ctx, 3, 1<<20)
	if len(prox) != 3 {
		t.Fatalf("limite de itens: %d", len(prox))
	}
}

func TestLimiteTemporalDescartaORegistraEManteOCorrente(t *testing.T) {
	a := novo(t, Limites{Retencao: 2 * time.Hour})
	ctx := context.Background()
	a.enfileira(t, 1, 10)
	a.enfileira(t, 2, 10)
	a.r.Avancar(2*time.Hour + time.Minute)
	a.enfileira(t, 3, 10) // o item corrente entra; ao entrar, o velho sai

	prox, _ := a.s.Proximos(ctx, 10, 1<<20)
	if len(prox) != 1 || prox[0].ResultID != "r-003" {
		t.Fatalf("só o corrente devia ficar: %+v", prox)
	}
	d, _ := a.s.Descartes(ctx)
	if len(d) != 1 || d[0].Quantidade != 2 || d[0].Motivo != "retencao_temporal" ||
		!d[0].DeSlot.Equal(item(1).Slot) || !d[0].AteSlot.Equal(item(2).Slot) {
		t.Fatalf("o descarte tem de dizer a faixa, a quantidade e o motivo: %+v", d)
	}
}

func TestLimiteDeBytesDescartaOsMaisAntigosPrimeiro(t *testing.T) {
	a := novo(t, Limites{BytesDados: 250})
	ctx := context.Background()
	for i := 1; i <= 4; i++ {
		a.enfileira(t, i, 100)
	}
	prox, _ := a.s.Proximos(ctx, 10, 1<<20)
	ids := []string{}
	for _, p := range prox {
		ids = append(ids, p.ResultID)
	}
	if len(ids) != 2 || ids[0] != "r-004" || ids[1] != "r-003" {
		t.Fatalf("ficam os 2 mais novos (200 ≤ 250): %v", ids)
	}
	var total int64
	d, _ := a.s.Descartes(ctx)
	for _, x := range d {
		total += int64(x.Quantidade)
		if x.Motivo != "limite_de_bytes" {
			t.Fatalf("motivo: %+v", x)
		}
	}
	if total != 2 {
		t.Fatalf("descartes registrados = %d, esperava 2", total)
	}
	e, _ := a.s.Estatisticas(ctx)
	if e.Descartados != 2 || e.Bytes != 200 {
		t.Fatalf("estatísticas: %+v", e)
	}
}

func TestQuarentenaTemLimiteEORegistra(t *testing.T) {
	a := novo(t, Limites{BytesReserva: 250})
	ctx := context.Background()
	for i := 1; i <= 4; i++ {
		a.enfileira(t, i, 100)
		a.r.Avancar(time.Second)
		if err := a.s.Confirmar(ctx, fmt.Sprintf("r-%03d", i), RejectedTerminal, "recusado", t0); err != nil {
			t.Fatal(err)
		}
	}
	e, _ := a.s.Estatisticas(ctx)
	if e.Quarentena != 2 {
		t.Fatalf("a reserva de 250 bytes comporta 2 itens de 100: %d", e.Quarentena)
	}
	d, _ := a.s.Descartes(ctx)
	if len(d) != 2 || d[0].Motivo != "quarentena_cheia" {
		t.Fatalf("a saída da quarentena tem de ficar registrada: %+v", d)
	}
}

func TestIdadeEDescartesNoHeartbeat(t *testing.T) {
	a := novo(t, Limites{})
	ctx := context.Background()
	e, _ := a.s.Estatisticas(ctx)
	if e.IdadeMaisAntigaS != nil {
		t.Fatal("spool vazio: idade é nula, não zero")
	}
	a.enfileira(t, 1, 10)
	a.r.Avancar(90 * time.Second)
	e, _ = a.s.Estatisticas(ctx)
	if e.IdadeMaisAntigaS == nil || *e.IdadeMaisAntigaS != 90 {
		t.Fatalf("idade: %v", e.IdadeMaisAntigaS)
	}
}

func TestLacunaFicaRegistrada(t *testing.T) {
	a := novo(t, Limites{})
	ctx := context.Background()
	a.s.RegistrarLacuna(ctx, "a1", 3, t0, t0.Add(time.Hour), 120)
	a.s.RegistrarLacuna(ctx, "a1", 3, t0.Add(2*time.Hour), t0.Add(3*time.Hour), 120)
	if n, _ := a.s.LacunasTotal(ctx); n != 240 {
		t.Fatalf("lacunas = %d", n)
	}
}

// ---- Queda de verdade: um processo escreve e é MORTO sem fechar o arquivo; outro reabre e acha tudo ----

func TestMain(m *testing.M) {
	if caminho := os.Getenv("SPOOL_FILHO"); caminho != "" {
		filhoQueEscreveEMorre(caminho)
		return
	}
	os.Exit(m.Run())
}

// filhoQueEscreveEMorre planeja 3 slots, finaliza 2 e deixa o terceiro EXECUTANDO; depois se mata com SIGKILL, sem Fechar.
func filhoQueEscreveEMorre(caminho string) {
	r := relogio.NovoFalso(t0)
	s, err := Abrir(caminho, Limites{}, r)
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(2)
	}
	ctx := context.Background()
	for n := 1; n <= 3; n++ {
		it := item(n)
		s.Planejar(ctx, it)
		if n < 3 {
			if err := s.Finalizar(ctx, it, Concluido, []byte(fmt.Sprintf(`{"n":%d}`, n))); err != nil {
				fmt.Fprintln(os.Stderr, err)
				os.Exit(2)
			}
		} else {
			s.Iniciar(ctx, it)
		}
	}
	fmt.Println("pronto")
	p, _ := os.FindProcess(os.Getpid())
	p.Signal(os.Kill)
	select {}
}

func TestQuedaDoProcessoNaoPerdeOQueFoiConfirmadoAoCoordenador(t *testing.T) {
	caminho := filepath.Join(t.TempDir(), "spool.db")
	cmd := exec.Command(os.Args[0], "-test.run=NaoExiste")
	cmd.Env = append(os.Environ(), "SPOOL_FILHO="+caminho)
	saida, _ := cmd.CombinedOutput() // o filho morre por SIGKILL: erro esperado
	if !strings.Contains(string(saida), "pronto") {
		t.Fatalf("o filho não chegou ao fim do trabalho: %s", saida)
	}

	s, err := Abrir(caminho, Limites{}, relogio.NovoFalso(t0))
	if err != nil {
		t.Fatalf("reabrir depois da queda: %v", err)
	}
	defer s.Fechar()
	ctx := context.Background()

	prox, _ := s.Proximos(ctx, 10, 1<<20)
	if len(prox) != 2 {
		t.Fatalf("os 2 resultados finalizados tinham de sobreviver: %+v", prox)
	}
	inc, _ := s.Incompletos(ctx)
	if len(inc) != 1 || inc[0].ResultID != "r-003" || inc[0].Estado != Executando {
		t.Fatalf("o slot que estava executando tem de aparecer para reconciliação, com a identidade original: %+v", inc)
	}
	if id, novo, _ := s.Planejar(ctx, item(3)); novo || id != "r-003" {
		t.Fatalf("o slot planejado antes da queda mantém o result_id: %q %v", id, novo)
	}
}

// ---- Falta de disco: o erro aparece, nada fica pela metade, e a sonda se recupera quando há espaço ----

func TestFaltaDeDiscoFalhaLimpoENaoCorrompe(t *testing.T) {
	a := novo(t, Limites{})
	ctx := context.Background()
	a.enfileira(t, 1, 100)

	// Simula o disco cheio: o SQLite recusa crescer além de N páginas.
	var paginas int
	a.s.db.QueryRow(`PRAGMA page_count`).Scan(&paginas)
	if _, err := a.s.db.Exec(fmt.Sprintf(`PRAGMA max_page_count=%d`, paginas+1)); err != nil {
		t.Fatal(err)
	}

	it := item(2)
	a.s.Planejar(ctx, it)
	err := a.s.Finalizar(ctx, it, Concluido, []byte(strings.Repeat("y", 1<<20)))
	if err == nil {
		t.Fatal("sem espaço, Finalizar devia FALHAR (e não fingir que guardou)")
	}
	// Nada ficou pela metade: o slot continua incompleto (reconciliável) e a fila só tem o 1.
	inc, _ := a.s.Incompletos(ctx)
	if len(inc) != 1 || inc[0].ResultID != "r-002" {
		t.Fatalf("o slot que falhou tem de continuar incompleto: %+v", inc)
	}
	if e, _ := a.s.Estatisticas(ctx); e.Linhas != 1 {
		t.Fatalf("o resultado que não coube não pode estar na fila: %+v", e)
	}

	// O disco volta: o mesmo slot finaliza normalmente.
	a.s.db.Exec(`PRAGMA max_page_count=1073741823`)
	if err := a.s.Finalizar(ctx, it, Concluido, []byte("agora cabe")); err != nil {
		t.Fatalf("com espaço de novo devia funcionar: %v", err)
	}
	if e, _ := a.s.Estatisticas(ctx); e.Linhas != 2 {
		t.Fatalf("depois de liberar: %+v", e)
	}
}

// Depois que a central confirmou (o item saiu da fila), finalizar o MESMO slot de novo NÃO pode reintroduzir um resultado já entregue.
func TestFinalizadoEConfirmadoNaoRenasce(t *testing.T) {
	a := novo(t, Limites{})
	ctx := context.Background()
	it := a.enfileira(t, 1, 10)
	if err := a.s.Confirmar(ctx, it.ResultID, Accepted, "", t0); err != nil {
		t.Fatal(err)
	}
	if err := a.s.Finalizar(ctx, it, Concluido, []byte("de novo")); err == nil {
		t.Fatal("um slot já finalizado e entregue não pode ser finalizado de novo")
	}
	if e, _ := a.s.Estatisticas(ctx); e.Linhas != 0 {
		t.Fatalf("o resultado entregue voltou para a fila: %+v", e)
	}
}
