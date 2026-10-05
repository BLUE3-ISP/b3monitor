<?php

declare(strict_types=1);

use App\Work\Fila;
use App\Work\WorkQueue;
use Illuminate\Support\Facades\DB;
use Tests\Support\Banco;
use Tests\Support\Trabalho;

/*
| A máquina da fila (B4): pegar com lease, segurar, concluir, falhar e reconciliar, sobre as três tabelas.
| Cada operação roda contra cada fila, porque o SQL é o mesmo e o nome da tabela é o único que muda.
*/

function linha(Fila $fila, int $id): stdClass
{
    return DB::table($fila->table())->where('id', $id)->first();
}

dataset('filas', [
    'ingest' => [Fila::Ingest],
    'decide' => [Fila::Decide],
    'notify' => [Fila::Notify],
]);

beforeEach(fn () => $this->q = new WorkQueue(maxAttempts: 3, backoffBaseS: 10, backoffCapS: 60));

it('pega o item pendente e o põe em processamento, com dono, prazo e uma tentativa a mais', function (Fila $fila) {
    $item = Trabalho::pendente($fila);

    $pego = $this->q->claim($fila, 'w1', 60, Trabalho::t());

    expect($pego->id)->toBe($item->id)
        ->and($pego->status)->toBe('processing')
        ->and($pego->attempts)->toBe(1)
        ->and($pego->lease_owner)->toBe('w1')
        ->and(strtotime($pego->lease_expires_at))->toBe(strtotime(Trabalho::T0) + 60);
})->with('filas');

it('não entrega um item que já está em processamento nem um que ainda não está disponível', function (Fila $fila) {
    $item = Trabalho::pendente($fila);
    $this->q->claim($fila, 'w1', 60, Trabalho::t());

    expect($this->q->claim($fila, 'w2', 60, Trabalho::t('+1 second')))->toBeNull();

    // Um item pendente com `available_at` no futuro (espera de uma falha) só aparece quando chega a hora.
    $outro = Trabalho::pendente($fila, '55555555-5555-4555-8555-555555555555');
    DB::table($fila->table())->where('id', $outro->id)->update(['available_at' => '2026-09-28T15:05:00Z']);
    expect($this->q->claim($fila, 'w2', 60, Trabalho::t('+1 minute')))->toBeNull()
        ->and($this->q->claim($fila, 'w2', 60, Trabalho::t('+10 minutes'))?->id)->toBe($outro->id);
})->with('filas');

it('entrega na ordem de disponibilidade e, nela, na ordem de chegada', function () {
    $a = Trabalho::pendente(Fila::Decide, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $b = Trabalho::pendente(Fila::Decide, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
    $c = Trabalho::pendente(Fila::Decide, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc');
    // `a` tem de esperar mais que os outros: sai por último, mesmo tendo chegado primeiro.
    DB::table('decision_work')->where('id', $a->id)->update(['available_at' => '2026-09-28T15:00:05Z']);
    DB::table('decision_work')->whereIn('id', [$b->id, $c->id])->update(['available_at' => '2026-09-28T15:00:01Z']);

    $ordem = [];
    while ($p = $this->q->claim(Fila::Decide, 'w', 60, Trabalho::t())) {
        $ordem[] = $p->id;
    }

    expect($ordem)->toBe([$b->id, $c->id, $a->id]);
});

it('conclui o item do dono e limpa a lease', function (Fila $fila) {
    $item = Trabalho::pendente($fila);
    $this->q->claim($fila, 'w1', 60, Trabalho::t());

    expect($this->q->finish($fila, $item->id, 'w1', Trabalho::t('+2 seconds')))->toBeTrue();

    $l = linha($fila, $item->id);
    expect($l->status)->toBe('done')->and($l->lease_owner)->toBeNull()->and($l->lease_expires_at)->toBeNull()
        ->and($l->processed_at)->not->toBeNull();
})->with('filas');

it('só o dono conclui: outro worker, ou um item que não foi pego, não', function (Fila $fila) {
    $item = Trabalho::pendente($fila);

    expect($this->q->finish($fila, $item->id, 'w1', Trabalho::t()))->toBeFalse();   // ainda pending

    $this->q->claim($fila, 'w1', 60, Trabalho::t());
    expect($this->q->finish($fila, $item->id, 'w2', Trabalho::t()))->toBeFalse()
        ->and(linha($fila, $item->id)->status)->toBe('processing');
})->with('filas');

it('a falha devolve o item à fila com espera que dobra a cada tentativa, até o teto', function () {
    $item = Trabalho::pendente(Fila::Decide);
    $esperas = [];

    foreach ([1, 2] as $tentativa) {
        $p = $this->q->claim(Fila::Decide, 'w', 60, Trabalho::t('+1 hour'));
        expect($p->attempts)->toBe($tentativa);
        expect($this->q->fail(Fila::Decide, $item->id, 'w', 'boom', Trabalho::t('+1 hour')))->toBe('pending');
        $l = linha(Fila::Decide, $item->id);
        $esperas[] = strtotime($l->available_at) - strtotime(Trabalho::t('+1 hour')->format('c'));
        // Para a próxima tentativa o relógio avança além da espera.
        DB::table('decision_work')->where('id', $item->id)->update(['available_at' => '2026-09-28T15:00:00Z']);
    }

    // 10 s na 1ª falha (base), 20 s na 2ª.
    expect($esperas)->toBe([10, 20])
        ->and(linha(Fila::Decide, $item->id)->last_error)->toBe('boom')
        ->and(linha(Fila::Decide, $item->id)->lease_owner)->toBeNull();
});

it('o teto da espera vale', function () {
    $q = new WorkQueue(maxAttempts: 100, backoffBaseS: 10, backoffCapS: 60);
    $item = Trabalho::pendente(Fila::Decide);
    DB::table('decision_work')->where('id', $item->id)->update(['attempts' => 9]);   // a próxima falha seria 10 × 2^9

    $q->claim(Fila::Decide, 'w', 60, Trabalho::t());
    $q->fail(Fila::Decide, $item->id, 'w', 'x', Trabalho::t());

    expect(strtotime(linha(Fila::Decide, $item->id)->available_at) - strtotime(Trabalho::T0))->toBe(60);
});

it('esgotadas as tentativas o item PARA como dead, com o motivo, e não volta à fila', function (Fila $fila) {
    $item = Trabalho::pendente($fila);

    foreach ([1, 2, 3] as $n) {
        DB::table($fila->table())->where('id', $item->id)->update(['available_at' => '2026-09-28T15:00:00Z']);
        $this->q->claim($fila, 'w', 60, Trabalho::t());
        $novo = $this->q->fail($fila, $item->id, 'w', "erro $n", Trabalho::t());
    }

    $l = linha($fila, $item->id);
    expect($novo)->toBe('dead')
        ->and($l->status)->toBe('dead')->and($l->attempts)->toBe(3)->and($l->last_error)->toBe('erro 3')
        ->and($this->q->claim($fila, 'w', 60, Trabalho::t('+1 day')))->toBeNull();
})->with('filas');

it('a falha de quem perdeu a lease não mexe no item', function () {
    $item = Trabalho::pendente(Fila::Decide);
    $this->q->claim(Fila::Decide, 'w1', 60, Trabalho::t());

    expect($this->q->fail(Fila::Decide, $item->id, 'w2', 'x', Trabalho::t()))->toBeNull()
        ->and(linha(Fila::Decide, $item->id)->status)->toBe('processing');
});

it('o reconciliador devolve à fila a lease VENCIDA, e só ela', function (Fila $fila) {
    $vencida = Trabalho::pendente($fila, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $viva = Trabalho::pendente($fila, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
    $this->q->claim($fila, 'morto', 30, Trabalho::t());
    $this->q->claim($fila, 'vivo', 300, Trabalho::t());

    // Antes de vencer, nada acontece: um worker lento mas vivo não perde o item.
    expect($this->q->reconcile($fila, Trabalho::t('+29 seconds')))->toBe(['requeued' => 0, 'dead' => 0]);

    expect($this->q->reconcile($fila, Trabalho::t('+31 seconds')))->toBe(['requeued' => 1, 'dead' => 0]);
    $v = linha($fila, $vencida->id);
    expect($v->status)->toBe('pending')->and($v->lease_owner)->toBeNull()->and($v->last_error)->toContain('lease vencida')
        ->and(linha($fila, $viva->id)->status)->toBe('processing');
})->with('filas');

it('um item que já gastou as tentativas e perde a lease PARA em vez de voltar: o que mata o worker não volta para sempre', function () {
    $item = Trabalho::pendente(Fila::Ingest);
    DB::table('ingestion_work')->where('id', $item->id)->update(['attempts' => 2]);   // o claim seguinte é a 3ª (a última)
    $this->q->claim(Fila::Ingest, 'morto', 30, Trabalho::t());

    expect($this->q->reconcile(Fila::Ingest, Trabalho::t('+1 minute')))->toBe(['requeued' => 0, 'dead' => 1])
        ->and(linha(Fila::Ingest, $item->id)->status)->toBe('dead');
});

it('o reconciliador não toca em item concluído nem em parado', function () {
    $feito = Trabalho::pendente(Fila::Decide, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $this->q->claim(Fila::Decide, 'w', 30, Trabalho::t());
    $this->q->finish(Fila::Decide, $feito->id, 'w', Trabalho::t());

    expect($this->q->reconcile(Fila::Decide, Trabalho::t('+1 day')))->toBe(['requeued' => 0, 'dead' => 0])
        ->and(linha(Fila::Decide, $feito->id)->status)->toBe('done');
});

it('o banco recusa um item em processamento sem dono (a lease é invariante do esquema)', function () {
    $item = Trabalho::pendente(Fila::Decide);

    expect(Banco::sqlstate(fn () => DB::table('decision_work')->where('id', $item->id)->update(['status' => 'processing'])))
        ->toBe(Banco::CHECK_VIOLADO);
});

it('só UM pedido de decisão pendente por check e família, e um em processamento não impede o próximo', function () {
    $check = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $primeiro = Trabalho::pendente(Fila::Decide, $check);

    expect(Banco::sqlstate(fn () => DB::table('decision_work')->insert(['check_id' => $check, 'ip_family' => 'ipv4'])))
        ->toBe(Banco::UNICA);

    $this->q->claim(Fila::Decide, 'w', 60, Trabalho::t());
    expect(Banco::sqlstate(fn () => DB::table('decision_work')->insert(['check_id' => $check, 'ip_family' => 'ipv4'])))->toBeNull()
        ->and(linha(Fila::Decide, $primeiro->id)->status)->toBe('processing');
});
