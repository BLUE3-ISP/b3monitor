<?php

declare(strict_types=1);

namespace App\State;

/** Abrangência (14 §3, `scope`). `AllObserved` não significa toda a internet. */
enum Scope: string
{
    case None = 'none';
    case Partial = 'partial';
    case AllObserved = 'all_observed';
    case Unknown = 'unknown';
}
