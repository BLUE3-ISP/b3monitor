package executor

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/coordenador"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/resultado"
)

// O executor NÃO ESCUTA em porta TCP nem UDP (02 §6; 07). Ele fala com o coordenador por um socket Unix de modo 0600, num
// diretório do próprio usuário do serviço. Isso é o que permite um executor por namespace de rede (`b3mon-executor@<ns>`) sem
// abrir uma superfície de rede na sonda: quem alcança o socket é quem já é o dono do processo.
//
// O protocolo é uma requisição JSON por linha e uma resposta JSON por linha, com teto de tamanho.

const maxMensagem = 1 << 20

// Ouvir abre o socket Unix com modo 0600. Um arquivo antigo no caminho só é removido se for um SOCKET (nunca um arquivo qualquer).
func Ouvir(caminho string) (*net.UnixListener, error) {
	if info, err := os.Lstat(caminho); err == nil {
		if info.Mode()&os.ModeSocket == 0 {
			return nil, fmt.Errorf("executor: %s existe e não é um socket: não será apagado", caminho)
		}
		os.Remove(caminho)
	}
	l, err := net.ListenUnix("unix", &net.UnixAddr{Name: caminho, Net: "unix"})
	if err != nil {
		return nil, err
	}
	if err := os.Chmod(caminho, 0o600); err != nil {
		l.Close()
		return nil, err
	}
	return l, nil
}

// Servir atende o coordenador no socket até o contexto acabar.
func Servir(ctx context.Context, l *net.UnixListener, ex coordenador.Executor) error {
	go func() {
		<-ctx.Done()
		l.Close()
	}()
	for {
		conn, err := l.AcceptUnix()
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			return err
		}
		go atender(ctx, conn, ex)
	}
}

func atender(ctx context.Context, conn net.Conn, ex coordenador.Executor) {
	defer conn.Close()
	conn.SetDeadline(time.Now().Add(2 * time.Minute))
	linha, err := bufio.NewReaderSize(io.LimitReader(conn, maxMensagem), 64<<10).ReadBytes('\n')
	if err != nil {
		return
	}
	var e coordenador.Execucao
	if err := json.Unmarshal(linha, &e); err != nil {
		json.NewEncoder(conn).Encode(coordenador.Saida{Outcome: resultado.ConfigError, Reason: resultado.RazaoPoliticaNegou})
		return
	}
	ctxE, cancela := context.WithTimeout(ctx, e.Timeout)
	defer cancela()
	json.NewEncoder(conn).Encode(ex.Executar(ctxE, e))
}

// Cliente é o executor do ponto de vista do coordenador: cada Executar vira uma ida e volta no socket.
type Cliente struct{ Caminho string }

var _ coordenador.Executor = Cliente{}

// Executar envia o pedido ao executor. Se o socket não responde, o resultado é `executor_unavailable`: a sonda não finge que
// mediu, e não confunde "o executor caiu" com "o alvo caiu".
func (c Cliente) Executar(ctx context.Context, e coordenador.Execucao) coordenador.Saida {
	agora := time.Now().UTC()
	indisponivel := func(err error) coordenador.Saida {
		return coordenador.Saida{Outcome: resultado.ExecutorError, Reason: resultado.RazaoExecutorIndisp, Inicio: agora, Fim: time.Now().UTC()}
	}
	var d net.Dialer
	conn, err := d.DialContext(ctx, "unix", c.Caminho)
	if err != nil {
		return indisponivel(err)
	}
	defer conn.Close()
	if dl, ok := ctx.Deadline(); ok {
		conn.SetDeadline(dl.Add(5 * time.Second))
	}
	corpo, err := json.Marshal(e)
	if err != nil {
		return indisponivel(err)
	}
	if _, err := conn.Write(append(corpo, '\n')); err != nil {
		return indisponivel(err)
	}
	var s coordenador.Saida
	if err := json.NewDecoder(io.LimitReader(conn, maxMensagem)).Decode(&s); err != nil {
		if errors.Is(err, io.EOF) {
			return indisponivel(err)
		}
		return indisponivel(err)
	}
	return s
}
