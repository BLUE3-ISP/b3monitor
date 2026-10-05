<?php

declare(strict_types=1);

namespace App\State;

/** Resultado de uma execução (14 §3, `outcome`; 03 §2). */
enum Outcome: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case ExecutorError = 'executor_error';
    case ConfigError = 'config_error';
    case PolicyDenied = 'policy_denied';
    case CapabilityUnavailable = 'capability_unavailable';
    case Skipped = 'skipped';

    /** Só `pass` e `fail` medem o alvo. O resto é incapacidade de medir, e nunca é falha do alvo. */
    public function measuresTarget(): bool
    {
        return $this === self::Pass || $this === self::Fail;
    }
}
