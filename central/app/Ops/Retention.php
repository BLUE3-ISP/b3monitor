<?php

declare(strict_types=1);

namespace App\Ops;

use InvalidArgumentException;

/**
 * As três janelas de retenção e a relação que as mantém coerentes (C-02 do 2.0; 06 §3 e §9).
 *
 * - aceitação histórica: idade máxima de um resultado que a central aceita;
 * - bruto: quanto a amostra fica, pelo menos aceitação + 7 dias;
 * - ledger de receipts: pelo menos bruto + 7 dias.
 *
 * O ledger tem de viver além da janela de aceitação: depois do expurgo, a regra de idade é o que impede um
 * resultado antigo de ser aceito de novo, e o ledger é o que o reconhece como duplicata durante o retry.
 */
final readonly class Retention
{
    public const MARGEM_DIAS = 7;

    public function __construct(
        public int $acceptanceDays,
        public int $rawDays,
        public int $ledgerDays,
    ) {
        if ($acceptanceDays < 1) {
            throw new InvalidArgumentException("Retention: a aceitação histórica deve ser de pelo menos 1 dia, recebeu $acceptanceDays.");
        }
        if ($rawDays < $acceptanceDays + self::MARGEM_DIAS) {
            throw new InvalidArgumentException(
                "Retention: a retenção bruta ($rawDays d) deve ser pelo menos a aceitação ($acceptanceDays d) + ".self::MARGEM_DIAS.' d.'
            );
        }
        if ($ledgerDays < $rawDays + self::MARGEM_DIAS) {
            throw new InvalidArgumentException(
                "Retention: o ledger de receipts ($ledgerDays d) deve ser pelo menos a retenção bruta ($rawDays d) + ".self::MARGEM_DIAS.' d.'
            );
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            (int) config('b3mon.retention.acceptance_days'),
            (int) config('b3mon.retention.raw_days'),
            (int) config('b3mon.retention.ledger_days'),
        );
    }
}
