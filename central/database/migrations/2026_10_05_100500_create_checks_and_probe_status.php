<?php

use App\Ops\Glossario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| O que a sonda precisa para ser configurada e vista (B7b da fila; 06 §4 e §8).
|
| 1. `checks`: a DEFINIÇÃO de uma verificação (espécie, alvo, cadência). O assignment já ligava uma sonda a um check_id, mas o
|    check em si não existia, e sem ele não há bundle. Não há FK de `assignments.check_id` para cá de propósito: o cadastro
|    administrativo (F0-05) é quem passa a exigir, e os testes de ingestão criam assignments sem check. O bundle só leva o
|    assignment cujo check existe.
| 2. `probes.bundle_revision`: a revisão do conjunto, MONÓTONA. Quem a sobe é um gatilho, e não a disciplina da aplicação: mudou
|    um assignment da sonda, ou o check de um assignment dela, a revisão sobe.
| 3. `probe_status`: o último autodiagnóstico de cada sonda (heartbeat). É o estado ATUAL, não um histórico: o histórico de
|    saúde da sonda é do F0-07.
*/
return new class extends Migration
{
    public function up(): void
    {
        $tipos = Glossario::sql(Glossario::checkKind());

        DB::unprepared(<<<SQL
            CREATE TABLE checks (
                id                  uuid         PRIMARY KEY,
                name                varchar(128) NOT NULL,
                kind                varchar(16)  NOT NULL CHECK (kind IN ($tipos)),
                target              jsonb        NOT NULL,
                interval_s          integer      NOT NULL CHECK (interval_s >= 1),
                timeout_ms          integer      NOT NULL CHECK (timeout_ms >= 1),
                start_tolerance_ms  integer      NOT NULL CHECK (start_tolerance_ms >= 0),
                schedule_epoch      timestamptz  NOT NULL,
                policy_revision     varchar(128) NOT NULL DEFAULT 'critical-v1',
                created_at          timestamptz  NOT NULL DEFAULT now(),
                updated_at          timestamptz  NOT NULL DEFAULT now(),
                -- O timeout tem de caber no intervalo (03 §12: "timeout crítico menor que o intervalo").
                CHECK (timeout_ms < interval_s * 1000)
            )
        SQL);

        DB::unprepared('ALTER TABLE probes ADD COLUMN bundle_revision integer NOT NULL DEFAULT 1 CHECK (bundle_revision >= 1)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION b3mon_bump_bundle_revision() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_TABLE_NAME = 'assignments' THEN
                    IF TG_OP IN ('UPDATE', 'DELETE') THEN
                        UPDATE probes SET bundle_revision = bundle_revision + 1 WHERE id = OLD.probe_id;
                    END IF;
                    IF TG_OP IN ('INSERT', 'UPDATE') AND (TG_OP = 'INSERT' OR NEW.probe_id IS DISTINCT FROM OLD.probe_id) THEN
                        UPDATE probes SET bundle_revision = bundle_revision + 1 WHERE id = NEW.probe_id;
                    END IF;
                ELSE
                    -- Mudou a definição de um check: sobe a revisão de toda sonda que o executa.
                    UPDATE probes SET bundle_revision = bundle_revision + 1
                     WHERE id IN (SELECT probe_id FROM assignments WHERE check_id = COALESCE(NEW.id, OLD.id));
                END IF;
                RETURN NULL;
            END $$
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER assignments_revisao AFTER INSERT OR UPDATE OR DELETE ON assignments
            FOR EACH ROW EXECUTE FUNCTION b3mon_bump_bundle_revision()
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER checks_revisao AFTER UPDATE OR DELETE ON checks
            FOR EACH ROW EXECUTE FUNCTION b3mon_bump_bundle_revision()
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TABLE probe_status (
                probe_id         uuid         PRIMARY KEY REFERENCES probes (id),
                received_at      timestamptz  NOT NULL,
                sent_at          timestamptz  NOT NULL,
                clock_skew_ms    bigint       NOT NULL,
                heartbeats       bigint       NOT NULL DEFAULT 1 CHECK (heartbeats >= 1),
                bundle_revision  integer      NULL,
                payload          jsonb        NOT NULL
            )
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS probe_status');
        DB::unprepared('DROP TRIGGER IF EXISTS checks_revisao ON checks');
        DB::unprepared('DROP TRIGGER IF EXISTS assignments_revisao ON assignments');
        DB::unprepared('DROP FUNCTION IF EXISTS b3mon_bump_bundle_revision()');
        DB::unprepared('ALTER TABLE probes DROP COLUMN IF EXISTS bundle_revision');
        DB::unprepared('DROP TABLE IF EXISTS checks');
    }
};
