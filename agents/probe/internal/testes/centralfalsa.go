// Package testes tem a CENTRAL FALSA que os testes da sonda usam: um servidor HTTP que se comporta como o `POST
// /api/v1/probe/results` da central (contracts/openapi): idempotência global por `result_id` com conteúdo, resposta por
// item, `accepted` só depois do "commit", e os modos de falha que importam (fora do ar, queda DEPOIS do commit, lote recusado
// por 403, item retryable e item terminal).
//
// Não é a central de verdade, e os testes que passam contra ela não provam a integração: provam que a sonda reage ao
// CONTRATO. A prova contra a central real é o `make demo`/`acceptance-lab` (B8 e B10).
package testes

import (
	"bytes"
	"compress/gzip"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strconv"
	"sync"
	"time"
)

// Modo de operação da central falsa.
type Modo int

const (
	Normal Modo = iota
	// Fora responde 503 com `Retry-After`.
	Fora
	// CairAposCommit grava os itens e FECHA a conexão sem responder: é a "resposta perdida" do 06 §3.
	CairAposCommit
)

// Central é o servidor falso.
type Central struct {
	Server *httptest.Server

	mu         sync.Mutex
	modo       Modo
	retryAfter int
	recebidos  map[string][]byte // result_id → conteúdo (o "commit")
	lotes      int
	recusaLote func(resultIDs []string) bool // 403 para o lote inteiro
	retryavel  map[string]bool
	terminal   map[string]bool
	token      string
	chamadas   int
	heartbeats []json.RawMessage
	gzipOK     int
	ordem      []string
	bundle     []byte
	configs    int
}

// Nova cria e inicia a central falsa. Fechar com `Close`.
func Nova(token string) *Central {
	c := &Central{recebidos: map[string][]byte{}, retryavel: map[string]bool{}, terminal: map[string]bool{}, token: token}
	c.Server = httptest.NewServer(http.HandlerFunc(c.tratar))
	return c
}

// Close encerra o servidor.
func (c *Central) Close() { c.Server.Close() }

// URL base.
func (c *Central) URL() string { return c.Server.URL }

// DefinirModo muda o comportamento.
func (c *Central) DefinirModo(m Modo, retryAfterS int) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.modo, c.retryAfter = m, retryAfterS
}

// RecusarLoteSe faz a central responder 403 ao lote inteiro quando `f` for verdadeiro (o 403 do contrato: o lote cai todo).
func (c *Central) RecusarLoteSe(f func(resultIDs []string) bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.recusaLote = f
}

// ItemRetryavel faz a central devolver `retryable` para o item, sem gravá-lo.
func (c *Central) ItemRetryavel(id string, v bool) { c.mu.Lock(); c.retryavel[id] = v; c.mu.Unlock() }

// ItemTerminal faz a central devolver `rejected_terminal` para o item.
func (c *Central) ItemTerminal(id string, v bool) { c.mu.Lock(); c.terminal[id] = v; c.mu.Unlock() }

// Gravados devolve quantos resultados distintos a central tem de forma durável.
func (c *Central) Gravados() int { c.mu.Lock(); defer c.mu.Unlock(); return len(c.recebidos) }

// TemResultado diz se o result_id foi gravado.
func (c *Central) TemResultado(id string) bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	_, ok := c.recebidos[id]
	return ok
}

// Corpo devolve o conteúdo (JSON) do i-ésimo resultado gravado, na ordem de chegada.
func (c *Central) Corpo(i int) string {
	c.mu.Lock()
	defer c.mu.Unlock()
	if i < 0 || i >= len(c.ordem) {
		return ""
	}
	return string(c.recebidos[c.ordem[i]])
}

// DefinirBundle escolhe o corpo que `GET /api/v1/probe/config` devolve (nulo = 503).
func (c *Central) DefinirBundle(b []byte) { c.mu.Lock(); c.bundle = b; c.mu.Unlock() }

// Configs é quantas vezes o bundle foi pedido.
func (c *Central) Configs() int { c.mu.Lock(); defer c.mu.Unlock(); return c.configs }

// LotesGzip é quantos lotes chegaram comprimidos e foram descomprimidos com sucesso.
func (c *Central) LotesGzip() int { c.mu.Lock(); defer c.mu.Unlock(); return c.gzipOK }

// Lotes é o número de requisições que chegaram a POST /probe/results.
func (c *Central) Lotes() int { c.mu.Lock(); defer c.mu.Unlock(); return c.chamadas }

// Heartbeats devolve os heartbeats recebidos, em ordem.
func (c *Central) Heartbeats() []json.RawMessage {
	c.mu.Lock()
	defer c.mu.Unlock()
	return append([]json.RawMessage(nil), c.heartbeats...)
}

func (c *Central) tratar(w http.ResponseWriter, r *http.Request) {
	c.mu.Lock()
	defer c.mu.Unlock()

	if r.URL.Path == "/api/v1/probe/config" && r.Method == http.MethodGet {
		if r.Header.Get("Authorization") != "Bearer "+c.token {
			http.Error(w, `{"error":"unauthorized"}`, http.StatusUnauthorized)
			return
		}
		c.configs++
		if c.bundle == nil {
			http.Error(w, `{"error":"unavailable"}`, http.StatusServiceUnavailable)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write(c.bundle)
		return
	}

	if r.URL.Path == "/api/v1/probe/heartbeat" {
		if r.Header.Get("Authorization") != "Bearer "+c.token {
			http.Error(w, `{"error":"unauthorized"}`, http.StatusUnauthorized)
			return
		}
		corpo, _ := io.ReadAll(r.Body)
		c.heartbeats = append(c.heartbeats, corpo)
		w.WriteHeader(http.StatusNoContent)
		return
	}
	c.chamadas++

	if r.Header.Get("Authorization") != "Bearer "+c.token {
		http.Error(w, `{"error":"unauthorized"}`, http.StatusUnauthorized)
		return
	}
	if c.modo == Fora {
		if c.retryAfter > 0 {
			w.Header().Set("Retry-After", strconv.Itoa(c.retryAfter))
		}
		http.Error(w, `{"error":"unavailable"}`, http.StatusServiceUnavailable)
		return
	}

	corpo, _ := io.ReadAll(r.Body)
	if r.Header.Get("Content-Encoding") == "gzip" {
		zr, err := gzip.NewReader(bytes.NewReader(corpo))
		if err != nil {
			http.Error(w, `{"error":"bad_request"}`, http.StatusBadRequest)
			return
		}
		corpo, _ = io.ReadAll(zr)
		c.gzipOK++
	}
	var lote struct {
		BatchID string            `json:"batch_id"`
		Mode    string            `json:"mode"`
		Results []json.RawMessage `json:"results"`
	}
	if err := json.Unmarshal(corpo, &lote); err != nil || len(lote.Results) == 0 || len(lote.Results) > 500 || lote.Mode != "live" {
		http.Error(w, `{"error":"bad_request"}`, http.StatusBadRequest)
		return
	}
	ids := make([]string, len(lote.Results))
	for i, raw := range lote.Results {
		var m struct {
			ResultID string `json:"result_id"`
		}
		if json.Unmarshal(raw, &m) != nil || m.ResultID == "" {
			http.Error(w, `{"error":"bad_request"}`, http.StatusBadRequest)
			return
		}
		ids[i] = m.ResultID
	}
	if c.recusaLote != nil && c.recusaLote(ids) {
		http.Error(w, `{"error":"forbidden"}`, http.StatusForbidden)
		return
	}
	c.lotes++

	itens := make([]map[string]any, len(lote.Results))
	for i, raw := range lote.Results {
		id := ids[i]
		agora := time.Now().UTC().Format("2006-01-02T15:04:05Z")
		item := map[string]any{"result_id": id, "received_at": agora}
		switch {
		case c.retryavel[id]:
			item["status"], item["durable"] = "retryable", false
		case c.terminal[id]:
			item["status"], item["durable"] = "rejected_terminal", false
		default:
			if antigo, ok := c.recebidos[id]; ok {
				if bytes.Equal(antigo, raw) {
					item["status"], item["durable"] = "duplicate", true
				} else {
					item["status"], item["durable"] = "conflict", false
				}
			} else {
				c.recebidos[id] = append([]byte(nil), raw...)
				c.ordem = append(c.ordem, id)
				item["status"], item["durable"] = "accepted", true
			}
		}
		itens[i] = item
	}

	if c.modo == CairAposCommit {
		// O commit JÁ aconteceu (acima). A conexão cai antes da resposta: a sonda não sabe de nada.
		if hj, ok := w.(http.Hijacker); ok {
			conn, _, err := hj.Hijack()
			if err == nil {
				conn.Close()
			}
		}
		return
	}
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]any{"batch_id": lote.BatchID, "items": itens})
}
