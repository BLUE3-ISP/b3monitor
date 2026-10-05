<?php

declare(strict_types=1);

namespace App\Decision;

use App\State\Evaluation;
use App\State\Health;
use App\State\Policy;
use Illuminate\Support\Facades\DB;

/**
 * Grava a avaliação do motor: `current_states` (a PROJEÇÃO, que pode ser refeita dos resultados) e `state_events`
 * (o histórico das transições, imutável).
 *
 * Idempotente. As transições por perspectiva trazem o instante ORIGINAL do slot, então reavaliar o mesmo conjunto de
 * resultados cai no índice único de `state_events` e não duplica nada. É também aqui que o replay recompõe o histórico:
 * as transições antigas entram com a data em que aconteceram, e quem decide se isso vira aviso é o `IncidentManager`,
 * olhando só o PRESENTE.
 */
final class StateProjector
{
    /**
     * @return Health|null a saúde agregada ANTERIOR, ou null na primeira avaliação desta verificação
     */
    public function project(string $checkId, string $family, Evaluation $e, Policy $policy): ?Health
    {
        $anterior = DB::selectOne(
            'SELECT health, scope, coverage, valid_domains, planned_domains FROM current_states WHERE check_id = ? AND ip_family = ? FOR UPDATE',
            [$checkId, $family],
        );

        $ultimo = null;
        foreach ($e->perspectives as $p) {
            if ($p->lastEligibleSlot !== null && ($ultimo === null || $p->lastEligibleSlot > $ultimo)) {
                $ultimo = $p->lastEligibleSlot;
            }
        }
        $validade = $ultimo?->modify('+'.$policy->freshnessS().' seconds');

        $evidencia = json_encode([
            'conflicted_domains' => $e->conflictedDomains,
            'perspectives' => array_map(fn ($p) => [
                'id' => $p->perspectiveId,
                'domain' => $p->domain,
                'health' => $p->health->value,
                'pending' => $p->pending->value,
                'last_eligible_slot' => $p->lastEligibleSlot?->format('Y-m-d\TH:i:s\Z'),
                'reason' => $p->reason?->value,
            ], $e->perspectives),
        ], JSON_THROW_ON_ERROR);

        $dados = [
            $e->health->value, $e->scope->value, $e->coverage->value, $e->validDomains, $e->plannedDomains,
            $e->asOf->format('Y-m-d\TH:i:s\Z'), $ultimo?->format('Y-m-d\TH:i:s\Z'), $validade?->format('Y-m-d\TH:i:s\Z'),
            $policy->revision, $evidencia,
        ];

        if ($anterior === null) {
            DB::insert(<<<'SQL'
                INSERT INTO current_states (health, scope, coverage, valid_domains, planned_domains, as_of,
                    last_valid_observation_at, valid_until, policy_revision, evaluation, check_id, ip_family)
                VALUES (?, ?, ?, ?, ?, ?::timestamptz, ?::timestamptz, ?::timestamptz, ?, ?::jsonb, ?, ?)
            SQL, [...$dados, $checkId, $family]);
        } else {
            // A versão só sobe quando algo MATERIAL muda: reavaliar sem mudança não é um estado novo.
            $mudou = $anterior->health !== $e->health->value || $anterior->scope !== $e->scope->value
                || $anterior->coverage !== $e->coverage->value || (int) $anterior->valid_domains !== $e->validDomains
                || (int) $anterior->planned_domains !== $e->plannedDomains;

            DB::update(<<<'SQL'
                UPDATE current_states
                   SET health = ?, scope = ?, coverage = ?, valid_domains = ?, planned_domains = ?, as_of = ?::timestamptz,
                       last_valid_observation_at = ?::timestamptz, valid_until = ?::timestamptz, policy_revision = ?,
                       evaluation = ?::jsonb, version = version + ?, updated_at = now()
                 WHERE check_id = ? AND ip_family = ?
            SQL, [...$dados, $mudou ? 1 : 0, $checkId, $family]);
        }

        foreach ($e->transitions() as $t) {
            DB::insert(<<<'SQL'
                INSERT INTO state_events (check_id, ip_family, perspective_id, from_state, to_state, at, policy_revision)
                VALUES (?, ?, ?, ?, ?, ?::timestamptz, ?)
                ON CONFLICT DO NOTHING
            SQL, [$checkId, $family, $t->perspectiveId, $t->from->value, $t->to->value, $t->at->format('Y-m-d\TH:i:s\Z'), $policy->revision]);
        }

        $antes = $anterior === null ? null : Health::from($anterior->health);

        // A mudança da saúde AGREGADA também é um evento (sem perspectiva). Na primeira avaliação, "de unknown".
        if (($antes ?? Health::Unknown) !== $e->health) {
            DB::insert(<<<'SQL'
                INSERT INTO state_events (check_id, ip_family, perspective_id, from_state, to_state, at, policy_revision)
                VALUES (?, ?, NULL, ?, ?, ?::timestamptz, ?)
                ON CONFLICT DO NOTHING
            SQL, [$checkId, $family, ($antes ?? Health::Unknown)->value, $e->health->value, $e->asOf->format('Y-m-d\TH:i:s\Z'), $policy->revision]);
        }

        return $antes;
    }
}
