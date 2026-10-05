<?php

declare(strict_types=1);

namespace App\Decision;

use App\State\Engine;
use App\State\Policy;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Uma decisão sobre um (check, família IP): carrega o que o motor precisa, avalia, projeta o estado e decide o
 * incidente. Tudo na transação de quem chama (o `Worker` da fila `decide`).
 *
 * Duas avaliações do MESMO check ao mesmo tempo (um pedido em processamento e outro pendente, em dois workers) são
 * serializadas por um lock consultivo de transação: sem ele, as duas veriam "nenhum incidente aberto" e a segunda
 * estouraria o índice único do incidente em vez de simplesmente não fazer nada.
 */
final class Decider
{
    public function __construct(
        private readonly Inputs $inputs,
        private readonly Engine $engine,
        private readonly StateProjector $projector,
        private readonly IncidentManager $incidents,
    ) {}

    /** @return array{health: string, incident: string|null} */
    public function decide(string $checkId, string $family, DateTimeImmutable $at, ?Policy $policy = null): array
    {
        $policy ??= $this->policyOf($checkId);

        DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["decide:{$checkId}:{$family}"]);

        // Nunca regredir o estado: uma avaliação MAIS VELHA que a projeção atual é descartada. Acontece quando dois pedidos
        // da mesma verificação rodam fora de ordem (relógios de workers diferentes, retentativa tardia). Sem isto, avaliar
        // "no passado" depois de o incidente ter fechado o REABRIRIA, e avisaria uma queda que já acabou.
        $atual = DB::selectOne(
            'SELECT health, as_of FROM current_states WHERE check_id = ? AND ip_family = ?',
            [$checkId, $family],
        );
        if ($atual !== null && new DateTimeImmutable($atual->as_of) > $at) {
            return ['health' => $atual->health, 'incident' => null];
        }

        $in = $this->inputs->load($checkId, $family, $at, (int) config('b3mon.decide.window_s'));
        $avaliacao = $this->engine->evaluate($policy, $in['planned'], $in['observations'], $at);

        $this->projector->project($checkId, $family, $avaliacao, $policy);
        $incidente = $this->incidents->apply($checkId, $family, $avaliacao, $policy);

        return ['health' => $avaliacao->health->value, 'incident' => $incidente];
    }

    /**
     * A política do check: a cadência e a tolerância de início são as DELE. Sem a definição do check (os testes do motor, e o que existia
     * antes do cadastro), vale o perfil crítico de 30 s.
     */
    private function policyOf(string $checkId): Policy
    {
        $c = DB::table('checks')->where('id', $checkId)->first(['interval_s', 'start_tolerance_ms', 'policy_revision']);

        return $c === null
            ? Policy::critical()
            : Policy::forCheck((int) $c->interval_s, (int) $c->start_tolerance_ms, (string) $c->policy_revision);
    }
}
