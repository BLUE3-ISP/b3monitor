<?php

declare(strict_types=1);

namespace App\State;

/** Integridade da medição (14 §3, `integrity`): confiabilidade do instrumento, não saúde do alvo. */
enum Integrity: string
{
    case Valid = 'valid';
    case Suspect = 'suspect';
    case Invalid = 'invalid';
    case Unknown = 'unknown';
}
