<?php

declare(strict_types=1);

namespace App\Work;

use DateTimeImmutable;
use stdClass;

/**
 * Quem processa um item de uma fila.
 *
 * ⚠️ O contrato é AO MENOS UMA VEZ: um item pode ser entregue de novo (worker morto, lease vencida), então o
 * efeito tem de ser idempotente. O que o `Worker` garante é mais forte para efeitos no PostgreSQL: o `handle`
 * roda na MESMA transação que marca o item como `done`, sob o lock da linha e com a lease conferida, então o efeito
 * e a conclusão entram juntos ou nenhum dos dois entra. Um efeito FORA do banco (um envio de notificação) não tem
 * essa garantia e precisa de idempotência própria.
 */
interface Handler
{
    /**
     * @param  stdClass  $item  a linha da fila, como `claim()` a devolveu
     * @param  DateTimeImmutable  $now  o instante do passo, vindo de FORA: o que decide expira ou não é determinístico
     */
    public function handle(stdClass $item, DateTimeImmutable $now): void;
}
