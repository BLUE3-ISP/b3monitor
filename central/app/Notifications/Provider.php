<?php

declare(strict_types=1);

namespace App\Notifications;

use stdClass;

/**
 * Um provedor de entrega de um canal. O worker `notify` chama `send` DEPOIS do commit da decisão, fora da transação do
 * banco que gravou a intenção, e a entrega é pelo menos uma vez: um timeout depois do aceite remoto repete a chamada
 * (03 §9.4). Por isso `send` recebe uma chave de idempotência estável, a repassar ao provedor quando ele a aceita.
 */
interface Provider
{
    /** Nome registrado em `notification_attempts.provider`. */
    public function name(): string;

    /**
     * @param  stdClass  $outbox  a linha da outbox (`payload` ainda é JSON em texto)
     * @param  string  $idempotencyKey  estável entre as repetições da mesma intenção
     * @return string o id da mensagem no provedor, quando há
     */
    public function send(stdClass $outbox, string $idempotencyKey): string;
}
