<?php

declare(strict_types=1);

namespace App\Ingestion;

/**
 * Valida o corpo de `POST /api/v1/probe/heartbeat` contra `contracts/schemas/heartbeat.schema.json` (04 §8).
 *
 * Mesma situação do `BatchValidator`: é uma implementação própria das regras do contrato (ADR-014 em aberto), guardada pelo teste de
 * conformidade com o manifesto das fixtures. O heartbeat é CAPACIDADE E SAÚDE da sonda, nunca resultado de alvo.
 */
final class HeartbeatValidator
{
    use ValidaContrato;

    private const ESTADOS = ['available', 'unavailable', 'unknown'];

    private const RELOGIO = ['synchronized', 'unsynchronized', 'unknown'];

    /** @return list<string> vazio quando é válido */
    public function validate(mixed $h): array
    {
        $this->erros = [];
        $p = '$';
        if (! $this->objeto($h)) {
            $this->erro($p, 'deve ser um objeto');

            return $this->erros;
        }
        $this->exato($h, [
            'schema_version', 'probe_id', 'sent_at', 'build', 'applied', 'uptime_s', 'last_execution_at',
            'schedule_lag_ms', 'spool', 'disk_free_bytes', 'capabilities', 'clock',
        ], [], $p);

        if (($h['schema_version'] ?? null) !== '1.0') {
            $this->erro("$p.schema_version", 'deve ser "1.0"');
        }
        $this->uuid($h['probe_id'] ?? null, "$p.probe_id");
        $this->timestamp($h['sent_at'] ?? null, "$p.sent_at");

        $this->build($h['build'] ?? null, "$p.build");
        $this->aplicado($h['applied'] ?? null, "$p.applied");
        $this->inteiro($h['uptime_s'] ?? null, 0, null, "$p.uptime_s");
        $this->ouNulo($h['last_execution_at'] ?? null, fn ($v) => $this->timestamp($v, "$p.last_execution_at"));
        $this->ouNulo($h['schedule_lag_ms'] ?? null, fn ($v) => $this->inteiro($v, 0, null, "$p.schedule_lag_ms"));
        $this->spool($h['spool'] ?? null, "$p.spool");
        $this->inteiro($h['disk_free_bytes'] ?? null, 0, null, "$p.disk_free_bytes");
        $this->capacidades($h['capabilities'] ?? null, "$p.capabilities");
        $this->relogio($h['clock'] ?? null, "$p.clock");

        return $this->erros;
    }

    private function build(mixed $b, string $p): void
    {
        if (! $this->objeto($b)) {
            $this->erro($p, 'deve ser um objeto');

            return;
        }
        $this->exato($b, ['version', 'commit'], [], $p);
        $this->texto($b['version'] ?? null, "$p.version");
        $this->texto($b['commit'] ?? null, "$p.commit");
    }

    private function aplicado(mixed $a, string $p): void
    {
        if (! $this->objeto($a)) {
            $this->erro($p, 'deve ser um objeto');

            return;
        }
        $this->exato($a, ['bundle_revision', 'config_hash', 'local_policy_hash'], [], $p);
        $this->ouNulo($a['bundle_revision'] ?? null, fn ($v) => $this->inteiro($v, 1, null, "$p.bundle_revision"));
        $this->ouNulo($a['config_hash'] ?? null, fn ($v) => $this->hash($v, "$p.config_hash"));
        $this->hash($a['local_policy_hash'] ?? null, "$p.local_policy_hash");
    }

    private function spool(mixed $s, string $p): void
    {
        if (! $this->objeto($s)) {
            $this->erro($p, 'deve ser um objeto');

            return;
        }
        $this->exato($s, ['rows', 'bytes', 'oldest_age_s', 'dropped'], [], $p);
        $this->inteiro($s['rows'] ?? null, 0, null, "$p.rows");
        $this->inteiro($s['bytes'] ?? null, 0, null, "$p.bytes");
        $this->ouNulo($s['oldest_age_s'] ?? null, fn ($v) => $this->inteiro($v, 0, null, "$p.oldest_age_s"));
        $this->inteiro($s['dropped'] ?? null, 0, null, "$p.dropped");
    }

    private function capacidades(mixed $c, string $p): void
    {
        if (! $this->objeto($c)) {
            $this->erro($p, 'deve ser um objeto');

            return;
        }
        $this->exato($c, ['ipv4', 'ipv6', 'icmp'], [], $p);
        foreach (['ipv4', 'ipv6', 'icmp'] as $k) {
            $this->enum($c[$k] ?? null, self::ESTADOS, "$p.$k");
        }
    }

    private function relogio(mixed $c, string $p): void
    {
        if (! $this->objeto($c)) {
            $this->erro($p, 'deve ser um objeto');

            return;
        }
        $this->exato($c, ['status', 'offset_ms', 'reference_kind'], [], $p);
        $this->enum($c['status'] ?? null, self::RELOGIO, "$p.status");
        $this->ouNulo($c['offset_ms'] ?? null, fn ($v) => $this->inteiro($v, null, null, "$p.offset_ms"));
        $this->texto($c['reference_kind'] ?? null, "$p.reference_kind");
        // INV-02: desconhecido não é zero. Relógio desconhecido exige offset nulo.
        if (($c['status'] ?? null) === 'unknown' && ($c['offset_ms'] ?? null) !== null) {
            $this->erro("$p.offset_ms", 'relógio desconhecido exige offset nulo (INV-02)');
        }
    }

    private function hash(mixed $v, string $p): void
    {
        if (! is_string($v) || preg_match('/^[0-9a-f]{64}$/', $v) !== 1) {
            $this->erro($p, 'deve ser um SHA-256 em hexadecimal minúsculo');
        }
    }

    private function ouNulo(mixed $v, callable $valida): void
    {
        if ($v !== null) {
            $valida($v);
        }
    }
}
