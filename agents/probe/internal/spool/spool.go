// Package spool é o armazenamento durável da sonda, em SQLite no modo WAL (04 §6; C-15): a agenda, a fila de envio e o
// que se descarta ou se põe em quarentena. Um arquivo só, e uma transação para cada passo que não pode ficar pela metade.
//
// O que ele garante:
//   - **Nada se perde por queda.** Um resultado só sai daqui depois que a central o confirmou de forma durável
//     (`accepted` ou `duplicate`), item por item. HTTP 200 do lote não apaga o lote.
//   - **A agenda sobrevive ao reinício.** Cada slot é planejado ANTES de executar, com o `result_id` já gerado, e a
//     conclusão do slot e a entrada do resultado na fila acontecem na mesma transação.
//   - **Limite temporal e em bytes, e descarte nunca é silencioso.** Passou de duas horas ou de 256 MiB, os mais antigos
//     saem POR POLÍTICA EXPLÍCITA, e cada descarte vira uma linha em `descartes` (faixa, quantidade, motivo). Descarte
//     não vira sucesso nem "sem falhas".
//   - **Rejeição definitiva não trava a fila.** `rejected_terminal` e `conflict` vão para a quarentena local, que também
//     tem limite (a reserva de 32 MiB para diagnóstico), e a fila segue.
package spool

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"errors"
	"fmt"
	"net/url"
	"time"

	_ "modernc.org/sqlite" // driver SQLite em Go puro (ADR-013)

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/relogio"
)

// Estados de um slot na agenda.
const (
	Planejado  = "planejado"
	Executando = "executando"
	Concluido  = "concluido"
	Pulado     = "pulado"
)

// Respostas por item que a central devolve (14 §3).
const (
	Accepted         = "accepted"
	Duplicate        = "duplicate"
	Retryable        = "retryable"
	RejectedTerminal = "rejected_terminal"
	Conflict         = "conflict"
)

// Limites iniciais da C-15: 500 exec/min × 120 min ≈ 60 mil linhas ≈ 60 MB, com folga para 256 MiB, mais 32 MiB reservados
// ao diagnóstico para o log nunca consumir a área do spool.
const (
	BytesDadosPadrao   = 256 << 20
	BytesReservaPadrao = 32 << 20
	RetencaoPadrao     = 2 * time.Hour
)

// Limites do spool.
type Limites struct {
	BytesDados   int64         // soma dos envelopes pendentes
	BytesReserva int64         // soma dos envelopes em quarentena (diagnóstico)
	Retencao     time.Duration // idade máxima de um item pendente
}

// Spool é o armazenamento. Seguro para uso concorrente (uma conexão só, serializada).
type Spool struct {
	db      *sql.DB
	limites Limites
	relogio relogio.Relogio
}

// ItemAgenda identifica um slot planejado.
type ItemAgenda struct {
	AssignmentID string
	Geracao      int
	Slot         time.Time
	ResultID     string
	Estado       string
	// Contexto é o retrato do assignment no momento em que o slot foi planejado. É o que permite reconciliar depois de um
	// reinício SEM depender de a configuração atual ainda ter o assignment (a geração pode ter mudado).
	Contexto []byte
	// PlanejadoEm é quando o slot foi planejado (preenchido por Incompletos).
	PlanejadoEm time.Time
}

// ItemFila é um resultado esperando confirmação.
type ItemFila struct {
	ResultID   string
	Slot       time.Time
	Envelope   []byte
	Hash       string
	Tentativas int
	CriadoEm   time.Time
}

// Descarte é o registro de uma perda deliberada.
type Descarte struct {
	Em         time.Time
	DeSlot     time.Time
	AteSlot    time.Time
	Quantidade int
	Motivo     string
}

// Estatisticas do spool, para o heartbeat (04 §8).
type Estatisticas struct {
	Linhas           int64
	Bytes            int64
	IdadeMaisAntigaS *int64
	Descartados      int64
	Quarentena       int64
}

const esquema = `
CREATE TABLE IF NOT EXISTS agenda (
	assignment_id TEXT    NOT NULL,
	geracao       INTEGER NOT NULL,
	slot_ms       INTEGER NOT NULL,
	result_id     TEXT    NOT NULL UNIQUE,
	estado        TEXT    NOT NULL CHECK (estado IN ('planejado','executando','concluido','pulado')),
	planejado_ms  INTEGER NOT NULL,
	contexto      BLOB,
	PRIMARY KEY (assignment_id, geracao, slot_ms)
);
CREATE TABLE IF NOT EXISTS fila (
	result_id         TEXT    PRIMARY KEY,
	assignment_id     TEXT    NOT NULL,
	geracao           INTEGER NOT NULL,
	slot_ms           INTEGER NOT NULL,
	envelope          BLOB    NOT NULL,
	hash              TEXT    NOT NULL,
	bytes             INTEGER NOT NULL,
	criado_ms         INTEGER NOT NULL,
	tentativas        INTEGER NOT NULL DEFAULT 0,
	proxima_ms        INTEGER NOT NULL DEFAULT 0,
	ultimo_erro       TEXT
);
CREATE INDEX IF NOT EXISTS fila_slot ON fila (slot_ms);
CREATE TABLE IF NOT EXISTS quarentena (
	result_id   TEXT    PRIMARY KEY,
	envelope    BLOB    NOT NULL,
	bytes       INTEGER NOT NULL,
	status      TEXT    NOT NULL,
	motivo      TEXT,
	em_ms       INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS descartes (
	id         INTEGER PRIMARY KEY AUTOINCREMENT,
	em_ms      INTEGER NOT NULL,
	de_ms      INTEGER NOT NULL,
	ate_ms     INTEGER NOT NULL,
	quantidade INTEGER NOT NULL,
	motivo     TEXT    NOT NULL
);
CREATE TABLE IF NOT EXISTS lacunas (
	id            INTEGER PRIMARY KEY AUTOINCREMENT,
	assignment_id TEXT    NOT NULL,
	geracao       INTEGER NOT NULL,
	de_ms         INTEGER NOT NULL,
	ate_ms        INTEGER NOT NULL,
	quantidade    INTEGER NOT NULL,
	em_ms         INTEGER NOT NULL
);
`

// Abrir cria (ou reabre) o spool em `caminho`. Reabrir é o caminho do reinício depois de uma queda: o WAL é reproduzido pelo
// próprio SQLite.
func Abrir(caminho string, l Limites, r relogio.Relogio) (*Spool, error) {
	if l.BytesDados <= 0 {
		l.BytesDados = BytesDadosPadrao
	}
	if l.BytesReserva <= 0 {
		l.BytesReserva = BytesReservaPadrao
	}
	if l.Retencao <= 0 {
		l.Retencao = RetencaoPadrao
	}
	// synchronous=FULL: um resultado confirmado ao coordenador sobrevive a uma queda de energia, e não só a uma queda do processo.
	q := url.Values{}
	q.Add("_pragma", "journal_mode(WAL)")
	q.Add("_pragma", "synchronous(FULL)")
	q.Add("_pragma", "busy_timeout(5000)")
	db, err := sql.Open("sqlite", "file:"+caminho+"?"+q.Encode())
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(1)
	if _, err := db.Exec(esquema); err != nil {
		db.Close()
		return nil, fmt.Errorf("spool: criando o esquema: %w", err)
	}
	return &Spool{db: db, limites: l, relogio: r}, nil
}

// Fechar fecha o arquivo.
func (s *Spool) Fechar() error { return s.db.Close() }

func ms(t time.Time) int64   { return t.UTC().UnixMilli() }
func deMS(v int64) time.Time { return time.UnixMilli(v).UTC() }

// Planejar registra o slot ANTES de executá-lo. Idempotente: se o slot já está planejado, devolve o `result_id` original
// (identidade estável entre reinícios) e `novo == false`.
func (s *Spool) Planejar(ctx context.Context, it ItemAgenda) (resultID string, novo bool, err error) {
	r, err := s.db.ExecContext(ctx,
		`INSERT OR IGNORE INTO agenda (assignment_id, geracao, slot_ms, result_id, estado, planejado_ms, contexto) VALUES (?,?,?,?,?,?,?)`,
		it.AssignmentID, it.Geracao, ms(it.Slot), it.ResultID, Planejado, ms(s.relogio.Agora()), it.Contexto)
	if err != nil {
		return "", false, err
	}
	n, _ := r.RowsAffected()
	if n == 1 {
		return it.ResultID, true, nil
	}
	err = s.db.QueryRowContext(ctx,
		`SELECT result_id FROM agenda WHERE assignment_id=? AND geracao=? AND slot_ms=?`,
		it.AssignmentID, it.Geracao, ms(it.Slot)).Scan(&resultID)
	return resultID, false, err
}

// EstadoDoSlot devolve o estado de um slot planejado, e se ele existe.
func (s *Spool) EstadoDoSlot(ctx context.Context, assignmentID string, geracao int, slot time.Time) (string, bool, error) {
	var e string
	err := s.db.QueryRowContext(ctx, `SELECT estado FROM agenda WHERE assignment_id=? AND geracao=? AND slot_ms=?`, assignmentID, geracao, ms(slot)).Scan(&e)
	if errors.Is(err, sql.ErrNoRows) {
		return "", false, nil
	}
	return e, err == nil, err
}

// Iniciar marca o slot como em execução. Devolve falso se ele já não estava `planejado` (outro caminho o pegou).
func (s *Spool) Iniciar(ctx context.Context, it ItemAgenda) (bool, error) {
	r, err := s.db.ExecContext(ctx,
		`UPDATE agenda SET estado=? WHERE assignment_id=? AND geracao=? AND slot_ms=? AND estado=?`,
		Executando, it.AssignmentID, it.Geracao, ms(it.Slot), Planejado)
	if err != nil {
		return false, err
	}
	n, _ := r.RowsAffected()
	return n == 1, nil
}

// Incompletos devolve os slots que ficaram `planejado` ou `executando`: o que o reinício precisa reconciliar.
func (s *Spool) Incompletos(ctx context.Context) ([]ItemAgenda, error) {
	rows, err := s.db.QueryContext(ctx,
		`SELECT assignment_id, geracao, slot_ms, result_id, estado, planejado_ms, contexto FROM agenda WHERE estado IN (?,?) ORDER BY slot_ms`,
		Planejado, Executando)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []ItemAgenda
	for rows.Next() {
		var it ItemAgenda
		var slot, planejado int64
		if err := rows.Scan(&it.AssignmentID, &it.Geracao, &slot, &it.ResultID, &it.Estado, &planejado, &it.Contexto); err != nil {
			return nil, err
		}
		it.Slot, it.PlanejadoEm = deMS(slot), deMS(planejado)
		out = append(out, it)
	}
	return out, rows.Err()
}

// UltimoSlot é o maior slot já planejado deste assignment nesta geração.
func (s *Spool) UltimoSlot(ctx context.Context, assignmentID string, geracao int) (time.Time, bool, error) {
	var v sql.NullInt64
	err := s.db.QueryRowContext(ctx, `SELECT MAX(slot_ms) FROM agenda WHERE assignment_id=? AND geracao=?`, assignmentID, geracao).Scan(&v)
	if err != nil || !v.Valid {
		return time.Time{}, false, err
	}
	return deMS(v.Int64), true, nil
}

// Finalizar guarda o resultado final na fila E marca o slot (`concluido` ou `pulado`) na MESMA transação: ou os dois
// acontecem, ou nenhum. Um resultado finalizado é imutável: finalizar de novo o mesmo slot é um erro, não uma sobrescrita.
func (s *Spool) Finalizar(ctx context.Context, it ItemAgenda, estado string, envelope []byte) error {
	if estado != Concluido && estado != Pulado {
		return fmt.Errorf("spool: estado final inválido %q", estado)
	}
	soma := sha256.Sum256(envelope)
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()

	r, err := tx.ExecContext(ctx,
		`UPDATE agenda SET estado=? WHERE assignment_id=? AND geracao=? AND slot_ms=? AND result_id=? AND estado IN (?,?)`,
		estado, it.AssignmentID, it.Geracao, ms(it.Slot), it.ResultID, Planejado, Executando)
	if err != nil {
		return err
	}
	if n, _ := r.RowsAffected(); n != 1 {
		return errors.New("spool: o slot não está planejado/executando (já finalizado, ou nunca planejado): um resultado finalizado é imutável")
	}
	if _, err := tx.ExecContext(ctx,
		`INSERT INTO fila (result_id, assignment_id, geracao, slot_ms, envelope, hash, bytes, criado_ms) VALUES (?,?,?,?,?,?,?,?)`,
		it.ResultID, it.AssignmentID, it.Geracao, ms(it.Slot), envelope, hex.EncodeToString(soma[:]), len(envelope), ms(s.relogio.Agora())); err != nil {
		return err
	}
	if err := tx.Commit(); err != nil {
		return err
	}
	return s.Aparar(ctx)
}

// RegistrarLacuna anota um intervalo de slots que NÃO foi executado nem registrado como resultado individual (a sonda ficou
// parada). Não é sucesso nem "sem falhas": aparece no diagnóstico.
func (s *Spool) RegistrarLacuna(ctx context.Context, assignmentID string, geracao int, de, ate time.Time, quantidade int) error {
	_, err := s.db.ExecContext(ctx,
		`INSERT INTO lacunas (assignment_id, geracao, de_ms, ate_ms, quantidade, em_ms) VALUES (?,?,?,?,?,?)`,
		assignmentID, geracao, ms(de), ms(ate), quantidade, ms(s.relogio.Agora()))
	return err
}

// LacunasTotal é a soma dos slots registrados como lacuna.
func (s *Spool) LacunasTotal(ctx context.Context) (int64, error) {
	var n sql.NullInt64
	err := s.db.QueryRowContext(ctx, `SELECT SUM(quantidade) FROM lacunas`).Scan(&n)
	return n.Int64, err
}

// Proximos devolve até `n` itens prontos para envio (cuja próxima tentativa já chegou), até `bytesMax` no total, os mais
// RECENTES primeiro: o dado corrente não fica bloqueado atrás de horas de histórico (04 §6). Sempre devolve ao menos um item
// se houver algum pronto, mesmo maior que `bytesMax`.
func (s *Spool) Proximos(ctx context.Context, n int, bytesMax int64) ([]ItemFila, error) {
	rows, err := s.db.QueryContext(ctx,
		`SELECT result_id, slot_ms, envelope, hash, tentativas, criado_ms, bytes FROM fila WHERE proxima_ms <= ? ORDER BY slot_ms DESC, result_id LIMIT ?`,
		ms(s.relogio.Agora()), n)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []ItemFila
	var soma int64
	for rows.Next() {
		var it ItemFila
		var slot, criado, bytes int64
		if err := rows.Scan(&it.ResultID, &slot, &it.Envelope, &it.Hash, &it.Tentativas, &criado, &bytes); err != nil {
			return nil, err
		}
		if len(out) > 0 && soma+bytes > bytesMax {
			break
		}
		soma += bytes
		it.Slot, it.CriadoEm = deMS(slot), deMS(criado)
		out = append(out, it)
	}
	return out, rows.Err()
}

// Confirmar aplica a resposta da central a UM item.
//
//   - accepted, duplicate: a central tem o resultado de forma durável. Sai da fila.
//   - retryable: continua na fila, com a próxima tentativa em `proxima`.
//   - rejected_terminal, conflict: não adianta reenviar. Vai para a quarentena local (com limite), para não bloquear o resto.
//
// Um status desconhecido NÃO apaga nada: o resultado fica, e o erro volta a quem chamou.
func (s *Spool) Confirmar(ctx context.Context, resultID, status, motivo string, proxima time.Time) error {
	switch status {
	case Accepted, Duplicate:
		_, err := s.db.ExecContext(ctx, `DELETE FROM fila WHERE result_id=?`, resultID)
		return err
	case Retryable:
		_, err := s.db.ExecContext(ctx,
			`UPDATE fila SET tentativas=tentativas+1, proxima_ms=?, ultimo_erro=? WHERE result_id=?`,
			ms(proxima), motivo, resultID)
		return err
	case RejectedTerminal, Conflict:
		return s.quarentenar(ctx, resultID, status, motivo)
	default:
		return fmt.Errorf("spool: status %q desconhecido para %s: o resultado fica na fila", status, resultID)
	}
}

func (s *Spool) quarentenar(ctx context.Context, resultID, status, motivo string) error {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if _, err := tx.ExecContext(ctx,
		`INSERT OR REPLACE INTO quarentena (result_id, envelope, bytes, status, motivo, em_ms)
		 SELECT result_id, envelope, bytes, ?, ?, ? FROM fila WHERE result_id=?`,
		status, motivo, ms(s.relogio.Agora()), resultID); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `DELETE FROM fila WHERE result_id=?`, resultID); err != nil {
		return err
	}
	// A quarentena tem limite (a reserva de diagnóstico): passou, os mais antigos saem, e a saída é registrada.
	for {
		var total sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT SUM(bytes) FROM quarentena`).Scan(&total); err != nil {
			return err
		}
		if total.Int64 <= s.limites.BytesReserva {
			break
		}
		var id string
		var em int64
		if err := tx.QueryRowContext(ctx, `SELECT result_id, em_ms FROM quarentena ORDER BY em_ms, result_id LIMIT 1`).Scan(&id, &em); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `DELETE FROM quarentena WHERE result_id=?`, id); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx,
			`INSERT INTO descartes (em_ms, de_ms, ate_ms, quantidade, motivo) VALUES (?,?,?,1,'quarentena_cheia')`,
			ms(s.relogio.Agora()), em, em); err != nil {
			return err
		}
	}
	return tx.Commit()
}

// Aparar aplica os limites temporal e em bytes. Só apaga com política explícita e SEMPRE registra o descarte (faixa de
// slots, quantidade e motivo). Chamado depois de cada entrada na fila.
func (s *Spool) Aparar(ctx context.Context) error {
	agora := s.relogio.Agora()
	if err := s.descartarOnde(ctx, `criado_ms < ?`, []any{ms(agora.Add(-s.limites.Retencao))}, "retencao_temporal"); err != nil {
		return err
	}
	for {
		var total sql.NullInt64
		if err := s.db.QueryRowContext(ctx, `SELECT SUM(bytes) FROM fila`).Scan(&total); err != nil {
			return err
		}
		if total.Int64 <= s.limites.BytesDados {
			return nil
		}
		// Os mais ANTIGOS saem primeiro, um por vez, até caber.
		if err := s.descartarOnde(ctx, `result_id = (SELECT result_id FROM fila ORDER BY slot_ms, result_id LIMIT 1)`, nil, "limite_de_bytes"); err != nil {
			return err
		}
	}
}

func (s *Spool) descartarOnde(ctx context.Context, onde string, args []any, motivo string) error {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	var n int
	var de, ate sql.NullInt64
	if err := tx.QueryRowContext(ctx, `SELECT COUNT(*), MIN(slot_ms), MAX(slot_ms) FROM fila WHERE `+onde, args...).Scan(&n, &de, &ate); err != nil {
		return err
	}
	if n == 0 {
		return nil
	}
	if _, err := tx.ExecContext(ctx, `DELETE FROM fila WHERE `+onde, args...); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx,
		`INSERT INTO descartes (em_ms, de_ms, ate_ms, quantidade, motivo) VALUES (?,?,?,?,?)`,
		ms(s.relogio.Agora()), de.Int64, ate.Int64, n, motivo); err != nil {
		return err
	}
	return tx.Commit()
}

// Descartes devolve o registro de tudo o que foi descartado.
func (s *Spool) Descartes(ctx context.Context) ([]Descarte, error) {
	rows, err := s.db.QueryContext(ctx, `SELECT em_ms, de_ms, ate_ms, quantidade, motivo FROM descartes ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []Descarte
	for rows.Next() {
		var d Descarte
		var em, de, ate int64
		if err := rows.Scan(&em, &de, &ate, &d.Quantidade, &d.Motivo); err != nil {
			return nil, err
		}
		d.Em, d.DeSlot, d.AteSlot = deMS(em), deMS(de), deMS(ate)
		out = append(out, d)
	}
	return out, rows.Err()
}

// Estatisticas para o heartbeat.
func (s *Spool) Estatisticas(ctx context.Context) (Estatisticas, error) {
	var e Estatisticas
	var bytes, minCriado, desc sql.NullInt64
	if err := s.db.QueryRowContext(ctx, `SELECT COUNT(*), SUM(bytes), MIN(criado_ms) FROM fila`).Scan(&e.Linhas, &bytes, &minCriado); err != nil {
		return e, err
	}
	e.Bytes = bytes.Int64
	if minCriado.Valid {
		idade := int64(s.relogio.Agora().Sub(deMS(minCriado.Int64)).Seconds())
		if idade < 0 {
			idade = 0
		}
		e.IdadeMaisAntigaS = &idade
	}
	if err := s.db.QueryRowContext(ctx, `SELECT SUM(quantidade) FROM descartes`).Scan(&desc); err != nil {
		return e, err
	}
	e.Descartados = desc.Int64
	if err := s.db.QueryRowContext(ctx, `SELECT COUNT(*) FROM quarentena`).Scan(&e.Quarentena); err != nil {
		return e, err
	}
	return e, nil
}
