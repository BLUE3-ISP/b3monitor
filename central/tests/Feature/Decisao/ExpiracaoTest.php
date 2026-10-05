<?php

declare(strict_types=1);

use App\Work\Fila;
use App\Work\Handlers\DecideHandler;
use App\Work\Resultado;
use App\Work\WorkQueue;
use App\Work\Worker;
use Illuminate\Support\Facades\DB;
use Tests\Support\Decisao;

/*
| T-008 (03 §3): a expiração é por TEMPO. Sem resultado novo ninguém pede a reavaliação, e um `up` velho ficaria verde para sempre.
| `b3mon:reevaluate-expired` fecha o buraco; a decisão que sai dele segue as regras de sempre (unknown não fecha incidente nem avisa).
*/

beforeEach(function () {
    $this->s = Decisao::sondas();
    Decisao::ambas($this->s, 0, 2, 'pass');
    Decisao::decidir(2);                                 // up, validade = slot 2 + 132 s
    DB::table('decision_work')->delete();                // zera o pedido que a ingestão teria feito
    $this->validade = strtotime(DB::table('current_states')->value('valid_until'));
});

function pedidos(): int
{
    return DB::table('decision_work')->where('status', 'pending')->count();
}

it('não pede nada enquanto a evidência vale', function () {
    $this->travelTo(Carbon\CarbonImmutable::createFromTimestampUTC($this->validade - 5));

    $this->artisan('b3mon:reevaluate-expired')->expectsOutputToContain('reavaliações pedidas: 0')->assertSuccessful();

    expect(pedidos())->toBe(0);
});

it('pede a reavaliação do estado cuja validade passou, e o estado vira unknown (nunca o verde antigo)', function () {
    $quando = Carbon\CarbonImmutable::createFromTimestampUTC($this->validade + 5);
    $this->travelTo($quando);

    $this->artisan('b3mon:reevaluate-expired')->expectsOutputToContain('reavaliações pedidas: 1')->assertSuccessful();
    expect(pedidos())->toBe(1);

    DB::table('decision_work')->update(['available_at' => $quando->subHour()->format('c')]);
    $w = new Worker(new WorkQueue, 60);
    expect($w->step(Fila::Decide, app(DecideHandler::class), 'w', $quando->toDateTimeImmutable()))->toBe(Resultado::Concluido);

    $e = DB::table('current_states')->first();
    expect($e->health)->toBe('unknown')->and($e->coverage)->toBe('insufficient')
        // A mudança aparece no histórico do estado agregado.
        ->and(DB::table('state_events')->whereNull('perspective_id')->where('from_state', 'up')->where('to_state', 'unknown')->count())->toBe(1);
});

it('é coalescido: rodar várias vezes seguidas deixa UM pedido pendente', function () {
    $this->travelTo(Carbon\CarbonImmutable::createFromTimestampUTC($this->validade + 5));

    foreach ([1, 2, 3] as $_) {
        $this->artisan('b3mon:reevaluate-expired')->assertSuccessful();
    }

    expect(pedidos())->toBe(1);
});

it('não pede para o estado que já é unknown (não gira em laço)', function () {
    $this->travelTo(Carbon\CarbonImmutable::createFromTimestampUTC($this->validade + 5));
    DB::table('current_states')->update(['health' => 'unknown', 'scope' => 'unknown', 'coverage' => 'insufficient']);

    $this->artisan('b3mon:reevaluate-expired')->expectsOutputToContain('reavaliações pedidas: 0')->assertSuccessful();
});

it('expirar NÃO fecha um incidente aberto e não avisa de novo (unknown não é recuperação)', function () {
    DB::table('current_states')->delete();
    DB::table('results')->count();
    // Uma queda confirmada (sobrescreve o cenário do beforeEach).
    Decisao::ambas($this->s, 3, 5, 'fail');
    Decisao::decidir(5);
    expect(DB::table('incidents')->whereNull('confirmed_end_at')->count())->toBe(1);
    $avisos = DB::table('notification_outbox')->count();
    DB::table('decision_work')->delete();

    $quando = Carbon\CarbonImmutable::createFromTimestampUTC(strtotime(DB::table('current_states')->value('valid_until')) + 5);
    $this->travelTo($quando);
    $this->artisan('b3mon:reevaluate-expired')->assertSuccessful();
    DB::table('decision_work')->update(['available_at' => $quando->subHour()->format('c')]);
    (new Worker(new WorkQueue, 60))->step(Fila::Decide, app(DecideHandler::class), 'w', $quando->toDateTimeImmutable());

    expect(DB::table('current_states')->value('health'))->toBe('unknown')
        ->and(DB::table('incidents')->whereNull('confirmed_end_at')->count())->toBe(1)
        ->and(DB::table('notification_outbox')->count())->toBe($avisos);
});

it('está no agendador, a cada 10 segundos', function () {
    $evento = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'b3mon:reevaluate-expired'));

    expect($evento)->not->toBeNull()->and($evento->repeatSeconds)->toBe(10);
});
