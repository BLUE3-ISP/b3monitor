<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Apoio dos testes de banco. Tudo roda no `b3mon_test`, dentro da transação do RefreshDatabase.
 */
final class Banco
{
    public const PROBE = '33333333-3333-4333-8333-333333333333';

    public const ASSIGNMENT = '44444444-4444-4444-8444-444444444444';

    public const CHECK = '22222222-2222-4222-8222-222222222222';

    /** SQLSTATEs do PostgreSQL que os testes esperam. */
    public const UNICA = '23505';

    public const CHECK_VIOLADO = '23514';

    public const FK = '23503';

    public const NAO_NULO = '23502';

    public const IMUTAVEL = '23000';   // integrity_constraint_violation, do gatilho b3mon_forbid_change

    /**
     * Roda `$acao` e devolve o SQLSTATE do erro de banco, ou `null` se não houve erro. Cada tentativa vai num
     * savepoint: sem isso, a primeira recusa abortaria a transação do teste inteiro.
     */
    public static function sqlstate(callable $acao): ?string
    {
        try {
            DB::transaction($acao);
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }

        return null;
    }

    public static function garantirParticao(string $dia = '2026-09-28'): void
    {
        DB::select('select b3mon_ensure_result_partitions(?::date, ?::date)', [$dia, $dia]);
    }

    /** @param  array<string, mixed>  $sobrepor */
    public static function resultado(array $sobrepor = []): string
    {
        self::garantirParticao(substr((string) ($sobrepor['started_at'] ?? '2026-09-28'), 0, 10));
        $id = $sobrepor['result_id'] ?? (string) Str::uuid();

        DB::table('results')->insert(array_merge([
            'result_id' => $id,
            'probe_id' => self::PROBE,
            'assignment_id' => self::ASSIGNMENT,
            'config_generation' => 3,
            'policy_revision' => 'critical-v1',
            'execution_kind' => 'scheduled',
            'manual_request_id' => null,
            'scheduled_at' => '2026-09-28T15:00:00Z',
            'started_at' => '2026-09-28T15:00:02Z',
            'finished_at' => '2026-09-28T15:00:02.120Z',
            'duration_ms' => 120,
            'ip_family' => 'ipv4',
            'outcome' => 'pass',
            'reason_code' => 'http_assertions_passed',
            'clock_status' => 'synchronized',
            'probe_integrity' => 'valid',
            'payload' => json_encode(['lab' => true]),
            'content_hash' => str_repeat('a', 64),
            'received_at' => '2026-09-28T15:00:03Z',
        ], $sobrepor));

        return $id;
    }

    /** Uma definição de verificação HTTP (a que o bundle leva à sonda). @param  array<string, mixed>  $sobrepor */
    public static function check(array $sobrepor = []): string
    {
        $id = $sobrepor['id'] ?? self::CHECK;
        DB::table('checks')->insert(array_merge([
            'id' => $id,
            'name' => 'portal-lab',
            'kind' => 'http',
            'target' => json_encode(['url' => 'https://portal.monitor.example/health', 'method' => 'GET', 'allowed_status_codes' => [200], 'follow_redirects' => false]),
            'interval_s' => 30,
            'timeout_ms' => 5000,
            'start_tolerance_ms' => 6000,
            'schedule_epoch' => '2026-09-28T14:00:00Z',
            'policy_revision' => 'critical-v1',
        ], $sobrepor));

        return $id;
    }

    /** @param  array<string, mixed>  $sobrepor */
    public static function recibo(array $sobrepor = []): string
    {
        $id = $sobrepor['result_id'] ?? (string) Str::uuid();
        DB::table('result_receipts')->insert(array_merge([
            'result_id' => $id,
            'probe_id' => self::PROBE,
            'assignment_id' => self::ASSIGNMENT,
            'config_generation' => 3,
            'execution_kind' => 'scheduled',
            'scheduled_at' => '2026-09-28T15:00:00Z',
            'manual_request_id' => null,
            'content_hash' => str_repeat('a', 64),
            'result_started_at' => '2026-09-28T15:00:02Z',
            'received_at' => '2026-09-28T15:00:03Z',
            'retain_until' => '2026-10-19T15:00:03Z',
        ], $sobrepor));

        return $id;
    }

    /** @param  array<string, mixed>  $sobrepor */
    public static function incidente(array $sobrepor = []): string
    {
        $id = $sobrepor['id'] ?? (string) Str::uuid();
        DB::table('incidents')->insert(array_merge([
            'id' => $id,
            'check_id' => self::CHECK,
            'ip_family' => 'ipv4',
            'incident_class' => 'service',
            'severity' => 'critical',
            'scope' => 'all_observed',
            'correlation_key' => 'service:'.self::CHECK.':ipv4',
            'observed_start_at' => '2026-09-28T15:00:00Z',
            'confirmed_at' => '2026-09-28T15:01:00Z',
        ], $sobrepor));

        return $id;
    }

    /** @param  array<string, mixed>  $sobrepor */
    public static function estado(array $sobrepor = []): void
    {
        DB::table('current_states')->insert(array_merge([
            'check_id' => self::CHECK,
            'ip_family' => 'ipv4',
            'health' => 'up',
            'scope' => 'none',
            'coverage' => 'full',
            'valid_domains' => 3,
            'planned_domains' => 3,
            'as_of' => '2026-09-28T15:01:00Z',
            'last_valid_observation_at' => '2026-09-28T15:00:50Z',
            'valid_until' => '2026-09-28T15:03:02Z',
            'policy_revision' => 'critical-v1',
            'evaluation' => json_encode(['lab' => true]),
        ], $sobrepor));
    }
}
