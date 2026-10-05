<?php

use App\Ops\Glossario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Trabalho assíncrono (D-04; B4 da fila).
|
| 1. `decision_work`: a fila `decide`. É COALESCIDA: no máximo UM pedido pendente por (check, família IP), porque
|    reavaliar o mesmo check cem vezes seguidas dá o mesmo resultado que reavaliá-lo uma vez depois do último
|    resultado. Um pedido já em processamento NÃO impede um novo: o resultado que chegou durante o processamento
|    precisa de outra avaliação.
| 2. Estado `dead` nas filas de trabalho. Um item que falha sempre (ou que derruba o worker toda vez que é
|    pego) não pode ser reenfileirado para sempre, e também não pode ser apagado: ele para, visível, com o motivo
|    em `last_error`, para alguém olhar. Dado nenhum se perde: o resultado continua no ledger.
*/
return new class extends Migration
{
    /** As filas que já existiam e ganham `dead` e `last_error`. */
    private const FILAS = ['ingestion_work', 'notification_outbox'];

    public function up(): void
    {
        $familias = Glossario::sql(Glossario::ipFamily());

        DB::unprepared(<<<SQL
            CREATE TABLE decision_work (
                id                bigserial    PRIMARY KEY,
                check_id          uuid         NOT NULL,
                ip_family         varchar(8)   NOT NULL CHECK (ip_family IN ($familias)),
                status            varchar(16)  NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'done', 'dead')),
                attempts          integer      NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                available_at      timestamptz  NOT NULL DEFAULT now(),
                lease_owner       varchar(128) NULL,
                lease_expires_at  timestamptz  NULL,
                last_error        text         NULL,
                created_at        timestamptz  NOT NULL DEFAULT now(),
                processed_at      timestamptz  NULL,
                CHECK ((status = 'processing') = (lease_owner IS NOT NULL AND lease_expires_at IS NOT NULL)),
                CHECK ((status = 'done') = (processed_at IS NOT NULL))
            )
        SQL);
        // A coalescência mora no banco: dois workers de ingestão concorrentes não criam dois pedidos pendentes.
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX decision_work_pendente_uq ON decision_work (check_id, ip_family) WHERE status = 'pending'
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE INDEX decision_work_fila_idx ON decision_work (available_at, id)
            WHERE status IN ('pending', 'processing')
        SQL);

        foreach (self::FILAS as $tabela) {
            DB::unprepared("ALTER TABLE $tabela ADD COLUMN last_error text NULL");
            DB::unprepared("ALTER TABLE $tabela DROP CONSTRAINT {$tabela}_status_check");
            DB::unprepared(
                "ALTER TABLE $tabela ADD CONSTRAINT {$tabela}_status_check ".
                "CHECK (status IN ('pending', 'processing', 'done', 'dead'))"
            );
        }
    }

    public function down(): void
    {
        foreach (self::FILAS as $tabela) {
            // Um item `dead` não tem lugar no esquema antigo: volta a pendente, para ser tentado de novo.
            DB::unprepared("UPDATE $tabela SET status = 'pending' WHERE status = 'dead'");
            DB::unprepared("ALTER TABLE $tabela DROP CONSTRAINT {$tabela}_status_check");
            DB::unprepared(
                "ALTER TABLE $tabela ADD CONSTRAINT {$tabela}_status_check ".
                "CHECK (status IN ('pending', 'processing', 'done'))"
            );
            DB::unprepared("ALTER TABLE $tabela DROP COLUMN last_error");
        }
        DB::unprepared('DROP TABLE IF EXISTS decision_work');
    }
};
