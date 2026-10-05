<?php

use App\Ops\Glossario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Resultados, ledger de receipts e trabalho de ingestão (06 §2 e §3; B2 da fila).
|
| `results` é particionada por DIA de `started_at`. Em PostgreSQL toda chave única de uma tabela particionada
| tem de incluir a coluna de partição, e uma chave estrangeira APONTANDO para ela não é possível. Por isso:
|   - a idempotência global NÃO mora em `results`, mora em `result_receipts` (ledger sem partição, com
|     retenção própria e MAIOR que a do bruto);
|   - ninguém tem FK para `results`; `ingestion_work` guarda `result_id` e `result_started_at` e acha a linha
|     pela chave completa.
| Resultados são IMUTÁVEIS: um gatilho recusa UPDATE e DELETE. O expurgo é por partição inteira (DROP), nunca
| por linha (06 §12).
*/
return new class extends Migration
{
    public function up(): void
    {
        $familias = Glossario::sql(Glossario::ipFamily());
        $tipos = Glossario::sql(Glossario::executionKind());
        $resultados = Glossario::sql(Glossario::outcome());
        $razoes = Glossario::sql(Glossario::reasonCode());
        $relogio = Glossario::sql(Glossario::clockStatus());
        $integridade = Glossario::sql(Glossario::integrity());

        // 1. Funções de apoio: imutabilidade e criação idempotente de partições.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION b3mon_forbid_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% é imutável: % não é permitido', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'integrity_constraint_violation';
            END
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION b3mon_ensure_result_partitions(first_day date, last_day date) RETURNS integer
            LANGUAGE plpgsql AS $$
            DECLARE
                d date;
                nome text;
                criadas integer := 0;
            BEGIN
                IF last_day < first_day THEN
                    RAISE EXCEPTION 'intervalo de partições invertido: % a %', first_day, last_day;
                END IF;
                FOR d IN SELECT g::date FROM generate_series(first_day, last_day, interval '1 day') AS g LOOP
                    nome := 'results_p' || to_char(d, 'YYYYMMDD');
                    IF to_regclass(nome) IS NULL THEN
                        EXECUTE format(
                            'CREATE TABLE %I PARTITION OF results FOR VALUES FROM (%L) TO (%L)',
                            nome,
                            to_char(d, 'YYYY-MM-DD') || ' 00:00:00+00',
                            to_char(d + 1, 'YYYY-MM-DD') || ' 00:00:00+00'
                        );
                        criadas := criadas + 1;
                    END IF;
                END LOOP;
                RETURN criadas;
            END
            $$;
        SQL);

        // 2. results: o payload normalizado imutável, particionado por dia de started_at.
        DB::unprepared(<<<SQL
            CREATE TABLE results (
                result_id          uuid        NOT NULL,
                probe_id           uuid        NOT NULL,
                assignment_id      uuid        NOT NULL,
                config_generation  integer     NOT NULL CHECK (config_generation >= 1),
                policy_revision    varchar(128) NOT NULL,
                execution_kind     varchar(16) NOT NULL CHECK (execution_kind IN ($tipos)),
                manual_request_id  uuid        NULL,
                scheduled_at       timestamptz NULL,
                started_at         timestamptz NOT NULL,
                finished_at        timestamptz NOT NULL,
                duration_ms        integer     NOT NULL CHECK (duration_ms >= 0),
                ip_family          varchar(8)  NOT NULL CHECK (ip_family IN ($familias)),
                outcome            varchar(32) NOT NULL CHECK (outcome IN ($resultados)),
                reason_code        varchar(48) NOT NULL CHECK (reason_code IN ($razoes)),
                clock_status       varchar(16) NOT NULL CHECK (clock_status IN ($relogio)),
                probe_integrity    varchar(16) NOT NULL CHECK (probe_integrity IN ($integridade)),
                payload            jsonb       NOT NULL,
                content_hash       char(64)    NOT NULL,
                received_at        timestamptz NOT NULL,
                PRIMARY KEY (result_id, started_at),
                CHECK (finished_at >= started_at),
                -- Agendado tem slot e não tem manual_request_id; manual é o inverso (06 §3 e §12).
                CHECK (
                    (execution_kind = 'scheduled' AND scheduled_at IS NOT NULL AND manual_request_id IS NULL)
                    OR (execution_kind = 'manual' AND scheduled_at IS NULL AND manual_request_id IS NOT NULL)
                    OR (execution_kind = 'canary')
                )
            ) PARTITION BY RANGE (started_at)
        SQL);

        DB::unprepared('CREATE INDEX results_assignment_slot_idx ON results (assignment_id, scheduled_at)');
        DB::unprepared('CREATE INDEX results_received_idx ON results (received_at)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER results_imutavel BEFORE UPDATE OR DELETE ON results
            FOR EACH ROW EXECUTE FUNCTION b3mon_forbid_change()
        SQL);

        // Partições preparadas com antecedência (06 §9): ontem até hoje + days_ahead, em UTC.
        $atras = (int) config('b3mon.partitions.days_behind', 1);
        $frente = (int) config('b3mon.partitions.days_ahead', 7);
        DB::select(
            "select b3mon_ensure_result_partitions((now() at time zone 'utc')::date - ?::integer, (now() at time zone 'utc')::date + ?::integer)",
            [$atras, $frente]
        );

        // 3. result_receipts: o ledger de idempotência GLOBAL (06 §3). Sem partição, retenção própria e maior.
        DB::unprepared(<<<SQL
            CREATE TABLE result_receipts (
                result_id          uuid        PRIMARY KEY,
                probe_id           uuid        NOT NULL,
                assignment_id      uuid        NOT NULL,
                config_generation  integer     NOT NULL CHECK (config_generation >= 1),
                execution_kind     varchar(16) NOT NULL CHECK (execution_kind IN ($tipos)),
                scheduled_at       timestamptz NULL,
                manual_request_id  uuid        NULL,
                content_hash       char(64)    NOT NULL,
                result_started_at  timestamptz NOT NULL,
                received_at        timestamptz NOT NULL,
                retain_until       timestamptz NOT NULL,
                CHECK (retain_until > received_at)
            )
        SQL);

        // A chave natural é única GLOBALMENTE: duas execuções do mesmo slot são o mesmo resultado.
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX result_receipts_slot_uq
            ON result_receipts (probe_id, assignment_id, config_generation, scheduled_at)
            WHERE scheduled_at IS NOT NULL
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX result_receipts_manual_uq
            ON result_receipts (probe_id, assignment_id, config_generation, manual_request_id)
            WHERE manual_request_id IS NOT NULL
        SQL);
        DB::unprepared('CREATE INDEX result_receipts_retain_idx ON result_receipts (retain_until)');

        // 4. ingestion_work: trabalho durável, consumido com FOR UPDATE SKIP LOCKED (D-04; item B4).
        DB::unprepared(<<<'SQL'
            CREATE TABLE ingestion_work (
                id                 bigserial   PRIMARY KEY,
                result_id          uuid        NOT NULL UNIQUE REFERENCES result_receipts (result_id),
                result_started_at  timestamptz NOT NULL,
                status             varchar(16) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'done')),
                attempts           integer     NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                available_at       timestamptz NOT NULL DEFAULT now(),
                lease_owner        varchar(128) NULL,
                lease_expires_at   timestamptz NULL,
                created_at         timestamptz NOT NULL DEFAULT now(),
                processed_at       timestamptz NULL,
                CHECK ((status = 'processing') = (lease_owner IS NOT NULL AND lease_expires_at IS NOT NULL)),
                CHECK ((status = 'done') = (processed_at IS NOT NULL))
            )
        SQL);
        // Só o que ainda falta processar entra no índice que o consumidor percorre.
        DB::unprepared(<<<'SQL'
            CREATE INDEX ingestion_work_fila_idx ON ingestion_work (available_at, id)
            WHERE status IN ('pending', 'processing')
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS ingestion_work');
        DB::unprepared('DROP TABLE IF EXISTS result_receipts');
        DB::unprepared('DROP TABLE IF EXISTS results CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS b3mon_ensure_result_partitions(date, date)');
        DB::unprepared('DROP FUNCTION IF EXISTS b3mon_forbid_change()');
    }
};
