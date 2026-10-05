<?php

declare(strict_types=1);

namespace App\State;

use DateTimeImmutable;

/**
 * O resultado do motor para uma verificação × família IP, num instante.
 *
 * Dimensões separadas (03 §1): saúde, abrangência e cobertura não se fundem em um semáforo. `asOf` é o
 * instante em que a conclusão foi calculada, que NÃO é o instante da medição.
 */
final readonly class Evaluation
{
    /**
     * @param  list<PerspectiveState>  $perspectives
     * @param  list<string>  $conflictedDomains  Domínios cujas perspectivas elegíveis discordam: sem voto.
     */
    public function __construct(
        public string $policyRevision,
        public DateTimeImmutable $asOf,
        public Health $health,
        public Scope $scope,
        public Coverage $coverage,
        public int $validDomains,
        public int $plannedDomains,
        public array $conflictedDomains,
        public array $perspectives,
    ) {}

    /** @return list<Transition> todas as transições confirmadas, em ordem de instante e depois de perspectiva */
    public function transitions(): array
    {
        $todas = [];
        foreach ($this->perspectives as $p) {
            array_push($todas, ...$p->transitions);
        }
        usort($todas, fn (Transition $a, Transition $b) => [$a->at->getTimestamp(), $a->perspectiveId]
            <=> [$b->at->getTimestamp(), $b->perspectiveId]);

        return $todas;
    }
}
