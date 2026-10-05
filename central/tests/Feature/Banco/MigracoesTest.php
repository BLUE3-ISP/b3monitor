<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Forward e backward testados" (B2 da fila): as migrações do produto sobem, descem e sobem de novo.
// O PostgreSQL tem DDL transacional, então tudo isto acontece dentro da transação do teste e é desfeito no fim.

const TABELAS_DO_PRODUTO = ['results', 'result_receipts', 'ingestion_work', 'current_states', 'state_events', 'incidents',
    'incident_events', 'decision_work', 'notification_outbox', 'notification_attempts', 'audit_events', 'probes', 'probe_credentials', 'assignments'];

it('desce todas as migrações do produto, remove tudo o que elas criaram, e sobe de novo', function () {
    foreach (TABELAS_DO_PRODUTO as $tabela) {
        expect(Schema::hasTable($tabela))->toBeTrue($tabela);
    }

    Artisan::call('migrate:rollback', ['--step' => count(glob(database_path('migrations/2026_*.php'))), '--force' => true]);

    foreach (TABELAS_DO_PRODUTO as $tabela) {
        expect(Schema::hasTable($tabela))->toBeFalse("$tabela deveria ter sido removida");
    }
    // As funções e as partições também: nada fica órfão (as partições caem com `DROP ... CASCADE` do pai).
    expect(DB::selectOne("select count(*) as n from pg_proc where proname in ('b3mon_forbid_change', 'b3mon_ensure_result_partitions')")->n)->toBe(0);
    expect(DB::selectOne("select count(*) as n from pg_class where relname like 'results\\_p%'")->n)->toBe(0);

    // As tabelas do esqueleto do Laravel não são tocadas por este rollback.
    expect(Schema::hasTable('users'))->toBeTrue();

    Artisan::call('migrate', ['--force' => true]);

    foreach (TABELAS_DO_PRODUTO as $tabela) {
        expect(Schema::hasTable($tabela))->toBeTrue("$tabela deveria ter voltado");
    }
    expect(DB::selectOne("select count(*) as n from pg_inherits where inhparent = 'results'::regclass")->n)->toBeGreaterThan(0);
});
