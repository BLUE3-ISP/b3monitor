<?php

declare(strict_types=1);

namespace App\Work\Handlers;

use App\Decision\Decider;
use App\Work\Handler;
use DateTimeImmutable;
use stdClass;

/**
 * A fila `decide`: roda o motor sobre o (check, família IP) do pedido, projeta o estado e decide o incidente, tudo na
 * transação do passo do worker (a mesma que conclui o item). Idempotente: um pedido repetido reavalia o mesmo estado e não
 * muda nada.
 */
final class DecideHandler implements Handler
{
    public function __construct(private readonly Decider $decider) {}

    public function handle(stdClass $item, DateTimeImmutable $now): void
    {
        $this->decider->decide($item->check_id, $item->ip_family, $now);
    }
}
