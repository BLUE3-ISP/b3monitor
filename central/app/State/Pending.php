<?php

declare(strict_types=1);

namespace App\State;

/** Confirmação em curso (14 §3, `pending`). A interface escreve "falha em confirmação", nunca só um verde. */
enum Pending: string
{
    case None = 'none';
    case PendingFailure = 'pending_failure';
    case PendingRecovery = 'pending_recovery';
}
