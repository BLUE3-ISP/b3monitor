// b3mon-lab-target é o ALVO do laboratório (B8; L-02): um servidor HTTP que se identifica como `lab` em TODA resposta e em todo log, com
// três modos que se alternam por um endpoint de controle local:
//
//	ok     /health responde 200
//	falha  /health responde 503
//	lento  /health demora mais que o timeout da sonda e depois responde 200 (a sonda mede `target_timeout`)
//
//	b3mon-lab-target -listen 127.0.0.1:18080 -control 127.0.0.1:18081
//	curl -X POST http://127.0.0.1:18081/modo/falha
//
// Os dois endereços TÊM de ser de loopback: um alvo de laboratório com um endpoint que derruba a "produção" de mentira não pode escutar
// numa interface alcançável. Isto não é um alvo real e nada que passe contra ele homologa um upstream.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"sync/atomic"
	"syscall"
	"time"
)

const (
	ok    = "ok"
	falha = "falha"
	lento = "lento"
)

// Marca de laboratório, em todo cabeçalho de resposta.
const (
	cabecalhoLab = "X-B3mon-Lab"
	servidor     = "b3mon-lab-target"
)

// Alvo é o estado do alvo: o modo atual e quanto o modo `lento` demora.
type Alvo struct {
	modo  atomic.Value // string
	Lento time.Duration
	Log   *log.Logger
}

// NovoAlvo cria o alvo no modo `ok`.
func NovoAlvo(lento time.Duration, l *log.Logger) *Alvo {
	a := &Alvo{Lento: lento, Log: l}
	a.modo.Store(ok)
	return a
}

// Modo atual.
func (a *Alvo) Modo() string { return a.modo.Load().(string) }

func marcar(w http.ResponseWriter) {
	w.Header().Set(cabecalhoLab, "lab")
	w.Header().Set("Server", servidor)
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
}

func corpo(w http.ResponseWriter, status int, modo string, extra map[string]any) {
	marcar(w)
	w.WriteHeader(status)
	m := map[string]any{"lab": true, "service": servidor, "mode": modo}
	for k, v := range extra {
		m[k] = v
	}
	json.NewEncoder(w).Encode(m)
}

func (a *Alvo) log(r *http.Request, status int, modo string) {
	if a.Log != nil {
		a.Log.Printf("[lab-target] %s %s -> %d (modo=%s)", r.Method, r.URL.Path, status, modo)
	}
}

// TratadorAlvo é o que a sonda mede.
func (a *Alvo) TratadorAlvo() http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		modo := a.Modo()
		if r.URL.Path != "/health" {
			corpo(w, http.StatusNotFound, modo, map[string]any{"error": "not_found"})
			a.log(r, 404, modo)
			return
		}
		switch modo {
		case falha:
			corpo(w, http.StatusServiceUnavailable, modo, map[string]any{"error": "lab_failure_mode"})
			a.log(r, 503, modo)
		case lento:
			select {
			case <-time.After(a.Lento):
			case <-r.Context().Done():
				a.log(r, 0, modo+" (cliente desistiu)")
				return
			}
			corpo(w, http.StatusOK, modo, nil)
			a.log(r, 200, modo)
		default:
			corpo(w, http.StatusOK, modo, nil)
			a.log(r, 200, modo)
		}
	})
}

// TratadorControle alterna o modo: `GET /modo` lê, `POST /modo/{ok|falha|lento}` troca.
func (a *Alvo) TratadorControle() http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/modo" && r.Method == http.MethodGet:
			corpo(w, http.StatusOK, a.Modo(), nil)
		case strings.HasPrefix(r.URL.Path, "/modo/") && r.Method == http.MethodPost:
			novo := strings.TrimPrefix(r.URL.Path, "/modo/")
			if novo != ok && novo != falha && novo != lento {
				corpo(w, http.StatusBadRequest, a.Modo(), map[string]any{"error": "modo_invalido", "validos": []string{ok, falha, lento}})
				return
			}
			antes := a.Modo()
			a.modo.Store(novo)
			if a.Log != nil {
				a.Log.Printf("[lab-target] modo %s -> %s", antes, novo)
			}
			corpo(w, http.StatusOK, novo, map[string]any{"anterior": antes})
		default:
			corpo(w, http.StatusNotFound, a.Modo(), map[string]any{"error": "not_found"})
		}
	})
}

// SoLoopback recusa um endereço de escuta que não seja de loopback.
func SoLoopback(endereco string) error {
	host, _, err := net.SplitHostPort(endereco)
	if err != nil {
		return err
	}
	ip := net.ParseIP(host)
	if ip == nil || !ip.IsLoopback() {
		return fmt.Errorf("o alvo de laboratório só escuta em loopback; %q não é", endereco)
	}
	return nil
}

func main() {
	os.Exit(executar(os.Args[1:]))
}

func executar(args []string) int {
	fs := flag.NewFlagSet("b3mon-lab-target", flag.ContinueOnError)
	escuta := fs.String("listen", "127.0.0.1:18080", "endereço do alvo (loopback)")
	controle := fs.String("control", "127.0.0.1:18081", "endereço do controle (loopback)")
	lentoD := fs.Duration("slow", 3*time.Second, "quanto o modo lento demora")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	for _, e := range []string{*escuta, *controle} {
		if err := SoLoopback(e); err != nil {
			fmt.Fprintln(os.Stderr, "erro:", err)
			return 2
		}
	}
	logger := log.New(os.Stderr, "", log.LstdFlags|log.LUTC)
	alvo := NovoAlvo(*lentoD, logger)

	srvAlvo := &http.Server{Addr: *escuta, Handler: alvo.TratadorAlvo(), ReadHeaderTimeout: 5 * time.Second}
	srvCtl := &http.Server{Addr: *controle, Handler: alvo.TratadorControle(), ReadHeaderTimeout: 5 * time.Second}
	erros := make(chan error, 2)
	for _, s := range []*http.Server{srvAlvo, srvCtl} {
		l, err := net.Listen("tcp", s.Addr)
		if err != nil {
			fmt.Fprintln(os.Stderr, "erro:", err)
			return 1
		}
		go func(s *http.Server) { erros <- s.Serve(l) }(s)
	}
	logger.Printf("[lab-target] no ar: alvo %s, controle %s (LABORATÓRIO)", *escuta, *controle)

	ctx, cancela := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer cancela()
	select {
	case <-ctx.Done():
	case err := <-erros:
		if !errors.Is(err, http.ErrServerClosed) {
			fmt.Fprintln(os.Stderr, "erro:", err)
			return 1
		}
	}
	fim, c2 := context.WithTimeout(context.Background(), 3*time.Second)
	defer c2()
	srvAlvo.Shutdown(fim)
	srvCtl.Shutdown(fim)
	return 0
}
