<?php

declare(strict_types=1);

namespace App\Probes;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Monta o bundle de UMA sonda (06 §4; `contracts/schemas/bundle.schema.json`).
 *
 * Três regras que moram aqui:
 *  - **Só o que é dela.** O bundle é a junção dos assignments DESTA sonda com os checks deles; um assignment de outra sonda nunca
 *    entra, e um assignment cujo check ainda não existe também não.
 *  - **Nunca política local de redes (INV-09).** O bundle SELECIONA operações; o que a VM aceita medir é a política local da sonda,
 *    que a central não vê nem amplia. Aqui não há campo para isso, e segredos entram por referência (`credential_ref`), nunca em texto.
 *  - **O jitter é determinístico por assignment e geração** (04 §3): até 10% do intervalo, derivado do hash. Mudou a geração, muda o
 *    offset; o mesmo assignment na mesma geração tem sempre o mesmo, em qualquer central, em qualquer momento.
 *
 * A `bundle_revision` vem da coluna `probes.bundle_revision`, que um gatilho do banco sobe quando um assignment da sonda ou o check
 * de um assignment dela muda: a revisão é monótona sem depender da disciplina da aplicação.
 */
final class BundleBuilder
{
    /** @return array<string, mixed> */
    public function build(string $probeId, DateTimeImmutable $agora): array
    {
        $sonda = DB::table('probes')->where('id', $probeId)->first(['bundle_revision']);

        $linhas = DB::select(<<<'SQL'
            SELECT a.id AS assignment_id, a.current_generation, a.check_id, a.ip_family,
                   c.kind, c.target, c.interval_s, c.timeout_ms, c.start_tolerance_ms, c.schedule_epoch, c.policy_revision
              FROM assignments a
              JOIN checks c ON c.id = a.check_id
             WHERE a.probe_id = ?
             ORDER BY a.id
        SQL, [$probeId]);

        $assignments = array_map(fn (object $l) => [
            'assignment_id' => $l->assignment_id,
            'config_generation' => (int) $l->current_generation,
            'check_id' => $l->check_id,
            'kind' => $l->kind,
            'ip_family' => $l->ip_family,
            'target' => json_decode((string) $l->target, true, 16, JSON_THROW_ON_ERROR),
            'schedule' => [
                'epoch' => $this->ts(new DateTimeImmutable((string) $l->schedule_epoch)),
                'interval_s' => (int) $l->interval_s,
                'jitter_offset_ms' => self::jitterMs($l->assignment_id, (int) $l->current_generation, (int) $l->interval_s),
                'start_tolerance_ms' => (int) $l->start_tolerance_ms,
                'timeout_ms' => (int) $l->timeout_ms,
            ],
            'policy_revision' => $l->policy_revision,
            'path_profile_id' => null,
            'credential_ref' => null,
        ], $linhas);

        return [
            'schema_version' => '1.0',
            'probe_id' => $probeId,
            'bundle_revision' => (int) $sonda->bundle_revision,
            'issued_at' => $this->ts($agora),
            'expires_at' => $this->ts($agora->modify('+'.(int) config('b3mon.bundle.validity_s').' seconds')),
            'assignments' => $assignments,
        ];
    }

    /** Até 10% do intervalo, derivado do assignment e da geração. */
    public static function jitterMs(string $assignmentId, int $geracao, int $intervaloS): int
    {
        $teto = intdiv($intervaloS * 1000, 10);

        return hexdec(substr(hash('sha256', $assignmentId.'|'.$geracao), 0, 8)) % ($teto + 1);
    }

    private function ts(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
