<?php

declare(strict_types=1);

namespace App\State;

/** Cobertura (14 §3, `coverage`): perspectivas elegíveis comparadas ao plano. */
enum Coverage: string
{
    case Full = 'full';
    case Reduced = 'reduced';
    case Insufficient = 'insufficient';
}
