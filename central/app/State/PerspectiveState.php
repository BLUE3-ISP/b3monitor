<?php

declare(strict_types=1);

namespace App\State;

use DateTimeImmutable;

/** O estado de UMA perspectiva no instante avaliado (03 §4). */
final readonly class PerspectiveState
{
    /**
     * @param  Health  $health  `up`, `down` ou `unknown`: o estado PRESENTE. Nunca `degraded` aqui.
     * @param  ?Health  $lastConfirmed  O último estado confirmado, mesmo que o presente seja `unknown`.
     *                                  É histórico: nunca se apresenta como situação atual.
     * @param  ?Ineligibility  $reason  Por que o presente é `unknown`, quando é.
     * @param  list<Transition>  $transitions
     */
    public function __construct(
        public string $perspectiveId,
        public string $domain,
        public Health $health,
        public Pending $pending,
        public ?DateTimeImmutable $lastEligibleSlot,
        public ?Health $lastConfirmed,
        public ?Ineligibility $reason,
        public array $transitions,
    ) {}

    /** Esta perspectiva tem evidência recente e elegível para o presente? */
    public function isEligible(): bool
    {
        return $this->health !== Health::Unknown;
    }
}
