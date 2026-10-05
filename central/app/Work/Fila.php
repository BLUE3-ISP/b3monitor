<?php

declare(strict_types=1);

namespace App\Work;

use App\Work\Handlers\DecideHandler;
use App\Work\Handlers\IngestHandler;
use App\Work\Handlers\NotifyHandler;

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
     * Quem processa um item desta fila.
     *
     * @return class-string<Handler>
     */
    public function handler(): string
    {
        return match ($this) {
            self::Ingest => IngestHandler::class,
            self::Decide => DecideHandler::class,
            self::Notify => NotifyHandler::class,
        };
    }
}
