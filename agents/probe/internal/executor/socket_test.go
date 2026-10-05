package executor

import (
	"context"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/bundle"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/coordenador"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/relogio"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/resultado"
)

func subir(t *testing.T, h *HTTP) (string, *net.UnixListener) {
	t.Helper()
	caminho := filepath.Join(t.TempDir(), "executor.sock")
	l, err := Ouvir(caminho)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancela := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { Servir(ctx, l, h); close(done) }()
	t.Cleanup(func() { cancela(); <-done })
	return caminho, l
}

func TestExecutorNaoEscutaEmTCPNemUDPSoEmSocketUnixComModo0600(t *testing.T) {
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("127.0.0.1", 80)}, "127.0.0.1/32"), Relogio: relogio.Sistema{}}
	caminho, l := subir(t, h)

	if l.Addr().Network() != "unix" {
		t.Fatalf("o executor escuta em %q: só socket Unix é permitido", l.Addr().Network())
	}
	info, err := os.Stat(caminho)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode()&os.ModeSocket == 0 || info.Mode().Perm() != 0o600 {
		t.Fatalf("o socket tem de ser 0600: %v", info.Mode())
	}
}

func TestPeloSocketOCasoLegitimoPassaEONegadoContinuaNegado(t *testing.T) {
	porta, hits := servidor(t, "127.0.0.1", 0, ok200)
	_, hitsB := servidor(t, "127.0.0.2", porta, ok200)
	h := &HTTP{Politica: politicaCom(t, []map[string]any{destino("127.0.0.1", porta)}, "127.0.0.1/32"), Relogio: relogio.Sistema{}}
	caminho, _ := subir(t, h)
	cli := Cliente{Caminho: caminho}

	exec := func(url string) coordenador.Saida {
		return cli.Executar(context.Background(), coordenador.Execucao{
			Assignment: bundle.Assignment{IPFamily: "ipv4"},
			Alvo:       bundle.AlvoHTTP{URL: url, Method: "GET", AllowedStatusCodes: []int{200}},
			Timeout:    3 * time.Second,
		})
	}

	s := exec(fmt.Sprintf("http://127.0.0.1:%d/", porta))
	exigir(t, s, resultado.Pass, resultado.RazaoSucessoHTTP)
	if s.Destino == nil || s.Destino.IP != "127.0.0.1" || hits.Load() != 1 {
		t.Fatalf("destino=%+v hits=%d", s.Destino, hits.Load())
	}

	// A defesa vale do outro lado do socket: o executor não perde a política por estar num processo separado.
	exigir(t, exec(fmt.Sprintf("http://127.0.0.2:%d/", porta)), resultado.PolicyDenied, resultado.RazaoPoliticaNegou)
	if hitsB.Load() != 0 {
		t.Fatal("o pedido negado chegou ao destino proibido")
	}
}

func TestExecutorForaDoArEhExecutorIndisponivelENaoFalhaDoAlvo(t *testing.T) {
	cli := Cliente{Caminho: filepath.Join(t.TempDir(), "nao-existe.sock")}
	s := cli.Executar(context.Background(), coordenador.Execucao{
		Assignment: bundle.Assignment{IPFamily: "ipv4"},
		Alvo:       bundle.AlvoHTTP{URL: "http://x/", Method: "GET", AllowedStatusCodes: []int{200}},
		Timeout:    time.Second,
	})
	exigir(t, s, resultado.ExecutorError, resultado.RazaoExecutorIndisp)
}

func TestOuvirNaoApagaArquivoQueNaoESocket(t *testing.T) {
	caminho := filepath.Join(t.TempDir(), "importante.txt")
	os.WriteFile(caminho, []byte("não apague"), 0o600)
	if _, err := Ouvir(caminho); err == nil {
		t.Fatal("não pode abrir o socket por cima de um arquivo comum")
	}
	if b, _ := os.ReadFile(caminho); string(b) != "não apague" {
		t.Fatal("o arquivo foi apagado")
	}
}

func TestOuvirReaproveitaSocketAntigo(t *testing.T) {
	caminho := filepath.Join(t.TempDir(), "e.sock")
	l1, err := Ouvir(caminho)
	if err != nil {
		t.Fatal(err)
	}
	l1.SetUnlinkOnClose(false)
	l1.Close() // deixa o arquivo de socket para trás, como uma queda faria
	l2, err := Ouvir(caminho)
	if err != nil {
		t.Fatalf("depois de uma queda, o socket velho tem de ser substituído: %v", err)
	}
	l2.Close()
}
