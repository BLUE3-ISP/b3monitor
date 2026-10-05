<?php

use App\Ops\Glossario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Sondas, credenciais e assignments, no mínimo que a ingestão precisa (B3 da fila; 06 §7 e §11).
|
| A credencial de sonda é um token aleatório mostrado UMA vez; o banco guarda só o SHA-256 dele. Ela só serve aos
| endpoints da sonda e nunca acessa APIs humanas (06 §7). O cadastro administrativo completo, com aprovação e
| rotação, é do F0-05: aqui está o que a ingestão exige para dizer "este lote é desta sonda".
*/
return new class extends Migration
{
    public function up(): void
    {
        $familias = Glossario::sql(Glossario::ipFamily());

        DB::unprepared(<<<'SQL'
            CREATE TABLE probes (
                id                  uuid         PRIMARY KEY,
                name                varchar(128) NOT NULL UNIQUE,
                observation_domain  varchar(128) NOT NULL,
                status              varchar(16)  NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'revoked')),
                created_at          timestamptz  NOT NULL DEFAULT now(),
                updated_at          timestamptz  NOT NULL DEFAULT now()
            )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TABLE probe_credentials (
                id            uuid         PRIMARY KEY,
                probe_id      uuid         NOT NULL REFERENCES probes (id),
                token_hash    char(64)     NOT NULL UNIQUE,
                created_at    timestamptz  NOT NULL DEFAULT now(),
                last_used_at  timestamptz  NULL,
                revoked_at    timestamptz  NULL
            )
        SQL);
        DB::unprepared('CREATE INDEX probe_credentials_probe_idx ON probe_credentials (probe_id)');

        DB::unprepared(<<<SQL
            CREATE TABLE assignments (
                id                  uuid         PRIMARY KEY,
                probe_id            uuid         NOT NULL REFERENCES probes (id),
                check_id            uuid         NOT NULL,
                ip_family           varchar(8)   NOT NULL CHECK (ip_family IN ($familias)),
                current_generation  integer      NOT NULL CHECK (current_generation >= 1),
                created_at          timestamptz  NOT NULL DEFAULT now(),
                updated_at          timestamptz  NOT NULL DEFAULT now()
            )
        SQL);
        DB::unprepared('CREATE INDEX assignments_probe_idx ON assignments (probe_id)');
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS assignments');
        DB::unprepared('DROP TABLE IF EXISTS probe_credentials');
        DB::unprepared('DROP TABLE IF EXISTS probes');
    }
};
