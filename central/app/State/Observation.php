<?php

declare(strict_types=1);

namespace App\State;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Uma observação já aceita pela ingestão, reduzida ao que o motor decide (03 §3).
 *
 * `slot` é o `scheduled_at`: o instante AGENDADO, parte da chave natural do resultado. Ordena-se por ele,
 * nunca pela ordem de chegada.
 */
final readonly class Observation
{
    public DateTimeImmutable $slot;

    public function __construct(
        public string $perspectiveId,
        public int $generation,
        DateTimeImmutable $slot,
        public Outcome $outcome,
        public Integrity $integrity = Integrity::Valid,
        public ClockStatus $clock = ClockStatus::Synchronized,
    ) {
        $this->slot = $slot->setTimezone(new DateTimeZone('UTC'));
    }

    /** Duas observações do mesmo slot são "a mesma" se concordam em tudo que o motor lê. */
    public function sameContentAs(self $other): bool
    {
        return $this->outcome === $other->outcome
            && $this->integrity === $other->integrity
            && $this->clock === $other->clock;
    }
}
