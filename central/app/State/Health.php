<?php

declare(strict_types=1);

namespace App\State;

/** Saúde observada (14 §3, `health`). Conclusão limitada às observações válidas e ao escopo. */
enum Health: string
{
    case Up = 'up';
    case Degraded = 'degraded';
    case Down = 'down';
    case Unknown = 'unknown';
}
