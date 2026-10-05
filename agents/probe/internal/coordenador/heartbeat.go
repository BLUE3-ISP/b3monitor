package coordenador

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"syscall"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/resultado"
)

// Heartbeat é o autodiagnóstico da sonda (04 §8; contracts/schemas/heartbeat.schema.json). É capacidade e saúde, NUNCA
// resultado de alvo e nunca heartbeat de job. Valor desconhecido é nulo, ou o estado diz `unknown`; nunca zero.
type Heartbeat struct {
	SchemaVersion string             `json:"schema_version"`
	ProbeID       string             `json:"probe_id"`
	SentAt        resultado.Instante `json:"sent_at"`
	Build         struct {
		Version string `json:"version"`
		Commit  string `json:"commit"`
	} `json:"build"`
	Applied struct {
		BundleRevision  *int    `json:"bundle_revision"`
		ConfigHash      *string `json:"config_hash"`
		LocalPolicyHash string  `json:"local_policy_hash"`
	} `json:"applied"`
	UptimeS         int64               `json:"uptime_s"`
	LastExecutionAt *resultado.Instante `json:"last_execution_at"`
	ScheduleLagMS   *int64              `json:"schedule_lag_ms"`
	Spool           struct {
		Rows       int64  `json:"rows"`
		Bytes      int64  `json:"bytes"`
		OldestAgeS *int64 `json:"oldest_age_s"`
		Dropped    int64  `json:"dropped"`
	} `json:"spool"`
	DiskFreeBytes uint64 `json:"disk_free_bytes"`
	Capabilities  struct {
		IPv4 string `json:"ipv4"`
		IPv6 string `json:"ipv6"`
		ICMP string `json:"icmp"`
	} `json:"capabilities"`
	Clock resultado.Relogio `json:"clock"`
}

// Capacidades que a sonda informa. Sem medição, `unknown`: capacidade não medida não é "disponível".
type Capacidades struct{ IPv4, IPv6, ICMP string }

// Heartbeat monta o autodiagnóstico de agora.
func (c *Coordenador) Heartbeat(ctx context.Context) (Heartbeat, error) {
	var h Heartbeat
	agora := c.cfg.Relogio.Agora()
	h.SchemaVersion = resultado.SchemaVersion
	h.ProbeID = c.cfg.ProbeID
	h.SentAt = resultado.Em(agora)
	h.Build.Version, h.Build.Commit = c.cfg.Versao, c.cfg.Commit
	if h.Build.Commit == "" {
		h.Build.Commit = "unknown"
	}

	h.Applied.LocalPolicyHash = c.cfg.Politica.Hash()
	if c.bundle != nil {
		rev := c.bundle.Revision
		h.Applied.BundleRevision = &rev
		b, err := json.Marshal(c.bundle)
		if err != nil {
			return h, err
		}
		soma := sha256.Sum256(b)
		hash := hex.EncodeToString(soma[:])
		h.Applied.ConfigHash = &hash
	}

	h.UptimeS = int64(agora.Sub(c.inicio).Seconds())
	if h.UptimeS < 0 {
		h.UptimeS = 0
	}
	if c.ultimaExecucao != nil {
		i := resultado.Em(*c.ultimaExecucao)
		h.LastExecutionAt = &i
	}
	h.ScheduleLagMS = c.atrasoMS

	e, err := c.cfg.Spool.Estatisticas(ctx)
	if err != nil {
		return h, err
	}
	h.Spool.Rows, h.Spool.Bytes, h.Spool.OldestAgeS, h.Spool.Dropped = e.Linhas, e.Bytes, e.IdadeMaisAntigaS, e.Descartados

	livre, err := c.discoLivre()
	if err != nil {
		// Sem saber o espaço livre não se inventa um número: o heartbeat não é montado, e o erro é visível.
		return h, errors.New("heartbeat: não foi possível medir o disco livre: " + err.Error())
	}
	h.DiskFreeBytes = livre

	cap := c.cfg.Capacidades
	h.Capabilities.IPv4, h.Capabilities.IPv6, h.Capabilities.ICMP = estado(cap.IPv4), estado(cap.IPv6), estado(cap.ICMP)
	h.Clock = c.cfg.EstadoRelogio()
	return h, nil
}

func estado(s string) string {
	if s == "" {
		return "unknown"
	}
	return s
}

func (c *Coordenador) discoLivre() (uint64, error) {
	if c.cfg.DiscoLivre != nil {
		return c.cfg.DiscoLivre()
	}
	if c.cfg.DiretorioSpool == "" {
		return 0, errors.New("DiretorioSpool não configurado")
	}
	var s syscall.Statfs_t
	if err := syscall.Statfs(c.cfg.DiretorioSpool, &s); err != nil {
		return 0, err
	}
	return s.Bavail * uint64(s.Bsize), nil
}
