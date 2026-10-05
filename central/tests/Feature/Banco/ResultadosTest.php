<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Banco;

/*
| `results`, `result_receipts` e `ingestion_work` (B2). Cada recusa confere o SQLSTATE exato, para saber que a
| regra CERTA foi a que disparou, e não outra qualquer.
*/

it('cria as tabelas do caminho de ingestão', function () {
    foreach (['results', 'result_receipts', 'ingestion_work', 'current_states', 'state_events', 'incidents',
        'incident_events', 'notification_outbox', 'notification_attempts', 'audit_events'] as $tabela) {
        expect(Schema::hasTable($tabela))->toBeTrue($tabela);
    }
});

it('particiona results por dia, e deixa as partições preparadas com antecedência', function () {
    $tipo = DB::selectOne("select relkind from pg_class where relname = 'results'")->relkind;
    expect($tipo)->toBe('p');   // p = tabela particionada

    $particoes = DB::select("select inhrelid::regclass::text as nome from pg_inherits where inhparent = 'results'::regclass");
    $esperadas = (int) config('b3mon.partitions.days_behind') + (int) config('b3mon.partitions.days_ahead') + 1;
    expect(count($particoes))->toBeGreaterThanOrEqual($esperadas);

    $hoje = DB::selectOne("select to_char((now() at time zone 'utc')::date, 'YYYYMMDD') as d")->d;
    expect(collect($particoes)->pluck('nome')->all())->toContain('results_p'.$hoje);
});

it('cria partições de forma idempotente e recusa um intervalo invertido', function () {
    $primeira = DB::selectOne("select b3mon_ensure_result_partitions('2031-01-01', '2031-01-03') as n")->n;
    $segunda = DB::selectOne("select b3mon_ensure_result_partitions('2031-01-01', '2031-01-03') as n")->n;

    expect($primeira)->toBe(3)->and($segunda)->toBe(0);
    expect(Banco::sqlstate(fn () => DB::select("select b3mon_ensure_result_partitions('2031-01-05', '2031-01-01')")))
        ->not->toBeNull();
});

it('recusa um resultado quando nenhuma partição cobre o seu dia', function () {
    $codigo = Banco::sqlstate(function () {
        DB::table('results')->insert([
            'result_id' => (string) Illuminate\Support\Str::uuid(), 'probe_id' => Banco::PROBE,
            'assignment_id' => Banco::ASSIGNMENT, 'config_generation' => 3, 'policy_revision' => 'x',
            'execution_kind' => 'scheduled', 'scheduled_at' => '2099-01-01T00:00:00Z',
            'started_at' => '2099-01-01T00:00:00Z', 'finished_at' => '2099-01-01T00:00:01Z', 'duration_ms' => 1,
            'ip_family' => 'ipv4', 'outcome' => 'pass', 'reason_code' => 'http_assertions_passed',
            'clock_status' => 'synchronized', 'probe_integrity' => 'valid', 'payload' => '{}',
            'content_hash' => str_repeat('a', 64), 'received_at' => '2099-01-01T00:00:02Z',
        ]);
    });

    expect($codigo)->toBe(Banco::CHECK_VIOLADO);
});

it('grava um resultado válido e o relê igual', function () {
    $id = Banco::resultado();

    $linha = DB::table('results')->where('result_id', $id)->first();
    expect($linha->outcome)->toBe('pass')
        ->and($linha->ip_family)->toBe('ipv4')
        ->and(json_decode($linha->payload, true))->toBe(['lab' => true]);
});

// ---------------------------------------------------------------------------------------------------------------
// Imutabilidade (06 §1: resultados são imutáveis)
// ---------------------------------------------------------------------------------------------------------------

it('recusa UPDATE e DELETE em results: resultado é imutável', function () {
    $id = Banco::resultado();

    expect(Banco::sqlstate(fn () => DB::table('results')->where('result_id', $id)->update(['outcome' => 'fail'])))
        ->toBe(Banco::IMUTAVEL);
    expect(Banco::sqlstate(fn () => DB::table('results')->where('result_id', $id)->delete()))
        ->toBe(Banco::IMUTAVEL);

    expect(DB::table('results')->where('result_id', $id)->value('outcome'))->toBe('pass');   // intacto
});

it('mantém a imutabilidade numa partição criada DEPOIS do gatilho', function () {
    Banco::garantirParticao('2031-06-01');
    $id = Banco::resultado(['started_at' => '2031-06-01T10:00:00Z', 'finished_at' => '2031-06-01T10:00:01Z',
        'scheduled_at' => '2031-06-01T10:00:00Z', 'received_at' => '2031-06-01T10:00:02Z']);

    expect(Banco::sqlstate(fn () => DB::table('results')->where('result_id', $id)->update(['outcome' => 'fail'])))
        ->toBe(Banco::IMUTAVEL);
});

// ---------------------------------------------------------------------------------------------------------------
// Invariantes do resultado, no banco (06 §3 e §11; 14 §3)
// ---------------------------------------------------------------------------------------------------------------

it('recusa valores fora do glossário', function (string $coluna, string $valor) {
    expect(Banco::sqlstate(fn () => Banco::resultado([$coluna => $valor])))->toBe(Banco::CHECK_VIOLADO);
})->with([
    'outcome' => ['outcome', 'timeout'],
    'família IP' => ['ip_family', 'ipv5'],
    'tipo de execução' => ['execution_kind', 'agendada'],
    'reason_code' => ['reason_code', 'timeout'],
    'relógio' => ['clock_status', 'ok'],
    'integridade' => ['probe_integrity', 'good'],
]);

it('recusa duração negativa e fim antes do início', function () {
    expect(Banco::sqlstate(fn () => Banco::resultado(['duration_ms' => -1])))->toBe(Banco::CHECK_VIOLADO);
    expect(Banco::sqlstate(fn () => Banco::resultado(['finished_at' => '2026-09-28T15:00:01Z'])))->toBe(Banco::CHECK_VIOLADO);
});

it('06 §12: agendado tem slot e não tem manual_request_id; manual é o inverso', function () {
    $m = '77777777-7777-4777-8777-777777777777';

    expect(Banco::sqlstate(fn () => Banco::resultado(['scheduled_at' => null])))->toBe(Banco::CHECK_VIOLADO);
    expect(Banco::sqlstate(fn () => Banco::resultado(['manual_request_id' => $m])))->toBe(Banco::CHECK_VIOLADO);
    expect(Banco::sqlstate(fn () => Banco::resultado(['execution_kind' => 'manual'])))->toBe(Banco::CHECK_VIOLADO);

    // E o caso certo passa: manual com request id e sem slot.
    $id = Banco::resultado(['execution_kind' => 'manual', 'manual_request_id' => $m, 'scheduled_at' => null]);
    expect(DB::table('results')->where('result_id', $id)->value('execution_kind'))->toBe('manual');
});

// ---------------------------------------------------------------------------------------------------------------
// Ledger de receipts: a idempotência GLOBAL (06 §3)
// ---------------------------------------------------------------------------------------------------------------

it('recusa um segundo recibo para o mesmo result_id', function () {
    $id = Banco::recibo();

    expect(Banco::sqlstate(fn () => Banco::recibo(['result_id' => $id, 'scheduled_at' => '2026-09-28T15:00:30Z'])))
        ->toBe(Banco::UNICA);
});

it('recusa o mesmo slot com outro result_id: a chave natural é única em TODAS as partições', function () {
    Banco::recibo();

    expect(Banco::sqlstate(fn () => Banco::recibo()))->toBe(Banco::UNICA);
});

it('aceita o mesmo slot em outra geração, em outra sonda e em outro assignment', function () {
    Banco::recibo();

    Banco::recibo(['config_generation' => 4]);
    Banco::recibo(['probe_id' => '99999999-9999-4999-8999-999999999999']);
    Banco::recibo(['assignment_id' => '88888888-8888-4888-8888-888888888888']);

    expect(DB::table('result_receipts')->count())->toBe(4);
});

it('06 §12: a chave de uma execução manual não colide com a de um slot agendado', function () {
    $m = '77777777-7777-4777-8777-777777777777';
    Banco::recibo();   // agendado, com slot

    Banco::recibo(['execution_kind' => 'manual', 'scheduled_at' => null, 'manual_request_id' => $m]);
    expect(Banco::sqlstate(fn () => Banco::recibo(['execution_kind' => 'manual', 'scheduled_at' => null, 'manual_request_id' => $m])))
        ->toBe(Banco::UNICA);
});

it('exige que o ledger viva além da recepção', function () {
    expect(Banco::sqlstate(fn () => Banco::recibo(['retain_until' => '2026-09-28T15:00:03Z'])))->toBe(Banco::CHECK_VIOLADO);
});

// ---------------------------------------------------------------------------------------------------------------
// ingestion_work (D-04)
// ---------------------------------------------------------------------------------------------------------------

it('só enfileira trabalho para um resultado que já tem recibo, e uma vez só', function () {
    expect(Banco::sqlstate(fn () => DB::table('ingestion_work')->insert([
        'result_id' => (string) Illuminate\Support\Str::uuid(), 'result_started_at' => '2026-09-28T15:00:02Z',
    ])))->toBe(Banco::FK);

    $id = Banco::recibo();
    DB::table('ingestion_work')->insert(['result_id' => $id, 'result_started_at' => '2026-09-28T15:00:02Z']);

    expect(DB::table('ingestion_work')->where('result_id', $id)->value('status'))->toBe('pending');
    expect(Banco::sqlstate(fn () => DB::table('ingestion_work')->insert(['result_id' => $id, 'result_started_at' => '2026-09-28T15:00:02Z'])))
        ->toBe(Banco::UNICA);
});

it('mantém o lease coerente: processing exige dono e validade, done exige a data de processamento', function () {
    $id = Banco::recibo();
    DB::table('ingestion_work')->insert(['result_id' => $id, 'result_started_at' => '2026-09-28T15:00:02Z']);

    expect(Banco::sqlstate(fn () => DB::table('ingestion_work')->where('result_id', $id)->update(['status' => 'processing'])))
        ->toBe(Banco::CHECK_VIOLADO);
    expect(Banco::sqlstate(fn () => DB::table('ingestion_work')->where('result_id', $id)->update(['status' => 'done'])))
        ->toBe(Banco::CHECK_VIOLADO);

    DB::table('ingestion_work')->where('result_id', $id)->update([
        'status' => 'processing', 'lease_owner' => 'worker-1', 'lease_expires_at' => '2026-09-28T15:05:00Z',
    ]);
    DB::table('ingestion_work')->where('result_id', $id)->update([
        'status' => 'done', 'lease_owner' => null, 'lease_expires_at' => null, 'processed_at' => '2026-09-28T15:00:04Z',
    ]);

    expect(DB::table('ingestion_work')->where('result_id', $id)->value('status'))->toBe('done');
});
