<?php

use App\Ops\Glossario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Outbox de notificação, tentativas e auditoria (06 §2 e §10; B2 da fila).
|
| A intenção de notificar nasce NA MESMA TRANSAÇÃO que a transição de estado (outbox transacional), e a unicidade
| `(incident_id, decision_version, reason, channel, recipient)` do glossário 14 §2 é uma chave única do banco: a
| decisão lógica é deduplicada mesmo se o código tentar gravá-la duas vezes. O ENVIO externo continua sendo pelo
| menos uma vez, quando o provedor não oferece idempotência (03 §9.4).
*/
return new class extends Migration
{
    public function up(): void
    {
        $canais = Glossario::sql(Glossario::channel());
        $tentativas = Glossario::sql(Glossario::attemptStatus());

        DB::unprepared(<<<SQL
            CREATE TABLE notification_outbox (
                id                bigserial     PRIMARY KEY,
                incident_id       uuid          NOT NULL REFERENCES incidents (id),
                decision_version  integer       NOT NULL CHECK (decision_version >= 1),
                reason            varchar(64)   NOT NULL,
                channel           varchar(16)   NOT NULL CHECK (channel IN ($canais)),
                recipient         varchar(255)  NOT NULL,
                payload           jsonb         NOT NULL,
                -- O canal `dev` é SIMULADO e nunca leva token real: o banco o identifica, em vez de a aplicação.
                simulated         boolean       GENERATED ALWAYS AS (channel = 'dev') STORED,
                status            varchar(16)   NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'done')),
                attempts          integer       NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                available_at      timestamptz   NOT NULL DEFAULT now(),
                lease_owner       varchar(128)  NULL,
                lease_expires_at  timestamptz   NULL,
                created_at        timestamptz   NOT NULL DEFAULT now(),
                processed_at      timestamptz   NULL,
                UNIQUE (incident_id, decision_version, reason, channel, recipient),
                CHECK ((status = 'processing') = (lease_owner IS NOT NULL AND lease_expires_at IS NOT NULL)),
                CHECK ((status = 'done') = (processed_at IS NOT NULL))
            )
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE INDEX notification_outbox_fila_idx ON notification_outbox (available_at, id)
            WHERE status IN ('pending', 'processing')
        SQL);

        DB::unprepared(<<<SQL
            CREATE TABLE notification_attempts (
                id                   bigserial    PRIMARY KEY,
                outbox_id            bigint       NOT NULL REFERENCES notification_outbox (id),
                attempt_no           integer      NOT NULL CHECK (attempt_no >= 1),
                provider             varchar(64)  NOT NULL,
                status               varchar(32)  NOT NULL CHECK (status IN ($tentativas)),
                provider_message_id  varchar(255) NULL,
                error_code           varchar(64)  NULL,
                attempted_at         timestamptz  NOT NULL DEFAULT now(),
                UNIQUE (outbox_id, attempt_no)
            )
        SQL);
        // `accepted_by_provider` não é "entregue ao dispositivo" nem "lido": o histórico de tentativas não se reescreve.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER notification_attempts_imutavel BEFORE UPDATE OR DELETE ON notification_attempts
            FOR EACH ROW EXECUTE FUNCTION b3mon_forbid_change()
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TABLE audit_events (
                id              bigserial    PRIMARY KEY,
                actor_type      varchar(16)  NOT NULL CHECK (actor_type IN ('user', 'probe', 'system', 'watchdog')),
                actor_id        varchar(128) NULL,
                action          varchar(96)  NOT NULL,
                resource_type   varchar(64)  NOT NULL,
                resource_id     varchar(128) NULL,
                at              timestamptz  NOT NULL DEFAULT now(),
                diff            jsonb        NULL,
                correlation_id  uuid         NULL
            )
        SQL);
        DB::unprepared('CREATE INDEX audit_events_resource_idx ON audit_events (resource_type, resource_id, at)');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_events_imutavel BEFORE UPDATE OR DELETE ON audit_events
            FOR EACH ROW EXECUTE FUNCTION b3mon_forbid_change()
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS audit_events');
        DB::unprepared('DROP TABLE IF EXISTS notification_attempts');
        DB::unprepared('DROP TABLE IF EXISTS notification_outbox');
    }
};
