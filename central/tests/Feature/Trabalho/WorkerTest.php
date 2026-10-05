<?php

declare(strict_types=1);

use App\Work\Fila;
use App\Work\Handlers\IngestHandler;
use App\Work\Resultado;
use App\Work\WorkQueue;
use App\Work\Worker;
use Illuminate\Support\Facades\DB;
use Tests\Support\Trabalho;

/*
| O passo do worker e o que ele garante: o efeito do handler e a conclusão do item entram JUNTOS ou nenhum dos
| dois entra, e um worker que perdeu a lease não deixa efeito nenhum.
*/

beforeEach(function () {
    $this->q = new WorkQueue(maxAttempts: 3, backoffBaseS: 10, backoffCapS: 60);
    $this->w = new Worker($this->q, leaseS: 30);
});

it('fila vazia: ocioso, e o handler não é chamado', function () {
    $h = Trabalho::handler();

    expect($this->w->step(Fila::Ingest, $h, 'w1', Trabalho::t()))->toBe(Resultado::Ocioso)
        ->and($h->itens)->toBe([]);
});

it('processa e conclui o item', function (Fila $fila) {
    $item = Trabalho::pendente($fila);
    $h = Trabalho::handler();

    expect($this->w->step($fila, $h, 'w1', Trabalho::t()))->toBe(Resultado::Concluido)
        ->and($h->itens)->toBe([$item->id])
        ->and(DB::table($fila->table())->where('id', $item->id)->value('status'))->toBe('done');
})->with([[Fila::Ingest], [Fila::Decide], [Fila::Notify]]);

it('o handler que falha desfaz o efeito que já tinha gravado e devolve o item à fila', function () {
    $item = Trabalho::pendente(Fila::Decide);
    $h = Trabalho::handler(function () {
        DB::table('decision_work')->insert(['check_id' => '99999999-9999-4999-8999-999999999999', 'ip_family' => 'ipv4']);
        throw new RuntimeException('o handler quebrou no meio');
    });

    expect($this->w->step(Fila::Decide, $h, 'w1', Trabalho::t()))->toBe(Resultado::Falhou);

    $l = DB::table('decision_work')->where('id', $item->id)->first();
    expect($l->status)->toBe('pending')->and($l->last_error)->toContain('o handler quebrou no meio')
        // O efeito gravado antes do erro NÃO ficou: só o item original está lá.
        ->and(DB::table('decision_work')->count())->toBe(1);
});

it('a falha repetida leva o item a dead, e o worker segue para o próximo', function () {
    $ruim = Trabalho::pendente(Fila::Decide, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $bom = Trabalho::pendente(Fila::Decide, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
    DB::table('decision_work')->where('id', $bom->id)->update(['available_at' => '2026-09-28T15:00:05Z']);
    $h = Trabalho::handler(fn ($i) => $i->id === $ruim->id ? throw new RuntimeException('veneno') : null);

    $agora = Trabalho::t('+1 hour');
    $resultados = [];
    for ($i = 0; $i < 4; $i++) {
        $resultados[] = $this->w->step(Fila::Decide, $h, 'w', $agora)->value;
        $agora = $agora->modify('+10 minutes');   // além de qualquer espera
    }

    expect(DB::table('decision_work')->where('id', $ruim->id)->value('status'))->toBe('dead')
        ->and(DB::table('decision_work')->where('id', $bom->id)->value('status'))->toBe('done')
        ->and(DB::table('decision_work')->where('id', $ruim->id)->value('attempts'))->toBe(3);
});

it('um worker LENTO que perdeu a lease não deixa efeito nenhum: o passo é descartado antes do handler', function () {
    $item = Trabalho::pendente(Fila::Decide);
    // O worker A pega o item e demora. A lease (30 s) vence, o reconciliador devolve o item, e B o conclui.
    $pegoPorA = $this->q->claim(Fila::Decide, 'A', 30, Trabalho::t());
    $this->q->reconcile(Fila::Decide, Trabalho::t('+31 seconds'));
    $hB = Trabalho::handler();
    expect($this->w->step(Fila::Decide, $hB, 'B', Trabalho::t('+32 seconds')))->toBe(Resultado::Concluido);

    // A acorda e tenta terminar o que pegou.
    $hA = Trabalho::handler();
    $r = $this->w->process(Fila::Decide, $hA, $pegoPorA, 'A', Trabalho::t('+40 seconds'));

    expect($r)->toBe(Resultado::LeasePerdida)
        ->and($hA->itens)->toBe([])            // o handler de A nem rodou
        ->and($hB->itens)->toBe([$item->id])    // o efeito aconteceu UMA vez
        ->and(DB::table('decision_work')->where('id', $item->id)->value('status'))->toBe('done');
});

it('um worker lento NÃO assume o item que outro worker está processando agora: o handler dele nem roda', function () {
    $item = Trabalho::pendente(Fila::Decide);
    $pegoPorA = $this->q->claim(Fila::Decide, 'A', 30, Trabalho::t());
    $this->q->reconcile(Fila::Decide, Trabalho::t('+31 seconds'));
    $this->q->claim(Fila::Decide, 'B', 30, Trabalho::t('+32 seconds'));   // B está no meio do processamento

    $hA = Trabalho::handler();

    expect($this->w->process(Fila::Decide, $hA, $pegoPorA, 'A', Trabalho::t('+40 seconds')))->toBe(Resultado::LeasePerdida)
        ->and($hA->itens)->toBe([])
        ->and(DB::table('decision_work')->where('id', $item->id)->value('lease_owner'))->toBe('B');
});

it('um worker que perdeu a lease e cujo item ainda está pendente também é descartado', function () {
    $item = Trabalho::pendente(Fila::Decide);
    $pegoPorA = $this->q->claim(Fila::Decide, 'A', 30, Trabalho::t());
    $this->q->reconcile(Fila::Decide, Trabalho::t('+31 seconds'));   // voltou a pending, ninguém pegou ainda

    $hA = Trabalho::handler();

    expect($this->w->process(Fila::Decide, $hA, $pegoPorA, 'A', Trabalho::t('+40 seconds')))->toBe(Resultado::LeasePerdida)
        ->and($hA->itens)->toBe([])
        ->and(DB::table('decision_work')->where('id', $item->id)->value('status'))->toBe('pending');
});

// ---- A fila ingest ----

it('ingest: o resultado aceito vira UM pedido de avaliação do check dele', function () {
    $item = Trabalho::pendente(Fila::Ingest);

    expect($this->w->step(Fila::Ingest, new IngestHandler, 'w', Trabalho::t()))->toBe(Resultado::Concluido);

    $pedidos = DB::table('decision_work')->get();
    expect($pedidos)->toHaveCount(1)
        ->and($pedidos[0]->check_id)->toBe('22222222-2222-4222-8222-222222222222')
        ->and($pedidos[0]->ip_family)->toBe('ipv4')
        ->and($pedidos[0]->status)->toBe('pending')
        ->and(DB::table('ingestion_work')->where('id', $item->id)->value('status'))->toBe('done');
});

it('ingest: resultados do mesmo check em sequência geram um pedido só, e um pedido em andamento não impede o próximo', function () {
    Trabalho::pendente(Fila::Ingest);
    Trabalho::pendente(Fila::Ingest);
    Trabalho::pendente(Fila::Ingest);

    foreach ([1, 2, 3] as $_) {
        // Cada passo CONCLUI: sem a coalescência no banco, o segundo INSERT violaria o índice único, o passo falharia
        // e a contagem de pendentes abaixo continuaria 1 — por isso o resultado de cada passo é conferido.
        expect($this->w->step(Fila::Ingest, new IngestHandler, 'w', Trabalho::t()))->toBe(Resultado::Concluido);
    }
    expect(DB::table('decision_work')->where('status', 'pending')->count())->toBe(1);

    // O pedido é pego pela decisão; chega outro resultado: precisa de uma avaliação nova. (O pedido nasce com o
    // relógio do banco, que não é o de mentira dos testes.)
    DB::table('decision_work')->update(['available_at' => '2026-09-28T15:00:00Z']);
    $this->q->claim(Fila::Decide, 'decisor', 60, Trabalho::t());
    Trabalho::pendente(Fila::Ingest);
    expect($this->w->step(Fila::Ingest, new IngestHandler, 'w', Trabalho::t()))->toBe(Resultado::Concluido);

    expect(DB::table('decision_work')->where('status', 'pending')->count())->toBe(1)
        ->and(DB::table('decision_work')->where('status', 'processing')->count())->toBe(1);
});

it('ingest: se o resultado do recibo não está em results, falha ALTO em vez de pular', function () {
    $item = Trabalho::pendente(Fila::Ingest);
    // `results` é imutável (nem o teste a apaga): o defeito é o item apontar para um resultado que não existe.
    DB::table('ingestion_work')->where('id', $item->id)->update(['result_started_at' => '2026-09-20T00:00:00Z']);

    expect($this->w->step(Fila::Ingest, new IngestHandler, 'w', Trabalho::t()))->toBe(Resultado::Falhou);

    $l = DB::table('ingestion_work')->where('id', $item->id)->first();
    expect($l->status)->toBe('pending')->and($l->last_error)->toContain('não está em results')
        ->and(DB::table('decision_work')->count())->toBe(0);
});
