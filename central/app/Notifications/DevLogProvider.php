<?php

declare(strict_types=1);

namespace App\Notifications;

use RuntimeException;
use stdClass;

/**
 * O canal `dev` (`provider=dev-log`): escreve a notificação num arquivo de log e NADA MAIS. É **simulado**, e se
 * identifica como tal em cada linha (`"simulated":true`, prefixo `[SIMULADO]`), para ninguém confundi-lo com um aviso
 * que chegou a um humano. Não usa token, rede nem credencial.
 *
 * Em `notification_outbox` a coluna `simulated` é gerada pelo banco (`channel = 'dev'`), então a marca não depende de
 * este código lembrar de escrevê-la.
 *
 * A repetição da entrega aparece como duas linhas com a mesma `idempotency_key`: é o "pelo menos uma vez" visível.
 */
final class DevLogProvider implements Provider
{
    public function __construct(private readonly ?string $path = null) {}

    public function name(): string
    {
        return 'dev-log';
    }

    public function send(stdClass $outbox, string $idempotencyKey): string
    {
        $caminho = $this->path ?? (string) config('b3mon.notifications.dev_log_path');

        $linha = '[SIMULADO] '.json_encode([
            'simulated' => true,
            'provider' => $this->name(),
            'idempotency_key' => $idempotencyKey,
            'channel' => $outbox->channel,
            'recipient' => $outbox->recipient,
            'reason' => $outbox->reason,
            'decision_version' => (int) $outbox->decision_version,
            'incident_id' => $outbox->incident_id,
            'payload' => json_decode((string) $outbox->payload, true),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        if (@file_put_contents($caminho, $linha, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Não foi possível escrever em {$caminho}.");
        }

        return 'dev-log:'.$idempotencyKey;
    }
}
