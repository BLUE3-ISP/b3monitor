<?php

declare(strict_types=1);

namespace App\Work\Handlers;

use App\Notifications\Provider;
use App\Work\Handler;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * A fila `notify`: entrega uma intenção da outbox pelo provedor do canal e registra a tentativa.
 *
 * ⚠️ O envio NÃO é transacional. O arquivo do canal `dev` (ou uma chamada HTTP, no futuro) não volta com o `ROLLBACK`, então a
 * garantia aqui é a do 03 §9.4: **pelo menos uma vez**. Se o processo morre entre o envio e o commit, a lease vence e o
 * item é enviado de novo, com a MESMA chave de idempotência (`outbox-<id>`). O que o produto garante é a decisão lógica
 * deduplicada (a unicidade da outbox) e as retentativas controladas, não "exatamente uma vez".
 *
 * Canal sem provedor configurado (`telegram`, `push` e `email` ainda não têm: dependem de credencial do dono) FALHA o item,
 * que tenta de novo e, esgotadas as tentativas, para como `dead` com o motivo. Não se descarta um aviso em silêncio.
 *
 * `accepted_by_provider` não é "entregue ao dispositivo" nem "lido por humano".
 *
 * Limitação conhecida: só a tentativa ACEITA vira linha em `notification_attempts`; a falha fica em `last_error` da outbox,
 * porque a transação do passo desfaz o que o handler gravou quando ele lança.
 */
final class NotifyHandler implements Handler
{
    /** @param  array<string, Provider>|null  $providers  por canal; null lê `b3mon.notifications.providers` */
    public function __construct(private readonly ?array $providers = null) {}

    public function handle(stdClass $item, DateTimeImmutable $now): void
    {
        $provedor = $this->provider($item->channel);

        $chave = 'outbox-'.$item->id;
        $idMensagem = $provedor->send($item, $chave);

        DB::insert(<<<'SQL'
            INSERT INTO notification_attempts (outbox_id, attempt_no, provider, status, provider_message_id)
            VALUES (?, ?, ?, 'accepted_by_provider', ?)
        SQL, [$item->id, $item->attempts, $provedor->name(), $idMensagem]);
    }

    private function provider(string $canal): Provider
    {
        $mapa = $this->providers ?? array_map(
            fn (string $classe) => app($classe),
            (array) config('b3mon.notifications.providers'),
        );

        return $mapa[$canal] ?? throw new RuntimeException("O canal `{$canal}` não tem provedor configurado.");
    }
}
