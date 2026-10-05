<?php

declare(strict_types=1);

namespace App\State;

use DateTimeImmutable;

/**
 * Uma mudança de estado CONFIRMADO de uma perspectiva, no slot da amostra que a confirmou.
 *
 * Quem decide o que fazer com ela (abrir incidente, notificar) é o item B5, e não o motor: o motor só
 * relata, de forma determinística, e nunca envia nada.
 */
final readonly class Transition
{
    public function __construct(
        public string $perspectiveId,
        public Health $from,
        public Health $to,
        public DateTimeImmutable $at,
    ) {}
}
