// b3mon-probe é a sonda do b3monitor: o coordenador (política local, agenda, spool, envio) e, opcionalmente, o executor.
//
//	b3mon-probe run      -config /etc/b3mon-probe/probe.json
//	b3mon-probe executor -policy /etc/b3mon-probe/politica.json -socket /run/b3mon-probe/executor.sock
//	b3mon-probe version
//
// `run` termina limpo em SIGTERM/SIGINT (fecha o spool). `executor` escuta SÓ num socket Unix (modo 0600), nunca em TCP nem UDP.
package main

import (
	"context"
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"syscall"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/executor"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/politica"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/processo"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/relogio"
)

// Preenchidos na compilação (-ldflags "-X main.versao=… -X main.commit=…").
var (
	versao = "0.0.0-dev"
	commit = "unknown"
)

func main() {
	os.Exit(executar(os.Args[1:]))
}

func executar(args []string) int {
	if len(args) == 0 {
		fmt.Fprintln(os.Stderr, "uso: b3mon-probe run|executor|version [opções]")
		return 2
	}
	switch args[0] {
	case "version":
		fmt.Printf("b3mon-probe %s (%s)\n", versao, commit)
		return 0
	case "run":
		return rodar(args[1:])
	case "executor":
		return servirExecutor(args[1:])
	default:
		fmt.Fprintf(os.Stderr, "comando desconhecido: %q\n", args[0])
		return 2
	}
}

func rodar(args []string) int {
	fs := flag.NewFlagSet("run", flag.ContinueOnError)
	caminho := fs.String("config", "", "arquivo de configuração local (JSON)")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	if *caminho == "" {
		fmt.Fprintln(os.Stderr, "run: -config é obrigatório")
		return 2
	}
	cfg, err := processo.CarregarConfig(*caminho)
	if err != nil {
		fmt.Fprintln(os.Stderr, "erro:", err)
		return 1
	}
	ctx, cancela := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer cancela()

	logger := log.New(os.Stderr, "b3mon-probe ", log.LstdFlags|log.LUTC)
	p := &processo.Processo{Cfg: cfg, Versao: versao, Commit: commit, Logf: logger.Printf}
	logger.Printf("iniciando: sonda %s, central %s", cfg.ProbeID, cfg.CentralURL)
	if err := p.Rodar(ctx); err != nil {
		logger.Printf("erro fatal: %v", err)
		return 1
	}
	return 0
}

func servirExecutor(args []string) int {
	fs := flag.NewFlagSet("executor", flag.ContinueOnError)
	pol := fs.String("policy", "", "arquivo da política local")
	socket := fs.String("socket", "", "caminho do socket Unix")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	if *pol == "" || *socket == "" {
		fmt.Fprintln(os.Stderr, "executor: -policy e -socket são obrigatórios")
		return 2
	}
	p, err := politica.Carregar(*pol)
	if err != nil {
		fmt.Fprintln(os.Stderr, "erro:", err)
		return 1
	}
	l, err := executor.Ouvir(*socket)
	if err != nil {
		fmt.Fprintln(os.Stderr, "erro:", err)
		return 1
	}
	ctx, cancela := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer cancela()
	if err := executor.Servir(ctx, l, &executor.HTTP{Politica: p, Relogio: relogio.Sistema{}}); err != nil {
		fmt.Fprintln(os.Stderr, "erro:", err)
		return 1
	}
	return 0
}
