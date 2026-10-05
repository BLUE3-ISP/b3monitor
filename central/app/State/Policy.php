<?php

declare(strict_types=1);

namespace App\State;

use InvalidArgumentException;

/**
 * Política versionada do motor (03 §12). Imutável: uma mudança é uma revisão nova, e o motor nunca
 * aplica retroativamente uma regra nova a um instante já observado (03 §9.3).
 *
 * Todo número aqui é parâmetro inicial de homologação, não SLA.
 */
final readonly class Policy
{
    public function __construct(
        public string $revision,
        public int $intervalS,
        public int $failureCount,
        public int $recoveryCount,
        public int $startToleranceS,
        public int $transportMarginS,
        public int $minDomains,
    ) {
        foreach (['intervalS' => $intervalS, 'failureCount' => $failureCount, 'recoveryCount' => $recoveryCount,
            'minDomains' => $minDomains] as $campo => $valor) {
            if ($valor < 1) {
                throw new InvalidArgumentException("Policy: $campo deve ser pelo menos 1, recebeu $valor.");
            }
        }
        if ($startToleranceS < 0 || $transportMarginS < 0) {
            throw new InvalidArgumentException('Policy: tolerância e margem de transporte não podem ser negativas.');
        }
    }

    /**
     * O perfil crítico inicial (03 §12): 30 s, 3 falhas e 3 recuperações, tolerância de início de 20%
     * do intervalo e mínimo de 2 domínios. A margem de transporte de 6 s é uma escolha deste perfil,
     * não do pacote: o 2.0 manda ter a margem e não diz quanto.
     */
    public static function critical(): self
    {
        return new self('critical-v1', 30, 3, 3, 6, 6, 2);
    }

    /**
     * Frescor: idade máxima de uma observação para representar o PRESENTE (C-01 do 2.0).
     *
     * `(failure_count + 1) × interval + tolerância de início + margem de transporte`. O 03 §12 do 1.0
     * dava 90 s fixos, exatamente 3 slots, e a confirmação oscilava no limite. Aqui o frescor é função
     * da política, e por construção sempre maior que o tempo para confirmar uma queda.
     *
     * ⚠️ A REVISAO diz que isto dá "cerca de 105 a 120 s" para 30 s, e a fórmula dá 132 s com este perfil
     * (4 × 30 + 6 + 6): os dois não batem, e o "105 a 120" é `failure_count × interval + tolerância +
     * margem`. A fórmula é a parte normativa e é a usada; a divergência está em `.loop/ASSUMPTIONS.md`.
     */
    public function freshnessS(): int
    {
        return ($this->failureCount + 1) * $this->intervalS + $this->startToleranceS + $this->transportMarginS;
    }
}
