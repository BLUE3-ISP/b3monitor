<?php

declare(strict_types=1);

namespace App\Work;

use App\Work\Handlers\IngestHandler;

/**
 * As três filas de trabalho da central (02 §7 do 2.0; D-04). O nome da tabela vem DAQUI, de uma lista fechada, e
 * é a única coisa que entra no SQL sem parâmetro: nada que venha de fora (argumento de comando, linha de banco)
 * chega a virar nome de tabela.
 */
enum Fila: string
{
    case Ingest = 'ingest';
    case Decide = 'decide';
    case Notify = 'notify';

    public function table(): string
    {
        return match ($this) {
            self::Ingest => 'ingestion_work',
            self::Decide => 'decision_work',
            self::Notify => 'notification_outbox',
        };
    }

    /**
     * Quem processa um item desta fila, ou `null` quando ainda não existe.
     *
     * `decide` (o motor sobre o banco) e `notify` (o envio) nascem no B5, que também grava a transição de estado e
     * a intenção de notificar na mesma transação. Até lá o worker dessas filas RECUSA subir, em vez de consumir
     * itens e não fazer nada com eles.
     *
     * @return class-string<Handler>|null
     */
    public function handler(): ?string
    {
        return match ($this) {
            self::Ingest => IngestHandler::class,
            self::Decide, self::Notify => null,
        };
    }

    /** O item da fila que falta implementar, para a mensagem de recusa. */
    public function pendingItem(): string
    {
        return 'B5';
    }
}
