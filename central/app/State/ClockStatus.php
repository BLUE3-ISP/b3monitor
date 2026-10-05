<?php

declare(strict_types=1);

namespace App\State;

/** Estado do relógio da sonda (14 §3, `clock_status`). Desconhecido nunca vale como zero. */
enum ClockStatus: string
{
    case Synchronized = 'synchronized';
    case Unsynchronized = 'unsynchronized';
    case Unknown = 'unknown';
}
