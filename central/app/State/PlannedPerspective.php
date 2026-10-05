<?php

declare(strict_types=1);

namespace App\State;

/**
 * Uma perspectiva prevista: origem × família × caminho, dentro de um domínio de observação (03 §5).
 *
 * Duas sondas do mesmo `domain` dão no máximo UM voto: reiniciar uma sonda ou duplicar uma VM no mesmo
 * domínio não cria voto novo. `currentGeneration` é a geração vigente do assignment; as demais só
 * servem ao histórico.
 */
final readonly class PlannedPerspective
{
    public function __construct(
        public string $id,
        public string $domain,
        public int $currentGeneration,
    ) {}
}
