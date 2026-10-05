<?php

declare(strict_types=1);

namespace App\Decision;

use App\State\ClockStatus;
use App\State\Integrity;
use App\State\Observation;
use App\State\Outcome;
use App\State\PlannedPerspective;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Lê do banco o que o motor puro precisa: as perspectivas previstas de um (check, família IP) e as observações
 * recentes. É a ÚNICA ponte entre o PostgreSQL e o `Engine`, que continua sem banco, rede e relógio.
 *
 * Uma perspectiva é um assignment de uma sonda ATIVA: sonda suspensa ou revogada sai do previsto, e com ela o voto
 * do domínio dela (a cobertura cai, e o motor diz isso em vez de contar um voto que não vale mais).
 */
final class Inputs
{
    /**
     * @return array{planned: list<PlannedPerspective>, observations: list<Observation>}
     */
    public function load(string $checkId, string $family, DateTimeImmutable $at, int $windowS): array
    {
        $planned = array_map(
            fn (object $l) => new PlannedPerspective($l->id, $l->observation_domain, (int) $l->current_generation),
            DB::select(<<<'SQL'
                SELECT a.id, a.current_generation, p.observation_domain
                  FROM assignments a
                  JOIN probes p ON p.id = a.probe_id
                 WHERE a.check_id = ? AND a.ip_family = ? AND p.status = 'active'
                 ORDER BY a.id
            SQL, [$checkId, $family]),
        );

        // Só o agendado forma a sequência (03 §9.1): teste manual tem identidade própria e não entra. A janela em
        // `started_at` também faz a consulta podar as partições diárias de `results`.
        $de = $this->ts($at->modify("-{$windowS} seconds"));
        $ate = $this->ts($at->modify('+1 hour'));

        $observations = array_map(
            fn (object $l) => new Observation(
                $l->assignment_id,
                (int) $l->config_generation,
                new DateTimeImmutable($l->scheduled_at, new DateTimeZone('UTC')),
                Outcome::from($l->outcome),
                Integrity::from($l->probe_integrity),
                ClockStatus::from($l->clock_status),
            ),
            DB::select(<<<'SQL'
                SELECT r.assignment_id, r.config_generation, r.scheduled_at, r.outcome, r.probe_integrity, r.clock_status
                  FROM results r
                  JOIN assignments a ON a.id = r.assignment_id
                 WHERE a.check_id = ? AND a.ip_family = ? AND r.ip_family = ?
                   AND r.execution_kind = 'scheduled' AND r.scheduled_at IS NOT NULL
                   AND r.started_at >= ?::timestamptz AND r.started_at <= ?::timestamptz
                 ORDER BY r.scheduled_at, r.assignment_id
            SQL, [$checkId, $family, $family, $de, $ate]),
        );

        return ['planned' => $planned, 'observations' => $observations];
    }

    private function ts(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
