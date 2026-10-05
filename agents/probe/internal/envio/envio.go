// Package envio leva os resultados do spool à central e aplica a resposta POR ITEM (04 §6 e §7; 06 §6).
//
// As regras que moram aqui:
//   - **A resposta é por item.** HTTP 200 do lote não autoriza apagar o lote: só `accepted` e `duplicate` (a central tem o
//     resultado de forma DURÁVEL) tiram um item do spool. Um item que a resposta não cita fica onde está.
//   - **Pelo menos uma vez.** A queda da conexão depois do commit da central deixa a sonda sem saber; ela reenvia, e o
//     reenvio é `duplicate`. Nenhuma tentativa de transporte repete o probe, só o envio do resultado já finalizado.
//   - **Backoff exponencial com jitter e `Retry-After`.** Não se sobrecarrega uma central que acabou de voltar.
//   - **Lote envenenado não trava a fila.** `400`/`403`/`413` recusam o lote inteiro; o lote é partido ao meio até isolar
//     o item culpado, que vai para a quarentena, e o resto segue.
package envio

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/rand"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/relogio"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/spool"
)

// Limites de lote do contrato (06 §6): de 1 a 500 itens; o corpo tem teto de 4 MiB, e a sonda fica bem abaixo.
const (
	MaxItensPorLote = 100
	MaxBytesPorLote = 2 << 20
	gzipAcima       = 1 << 10
)

// RespostaItem é uma entrada da resposta da central.
type RespostaItem struct {
	ResultID   string `json:"result_id"`
	Status     string `json:"status"`
	ReceivedAt string `json:"received_at"`
	Durable    bool   `json:"durable"`
	ReasonCode string `json:"reason_code"`
}

// ErroDeLote é a falha de transporte que veio ANTES de qualquer item ser processado (400, 401, 403, 413, 429, 5xx).
type ErroDeLote struct {
	Status     int
	RetryAfter time.Duration
	Corpo      string
}

func (e *ErroDeLote) Error() string {
	return fmt.Sprintf("a central recusou o lote: HTTP %d %s", e.Status, e.Corpo)
}

// LoteRecusado diz se o erro é uma recusa do CONTEÚDO do lote (partir e isolar adianta), e não do transporte ou da credencial.
func (e *ErroDeLote) LoteRecusado() bool {
	return e.Status == http.StatusBadRequest || e.Status == http.StatusForbidden || e.Status == http.StatusRequestEntityTooLarge
}

// Cliente HTTP da central. A URL base e o token vêm da configuração local; o token nunca vai a log.
type Cliente struct {
	Base  string
	Token string
	HTTP  *http.Client
}

// Enviar manda um lote e devolve a resposta por item. Nenhum item é "confirmado" por este método: quem decide é quem lê a
// resposta.
func (c *Cliente) Enviar(ctx context.Context, itens []spool.ItemFila) (string, []RespostaItem, error) {
	loteID := novoUUID()
	resultados := make([]json.RawMessage, len(itens))
	for i, it := range itens {
		resultados[i] = json.RawMessage(it.Envelope)
	}
	corpo, err := json.Marshal(map[string]any{
		"batch_id": loteID, "mode": "live", "replay_session_id": nil, "results": resultados,
	})
	if err != nil {
		return loteID, nil, err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, strings.TrimRight(c.Base, "/")+"/api/v1/probe/results", nil)
	if err != nil {
		return loteID, nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", "Bearer "+c.Token)
	if len(corpo) > gzipAcima {
		var buf bytes.Buffer
		zw := gzip.NewWriter(&buf)
		zw.Write(corpo)
		zw.Close()
		corpo = buf.Bytes()
		req.Header.Set("Content-Encoding", "gzip")
	}
	req.Body = io.NopCloser(bytes.NewReader(corpo))
	req.ContentLength = int64(len(corpo))

	cli := c.HTTP
	if cli == nil {
		cli = &http.Client{Timeout: 30 * time.Second}
	}
	resp, err := cli.Do(req)
	if err != nil {
		return loteID, nil, fmt.Errorf("transporte: %w", err)
	}
	defer resp.Body.Close()
	dados, _ := io.ReadAll(io.LimitReader(resp.Body, 4<<20))

	if resp.StatusCode != http.StatusOK {
		return loteID, nil, &ErroDeLote{Status: resp.StatusCode, RetryAfter: retryAfter(resp), Corpo: resumo(dados)}
	}
	var corpoResp struct {
		BatchID string         `json:"batch_id"`
		Items   []RespostaItem `json:"items"`
	}
	if err := json.Unmarshal(dados, &corpoResp); err != nil {
		return loteID, nil, fmt.Errorf("transporte: resposta ilegível: %w", err)
	}
	return loteID, corpoResp.Items, nil
}

func retryAfter(r *http.Response) time.Duration {
	v := r.Header.Get("Retry-After")
	if v == "" {
		return 0
	}
	if s, err := strconv.Atoi(v); err == nil && s >= 0 {
		return time.Duration(s) * time.Second
	}
	if t, err := http.ParseTime(v); err == nil {
		if d := time.Until(t); d > 0 {
			return d
		}
	}
	return 0
}

func resumo(b []byte) string {
	s := strings.TrimSpace(string(b))
	if len(s) > 200 {
		s = s[:200]
	}
	return s
}

// Enviador do spool. Guarda o estado do backoff.
type Enviador struct {
	Spool   *spool.Spool
	Cliente interface {
		Enviar(ctx context.Context, itens []spool.ItemFila) (string, []RespostaItem, error)
	}
	Relogio relogio.Relogio
	// Aleatorio devolve um número em [0,1) para o jitter; injetável para o teste ser determinístico.
	Aleatorio func() float64

	Base time.Duration // primeira espera (padrão 1 s)
	Teto time.Duration // espera máxima (padrão 5 min)

	falhas  int
	proximo time.Time
}

// Resultado de um ciclo.
type Resultado struct {
	Confirmados int // accepted + duplicate
	Quarentena  int
	Reagendados int // retryable
	Sem         int // itens que a resposta não citou (continuam na fila)
}

func (e *Enviador) base() time.Duration {
	if e.Base > 0 {
		return e.Base
	}
	return time.Second
}

func (e *Enviador) teto() time.Duration {
	if e.Teto > 0 {
		return e.Teto
	}
	return 5 * time.Minute
}

// Espera exponencial `base × 2^(n-1)` limitada pelo teto, com jitter de ±20%.
func (e *Enviador) espera(n int) time.Duration {
	if n < 1 {
		n = 1
	}
	d := float64(e.base()) * math.Pow(2, float64(n-1))
	if d > float64(e.teto()) {
		d = float64(e.teto())
	}
	r := 0.5
	if e.Aleatorio != nil {
		r = e.Aleatorio()
	}
	return time.Duration(d * (0.8 + 0.4*r))
}

// ProximoEnvio é o instante antes do qual o enviador não tenta de novo (zero = já).
func (e *Enviador) ProximoEnvio() time.Time { return e.proximo }

// Falhas consecutivas de transporte.
func (e *Enviador) Falhas() int { return e.falhas }

// Ciclo envia UM lote, se houver item pronto e o backoff permitir.
func (e *Enviador) Ciclo(ctx context.Context) (Resultado, error) {
	var res Resultado
	agora := e.Relogio.Agora()
	if agora.Before(e.proximo) {
		return res, nil
	}
	itens, err := e.Spool.Proximos(ctx, MaxItensPorLote, MaxBytesPorLote)
	if err != nil || len(itens) == 0 {
		return res, err
	}
	if err := e.enviarLote(ctx, itens, &res); err != nil {
		return res, err
	}
	return res, nil
}

func (e *Enviador) enviarLote(ctx context.Context, itens []spool.ItemFila, res *Resultado) error {
	_, resp, err := e.Cliente.Enviar(ctx, itens)
	if err != nil {
		var el *ErroDeLote
		if errors.As(err, &el) && el.LoteRecusado() {
			return e.isolar(ctx, itens, el, res)
		}
		// Rede, 401, 429, 5xx: o problema não é de um item. Recua e tenta de novo depois, sem mexer em item nenhum.
		e.falhas++
		espera := e.espera(e.falhas)
		if errors.As(err, &el) && el.RetryAfter > espera {
			espera = el.RetryAfter
		}
		e.proximo = e.Relogio.Agora().Add(espera)
		return err
	}
	e.falhas = 0
	e.proximo = time.Time{}
	return e.aplicar(ctx, itens, resp, res)
}

// aplicar confirma item por item. Um item que a resposta não cita NÃO é tocado.
func (e *Enviador) aplicar(ctx context.Context, itens []spool.ItemFila, resp []RespostaItem, res *Resultado) error {
	porID := make(map[string]RespostaItem, len(resp))
	for _, r := range resp {
		porID[r.ResultID] = r
	}
	for _, it := range itens {
		r, ok := porID[it.ResultID]
		if !ok {
			res.Sem++
			continue
		}
		motivo := r.ReasonCode
		if r.Status == spool.Accepted || r.Status == spool.Duplicate {
			// `accepted` e `duplicate` só valem com commit durável na central (06 §3). Sem `durable`, não se apaga.
			if !r.Durable {
				res.Sem++
				continue
			}
		}
		proxima := e.Relogio.Agora().Add(e.espera(it.Tentativas + 1))
		if err := e.Spool.Confirmar(ctx, it.ResultID, r.Status, motivo, proxima); err != nil {
			return err
		}
		switch r.Status {
		case spool.Accepted, spool.Duplicate:
			res.Confirmados++
		case spool.Retryable:
			res.Reagendados++
		case spool.RejectedTerminal, spool.Conflict:
			res.Quarentena++
		}
	}
	return nil
}

// isolar parte o lote recusado ao meio até achar o item culpado. Um item sozinho que a central recusa vai para a quarentena.
func (e *Enviador) isolar(ctx context.Context, itens []spool.ItemFila, el *ErroDeLote, res *Resultado) error {
	if len(itens) == 1 {
		motivo := fmt.Sprintf("a central recusou o item sozinho: HTTP %d", el.Status)
		if err := e.Spool.Confirmar(ctx, itens[0].ResultID, spool.RejectedTerminal, motivo, e.Relogio.Agora()); err != nil {
			return err
		}
		res.Quarentena++
		return nil
	}
	meio := len(itens) / 2
	for _, parte := range [][]spool.ItemFila{itens[:meio], itens[meio:]} {
		if err := e.enviarLote(ctx, parte, res); err != nil {
			return err
		}
	}
	return nil
}

func novoUUID() string {
	var b [16]byte
	rand.Read(b[:])
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80
	return fmt.Sprintf("%08x-%04x-%04x-%04x-%012x", binary.BigEndian.Uint32(b[0:4]), binary.BigEndian.Uint16(b[4:6]),
		binary.BigEndian.Uint16(b[6:8]), binary.BigEndian.Uint16(b[8:10]), b[10:])
}

// EnviarHeartbeat manda o autodiagnóstico (`POST /api/v1/probe/heartbeat`). É um sinal de saúde, não um resultado: não passa pelo
// spool, e uma falha aqui não bloqueia nada. O corpo é o que o coordenador montou; este método só o transporta.
func (c *Cliente) EnviarHeartbeat(ctx context.Context, corpo any) error {
	dados, err := json.Marshal(corpo)
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, strings.TrimRight(c.Base, "/")+"/api/v1/probe/heartbeat", bytes.NewReader(dados))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", "Bearer "+c.Token)
	cli := c.HTTP
	if cli == nil {
		cli = &http.Client{Timeout: 30 * time.Second}
	}
	resp, err := cli.Do(req)
	if err != nil {
		return fmt.Errorf("transporte: %w", err)
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<16))
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return &ErroDeLote{Status: resp.StatusCode, RetryAfter: retryAfter(resp), Corpo: resumo(b)}
	}
	return nil
}
