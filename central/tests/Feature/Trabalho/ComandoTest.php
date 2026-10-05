<?php

declare(strict_types=1);

use App\Work\Fila;
use Illuminate\Support\Facades\DB;
use Tests\Support\Trabalho;

it('b3mon:work ingest --once processa um item e sai', function () {
    $item = Trabalho::pendente(Fila::Ingest);
    // O comando usa o relógio real: o item já está disponível desde antes.

    $this->artisan('b3mon:work', ['fila' => 'ingest', '--once' => true])->assertSuccessful();

    expect(DB::table('ingestion_work')->where('id', $item->id)->value('status'))->toBe('done')
        ->and(DB::table('decision_work')->count())->toBe(1);
});

it('o worker reconcilia a própria fila antes de pegar: item de um colega morto é retomado', function () {
    $item = Trabalho::pendente(Fila::Ingest);
    // Um colega morreu com a lease vencida há muito tempo (o comando usa o relógio real).
    DB::table('ingestion_work')->where('id', $item->id)->update([
        'status' => 'processing', 'attempts' => 1, 'lease_owner' => 'morto', 'lease_expires_at' => '2026-09-28T15:00:30Z',
    ]);

    $this->artisan('b3mon:work', ['fila' => 'ingest', '--once' => true])->assertSuccessful();

    $l = DB::table('ingestion_work')->where('id', $item->id)->first();
    expect($l->status)->toBe('done')->and($l->attempts)->toBe(2);
});

it('b3mon:work --once com a fila vazia sai sem erro', function () {
    $this->artisan('b3mon:work', ['fila' => 'ingest', '--once' => true])->assertSuccessful();
});

it('as três filas têm worker: com a fila vazia cada uma sobe, não acha nada e sai sem erro', function (string $fila) {
    $this->artisan('b3mon:work', ['fila' => $fila, '--once' => true])->assertSuccessful();
})->with(['ingest', 'decide', 'notify']);

it('b3mon:work decide --once roda o motor sobre o pedido: a decisão acontece pelo comando de verdade', function () {
    $s = Tests\Support\Decisao::sondas();
    Tests\Support\Decisao::ambas($s, 0, 2, 'fail');
    DB::table('decision_work')->insert(['check_id' => Tests\Support\Banco::CHECK, 'ip_family' => 'ipv4']);

    // O comando usa o relógio REAL: os resultados de 2026-09-28 estão velhos, e o presente é `unknown`. O que importa aqui é
    // que o comando consumiu o pedido e gravou a projeção.
    $this->artisan('b3mon:work', ['fila' => 'decide', '--once' => true])->assertSuccessful();

    expect(DB::table('decision_work')->value('status'))->toBe('done')
        ->and(DB::table('current_states')->value('health'))->toBe('unknown');
});

it('recusa uma fila que não existe', function () {
    $this->artisan('b3mon:work', ['fila' => 'tabela; drop table results', '--once' => true])->assertFailed();

    expect(DB::selectOne("select to_regclass('results') is not null as existe")->existe)->toBeTrue();
});

it('b3mon:work-reconcile devolve a lease vencida e informa por fila', function () {
    $item = Trabalho::pendente(Fila::Decide);
    DB::table('decision_work')->where('id', $item->id)->update([
        'status' => 'processing', 'lease_owner' => 'morto', 'lease_expires_at' => '2026-09-28T15:00:30Z',
    ]);

    $this->artisan('b3mon:work-reconcile')->expectsOutputToContain('decide')->assertSuccessful();

    expect(DB::table('decision_work')->where('id', $item->id)->value('status'))->toBe('pending');
});

it('o reconciliador está no agendador, a cada minuto', function () {
    $eventos = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command, 'b3mon:work-reconcile'));

    expect($eventos)->toHaveCount(1)->and($eventos->first()->expression)->toBe('* * * * *');
});

it('b3mon:partitions cria as partições que faltam, é idempotente e está no agendador', function () {
    $ate = now()->utc()->addDays((int) config('b3mon.partitions.days_ahead'));
    $nome = 'results_p'.$ate->format('Ymd');
    // Simula o dia que passou sem ninguém ter criado a partição do futuro: derruba a mais distante.
    DB::unprepared("DROP TABLE IF EXISTS $nome");
    expect(DB::selectOne('select to_regclass(?) is not null as existe', [$nome])->existe)->toBeFalse();

    $this->artisan('b3mon:partitions')->expectsOutputToContain('partições criadas: 1')->assertSuccessful();
    expect(DB::selectOne('select to_regclass(?) is not null as existe', [$nome])->existe)->toBeTrue();

    $this->artisan('b3mon:partitions')->expectsOutputToContain('partições criadas: 0')->assertSuccessful();

    $evento = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'b3mon:partitions'));
    expect($evento?->expression)->toBe('0 * * * *');
});
