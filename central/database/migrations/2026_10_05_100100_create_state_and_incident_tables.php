<?php

use App\Ops\Glossario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Estado atual, transições e incidentes (06 §2; B2 da fila).
|
| `current_states` é PROJEÇÃO, não fonte: pode ser reconstruída dos resultados. As invariantes que o banco
| consegue garantir ficam em CHECK, para que um código com defeito não grave um estado impossível, como um
| "verde" com cobertura insuficiente (INV-11).
*/
return new class extends Migration
{
    public function up(): void
    {
        $familias = Glossario::sql(Glossario::ipFamily());
        $saude = Glossario::sql(Glossario::health());
        $abrangencia = Glossario::sql(Glossario::scope());
        $cobertura = Glossario::sql(Glossario::coverage());
        $classes = Glossario::sql(Glossario::incidentClass());
        $gravidades = Glossario::sql(Glossario::severity());

        DB::unprepared(<<<SQL
            CREATE TABLE current_states (
                check_id                    uuid         NOT NULL,
                ip_family                   varchar(8)   NOT NULL CHECK (ip_family IN ($familias)),
                health                      varchar(16)  NOT NULL CHECK (health IN ($saude)),
                scope                       varchar(16)  NOT NULL CHECK (scope IN ($abrangencia)),
                coverage                    varchar(16)  NOT NULL CHECK (coverage IN ($cobertura)),
                valid_domains               integer      NOT NULL CHECK (valid_domains >= 0),
                planned_domains             integer      NOT NULL CHECK (planned_domains >= 0),
                as_of                       timestamptz  NOT NULL,
                last_valid_observation_at   timestamptz  NULL,
                valid_until                 timestamptz  NULL,
                policy_revision             varchar(128) NOT NULL,
                evaluation                  jsonb        NOT NULL,
                version                     bigint       NOT NULL DEFAULT 1 CHECK (version >= 1),
                updated_at                  timestamptz  NOT NULL DEFAULT now(),
                PRIMARY KEY (check_id, ip_family),
                CHECK (valid_domains <= planned_domains),
                -- Cobertura insuficiente não conclui nada, e só ela deixa saúde e abrangência desconhecidas (03 §5.1).
                CHECK ((coverage = 'insufficient') = (health = 'unknown')),
                CHECK ((health = 'unknown') = (scope = 'unknown')),
                -- "Calculado agora" não é "medido agora": a validade, quando existe, vem depois da medição.
                CHECK (valid_until IS NULL OR last_valid_observation_at IS NULL OR valid_until >= last_valid_observation_at)
            )
        SQL);

        DB::unprepared(<<<SQL
            CREATE TABLE state_events (
                id               bigserial    PRIMARY KEY,
                check_id         uuid         NOT NULL,
                ip_family        varchar(8)   NOT NULL CHECK (ip_family IN ($familias)),
                perspective_id   varchar(128) NULL,
                from_state       varchar(16)  NOT NULL CHECK (from_state IN ($saude)),
                to_state         varchar(16)  NOT NULL CHECK (to_state IN ($saude)),
                at               timestamptz  NOT NULL,
                policy_revision  varchar(128) NOT NULL,
                evidence         jsonb        NOT NULL DEFAULT '{}'::jsonb,
                created_at       timestamptz  NOT NULL DEFAULT now(),
                CHECK (from_state <> to_state)
            )
        SQL);
        // A mesma transição, reprocessada (replay, retry), não vira dois eventos.
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX state_events_uq
            ON state_events (check_id, ip_family, COALESCE(perspective_id, ''), at, to_state)
        SQL);
        DB::unprepared('CREATE INDEX state_events_check_idx ON state_events (check_id, ip_family, at)');

        DB::unprepared(<<<SQL
            CREATE TABLE incidents (
                id                 uuid         PRIMARY KEY,
                check_id           uuid         NOT NULL,
                ip_family          varchar(8)   NULL CHECK (ip_family IS NULL OR ip_family IN ($familias)),
                incident_class     varchar(32)  NOT NULL CHECK (incident_class IN ($classes)),
                severity           varchar(16)  NOT NULL CHECK (severity IN ($gravidades)),
                scope              varchar(16)  NOT NULL CHECK (scope IN ($abrangencia)),
                correlation_key    varchar(256) NOT NULL,
                observed_start_at  timestamptz  NOT NULL,
                confirmed_at       timestamptz  NOT NULL,
                observed_end_at    timestamptz  NULL,
                confirmed_end_at   timestamptz  NULL,
                acknowledged_at    timestamptz  NULL,
                acknowledged_by    uuid         NULL,
                owner_id           uuid         NULL,
                version            integer      NOT NULL DEFAULT 1 CHECK (version >= 1),
                created_at         timestamptz  NOT NULL DEFAULT now(),
                updated_at         timestamptz  NOT NULL DEFAULT now(),
                -- Início observado e instante de detecção são coisas diferentes (03 §7).
                CHECK (confirmed_at >= observed_start_at),
                CHECK (confirmed_end_at IS NULL OR confirmed_end_at >= confirmed_at),
                CHECK (acknowledged_at IS NULL OR acknowledged_by IS NOT NULL)
            )
        SQL);
        // Eventos consecutivos do mesmo episódio reutilizam identidade: UM incidente aberto por chave (03 §7).
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX incidents_aberto_uq ON incidents (correlation_key) WHERE confirmed_end_at IS NULL
        SQL);
        DB::unprepared('CREATE INDEX incidents_check_idx ON incidents (check_id, confirmed_at)');

        DB::unprepared(<<<'SQL'
            CREATE TABLE incident_events (
                id           bigserial    PRIMARY KEY,
                incident_id  uuid         NOT NULL REFERENCES incidents (id),
                event_type   varchar(32)  NOT NULL CHECK (event_type IN
                             ('opened', 'updated', 'recovered', 'reopened', 'acknowledged', 'owner_assigned', 'comment')),
                at           timestamptz  NOT NULL,
                payload      jsonb        NOT NULL DEFAULT '{}'::jsonb,
                created_at   timestamptz  NOT NULL DEFAULT now()
            )
        SQL);
        DB::unprepared('CREATE INDEX incident_events_incident_idx ON incident_events (incident_id, at)');
        // A linha do tempo do incidente é histórico: não se reescreve.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER incident_events_imutavel BEFORE UPDATE OR DELETE ON incident_events
            FOR EACH ROW EXECUTE FUNCTION b3mon_forbid_change()
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER state_events_imutavel BEFORE UPDATE OR DELETE ON state_events
            FOR EACH ROW EXECUTE FUNCTION b3mon_forbid_change()
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS incident_events');
        DB::unprepared('DROP TABLE IF EXISTS incidents');
        DB::unprepared('DROP TABLE IF EXISTS state_events');
        DB::unprepared('DROP TABLE IF EXISTS current_states');
    }
};
