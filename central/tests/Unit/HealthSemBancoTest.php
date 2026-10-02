<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// Fica em Unit de propósito: sem o RefreshDatabase, que tentaria dar rollback numa conexão
// que este teste aponta para uma porta morta.
it('nunca diz que está tudo bem quando o banco não responde', function () {
    // Aponta a conexão para uma porta onde nada escuta, e descarta a conexão já aberta.
    config(['database.connections.pgsql.port' => 1]);
    DB::purge('pgsql');

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'unavailable')
        ->assertJsonPath('checks.database', 'fail');
});
