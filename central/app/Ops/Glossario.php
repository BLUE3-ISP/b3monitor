<?php

declare(strict_types=1);

namespace App\Ops;

use App\State\ClockStatus;
use App\State\Coverage;
use App\State\Health;
use App\State\Integrity;
use App\State\Outcome;
use App\State\Scope;

/**
 * Os valores de enum do glossário 14 §3 e §4 do pacote 2.0, para o que o banco e o código precisam listar.
 *
 * A fonte única é o glossário, espelhado em `contracts/schemas/common.schema.json`. Aqui ficam só os
 * valores que as migrações usam em CHECK, e um teste compara os que também existem no contrato com ele, para
 * o banco e o contrato nunca divergirem em silêncio. Acrescentar um valor é por ADR, nunca renomear (14 §4).
 */
final class Glossario
{
    /** @return list<string> */
    public static function ipFamily(): array
    {
        return ['ipv4', 'ipv6'];
    }

    /** @return list<string> */
    public static function executionKind(): array
    {
        return ['scheduled', 'manual', 'canary'];
    }

    /** @return list<string> */
    public static function outcome(): array
    {
        return array_map(fn (Outcome $o) => $o->value, Outcome::cases());
    }

    /** @return list<string> */
    public static function health(): array
    {
        return array_map(fn (Health $h) => $h->value, Health::cases());
    }

    /** @return list<string> */
    public static function scope(): array
    {
        return array_map(fn (Scope $s) => $s->value, Scope::cases());
    }

    /** @return list<string> */
    public static function coverage(): array
    {
        return array_map(fn (Coverage $c) => $c->value, Coverage::cases());
    }

    /** @return list<string> */
    public static function integrity(): array
    {
        return array_map(fn (Integrity $i) => $i->value, Integrity::cases());
    }

    /** @return list<string> */
    public static function clockStatus(): array
    {
        return array_map(fn (ClockStatus $c) => $c->value, ClockStatus::cases());
    }

    /** @return list<string> */
    public static function reasonCode(): array
    {
        return [
            'http_assertions_passed', 'target_timeout', 'connection_refused', 'unexpected_status',
            'assertion_failed', 'tls_handshake_failed', 'tls_certificate_invalid', 'tls_expiring',
            'dns_unexpected_answer', 'dns_servfail', 'icmp_no_reply', 'executor_unavailable',
            'family_unavailable', 'policy_denied', 'clock_untrusted', 'path_evidence_expired',
            'path_mismatch', 'schedule_missed', 'spool_full', 'config_expired',
        ];
    }

    /** @return list<string> */
    public static function incidentClass(): array
    {
        return ['service', 'upstream_path', 'monitoring_coverage', 'monitoring_integrity', 'platform', 'job_heartbeat'];
    }

    /** @return list<string> */
    public static function severity(): array
    {
        return ['info', 'warning', 'critical'];
    }

    /** @return list<string> */
    public static function channel(): array
    {
        return ['dev', 'telegram', 'push', 'email'];
    }

    /** @return list<string> */
    public static function attemptStatus(): array
    {
        return ['queued', 'sent', 'accepted_by_provider', 'failed', 'cancelled_by_policy', 'suppressed'];
    }

    /** Lista pronta para um CHECK: `'a','b','c'`. Valores vêm do código acima, nunca de entrada externa. */
    /** @return list<string> */
    public static function checkKind(): array
    {
        return ['http', 'tcp', 'icmp', 'dns', 'tls', 'job_heartbeat'];
    }

    public static function sql(array $valores): string
    {
        return implode(',', array_map(fn (string $v) => "'".str_replace("'", "''", $v)."'", $valores));
    }
}
