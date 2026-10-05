<?php

declare(strict_types=1);

use App\Decision\Decider;
use Illuminate\Support\Facades\DB;
use Tests\Support\Banco;
use Tests\Support\Decisao;

/*
| B5: a decisão sobre o banco. O motor já tem os próprios testes (puro, sem banco); aqui se prova o que o B5 acrescenta: o
| incidente abre e fecha pela confirmação e pela recuperação SUSTENTADAS, a intenção de notificar nasce na mesma transação,
| e reavaliar o mesmo estado não muda nada.
*/

beforeEach(fn () => $this->s = Decisao::sondas());

it('três falhas consecutivas em dois domínios CONFIRMAM a queda: abre UM incidente e UMA intenção de aviso', function () {
    Decisao::ambas($this->s, 0, 2, 'fail');

    $r = Decisao::decidir(2);

    expect($r)->toBe(['health' => 'down', 'incident' => 'opened']);

    $i = Decisao::incidentes();
    expect($i)->toHaveCount(1)
        ->and($i[0]->incident_class)->toBe('service')->and($i[0]->severity)->toBe('critical')->and($i[0]->scope)->toBe('all_observed')
        ->and($i[0]->check_id)->toBe(Banco::CHECK)->and($i[0]->ip_family)->toBe('ipv4')->and($i[0]->version)->toBe(1)
        ->and($i[0]->correlation_key)->toBe('service:'.Banco::CHECK.':ipv4')
        ->and($i[0]->confirmed_end_at)->toBeNull();

    $o = Decisao::outbox();
    expect($o)->toHaveCount(1)
        ->and($o[0]->reason)->toBe('opened')->and($o[0]->channel)->toBe('dev')->and($o[0]->recipient)->toBe('lab')
        ->and($o[0]->decision_version)->toBe(1)->and($o[0]->status)->toBe('pending')
        // O canal dev é simulado e o BANCO sabe disso (coluna gerada).
        ->and($o[0]->simulated)->toBeTrue()
        ->and(json_decode($o[0]->payload, true))->toMatchArray(['incident_id' => $i[0]->id, 'reason' => 'opened', 'health' => 'down', 'decision_version' => 1]);

    $e = DB::table('current_states')->first();
    expect($e->health)->toBe('down')->and($e->scope)->toBe('all_observed')->and($e->coverage)->toBe('full')
        ->and($e->valid_domains)->toBe(2)->and($e->planned_domains)->toBe(2);
});

it('o início observado é a PRIMEIRA falha, e a detecção é a avaliação: duas coisas diferentes (03 §7)', function () {
    Decisao::ambas($this->s, 0, 2, 'fail');

    Decisao::decidir(2);

    $i = Decisao::incidentes()[0];
    expect(strtotime($i->observed_start_at))->toBe(Decisao::slot(0)->getTimestamp())
        ->and(strtotime($i->confirmed_at))->toBe(Decisao::slot(2)->getTimestamp() + 10);
});

it('duas falhas NÃO bastam: sem confirmação não há incidente, e a saúde não é um verde inventado', function () {
    Decisao::ambas($this->s, 0, 1, 'fail');

    $r = Decisao::decidir(1);

    expect($r['incident'])->toBeNull()->and($r['health'])->not->toBe('up')
        ->and(Decisao::incidentes())->toBe([])->and(Decisao::outbox())->toBe([]);
});

it('uma sonda caindo e a outra de pé: DEGRADADO, com aviso de severidade menor', function () {
    for ($n = 0; $n <= 2; $n++) {
        Decisao::amostra($this->s['a'], $n, 'fail');
        Decisao::amostra($this->s['b'], $n, 'pass');
    }

    $r = Decisao::decidir(2);

    // A confirma a queda, B está de pé: o motor diz `degraded / partial`, e é um aviso de severidade menor.
    expect($r)->toBe(['health' => 'degraded', 'incident' => 'opened'])
        ->and(Decisao::incidentes()[0]->severity)->toBe('warning')
        ->and(Decisao::incidentes()[0]->scope)->toBe('partial');
});

it('reavaliar o MESMO estado não muda nada: nem evento, nem versão, nem uma segunda intenção', function () {
    Decisao::ambas($this->s, 0, 2, 'fail');
    Decisao::decidir(2);
    $eventos = DB::table('incident_events')->count();
    $versao = DB::table('current_states')->value('version');

    foreach ([1, 2, 3] as $_) {
        expect(Decisao::decidir(2))->toBe(['health' => 'down', 'incident' => null]);
    }

    expect(DB::table('incidents')->count())->toBe(1)
        ->and(DB::table('incident_events')->count())->toBe($eventos)
        ->and(DB::table('notification_outbox')->count())->toBe(1)
        ->and(DB::table('current_states')->value('version'))->toBe($versao)
        ->and(DB::table('state_events')->count())->toBe(3);   // duas transições por sonda + a agregada, e só.
});

it('três sucessos consecutivos em dois domínios RECUPERAM: fecha o incidente e avisa a recuperação', function () {
    Decisao::ambas($this->s, 0, 2, 'fail');
    Decisao::decidir(2);
    Decisao::ambas($this->s, 3, 5, 'pass');

    // Dois sucessos ainda NÃO recuperam (a recuperação é sustentada, como a queda).
    expect(Decisao::decidir(4))->toMatchArray(['incident' => null]);
    expect(DB::table('incidents')->whereNull('confirmed_end_at')->count())->toBe(1);

    expect(Decisao::decidir(5))->toBe(['health' => 'up', 'incident' => 'recovered']);

    $i = Decisao::incidentes()[0];
    expect($i->confirmed_end_at)->not->toBeNull()->and($i->version)->toBe(2)
        // O fim observado é o primeiro sucesso da recuperação; a confirmação, a avaliação.
        ->and(strtotime($i->observed_end_at))->toBe(Decisao::slot(3)->getTimestamp())
        ->and(strtotime($i->confirmed_end_at))->toBe(Decisao::slot(5)->getTimestamp() + 10);

    $o = Decisao::outbox();
    expect($o)->toHaveCount(2)
        ->and([$o[1]->reason, $o[1]->decision_version])->toBe(['recovered', 2]);

    expect(DB::table('incident_events')->orderBy('id')->pluck('event_type')->all())->toBe(['opened', 'recovered']);
});

it('recuperação de um incidente que NUNCA foi avisado não gera aviso de "voltou"', function () {
    Decisao::ambas($this->s, 0, 2, 'fail');
    Decisao::decidir(2);
    DB::table('notification_outbox')->delete();   // a queda não chegou a ser avisada (ex.: nenhum destinatário na época)
    Decisao::ambas($this->s, 3, 5, 'pass');

    expect(Decisao::decidir(5)['incident'])->toBe('recovered')
        ->and(Decisao::outbox())->toBe([]);
});

it('o agravamento de degradado para queda total atualiza o incidente e avisa UMA vez; o alívio não avisa', function () {
    // A cai, B sobe: degradado (warning).
    for ($n = 0; $n <= 2; $n++) {
        Decisao::amostra($this->s['a'], $n, 'fail');
        Decisao::amostra($this->s['b'], $n, 'pass');
    }
    Decisao::decidir(2);

    // B também cai: queda total.
    for ($n = 3; $n <= 5; $n++) {
        Decisao::amostra($this->s['a'], $n, 'fail');
        Decisao::amostra($this->s['b'], $n, 'fail');
    }
    expect(Decisao::decidir(5))->toBe(['health' => 'down', 'incident' => 'updated']);

    $i = Decisao::incidentes()[0];
    expect($i->severity)->toBe('critical')->and($i->scope)->toBe('all_observed')->and($i->version)->toBe(2);
    $o = Decisao::outbox();
    expect(array_map(fn ($x) => [$x->reason, $x->decision_version], $o))->toBe([['opened', 1], ['worsened', 2]]);

    // B volta: alívio. Atualiza a linha do tempo e fica quieto.
    Decisao::amostra($this->s['a'], 6, 'fail');
    for ($n = 6; $n <= 8; $n++) {
        Decisao::amostra($this->s['b'], $n, 'pass');
        if ($n > 6) {
            Decisao::amostra($this->s['a'], $n, 'fail');
        }
    }
    expect(Decisao::decidir(8))->toBe(['health' => 'degraded', 'incident' => 'updated']);
    expect(Decisao::outbox())->toHaveCount(2);
});

it('evidência que SUMIU (unknown) não fecha o incidente nem repete o aviso: nunca um verde antigo', function () {
    Decisao::ambas($this->s, 0, 2, 'fail');
    Decisao::decidir(2);

    // Dez minutos depois, ninguém mediu nada: o presente é `unknown`.
    $r = Decisao::decidir(2, 600);

    expect($r)->toBe(['health' => 'unknown', 'incident' => null])
        ->and(DB::table('incidents')->whereNull('confirmed_end_at')->count())->toBe(1)
        ->and(DB::table('notification_outbox')->count())->toBe(1)
        ->and(DB::table('current_states')->value('health'))->toBe('unknown');
});

it('sonda suspensa sai do previsto: o domínio dela deixa de votar e a cobertura cai', function () {
    Decisao::ambas($this->s, 0, 2, 'fail');
    DB::table('probes')->where('id', $this->s['b']['probe'])->update(['status' => 'suspended']);

    $r = Decisao::decidir(2);

    // Um domínio só: abaixo do mínimo de 2. Cobertura insuficiente conclui NADA.
    expect($r)->toBe(['health' => 'unknown', 'incident' => null])
        ->and(DB::table('current_states')->value('coverage'))->toBe('insufficient');
});

it('o resultado MANUAL não entra na sequência agendada', function () {
    Decisao::ambas($this->s, 0, 1, 'fail');
    // Terceira "falha" de cada sonda é manual: não pode confirmar a queda.
    foreach (['a', 'b'] as $k) {
        Decisao::amostra($this->s[$k], 2, 'fail', ['execution_kind' => 'manual', 'scheduled_at' => null, 'manual_request_id' => (string) Illuminate\Support\Str::uuid()]);
    }

    expect(Decisao::decidir(2)['incident'])->toBeNull()->and(Decisao::incidentes())->toBe([]);
});

it('o histórico das transições é gravado com a data ORIGINAL e não duplica ao reavaliar', function () {
    Decisao::ambas($this->s, 0, 2, 'fail');
    Decisao::decidir(2);
    Decisao::decidir(2);

    $porSonda = DB::table('state_events')->whereNotNull('perspective_id')->get();
    expect($porSonda)->toHaveCount(2)
        ->and($porSonda->every(fn ($e) => $e->to_state === 'down' && strtotime($e->at) === Decisao::slot(2)->getTimestamp()))->toBeTrue();

    $agregado = DB::table('state_events')->whereNull('perspective_id')->get();
    expect($agregado)->toHaveCount(1)->and([$agregado[0]->from_state, $agregado[0]->to_state])->toBe(['unknown', 'down']);
});

it('a transação inteira volta se a decisão falha no meio: nem transição nem intenção ficam sozinhas', function () {
    Decisao::ambas($this->s, 0, 2, 'fail');
    // Quebra a outbox: o destinatário é obrigatório no esquema.
    config(['b3mon.notifications.destinations' => [['channel' => 'canal-inexistente', 'recipient' => 'x']]]);

    expect(fn () => DB::transaction(fn () => Decisao::decidir(2)))->toThrow(Illuminate\Database\QueryException::class);

    expect(DB::table('incidents')->count())->toBe(0)
        ->and(DB::table('current_states')->count())->toBe(0)
        ->and(DB::table('state_events')->count())->toBe(0)
        ->and(DB::table('notification_outbox')->count())->toBe(0);
});

it('uma avaliação MAIS VELHA que a projeção atual é descartada: o estado nunca regride, e a queda que já acabou não reabre', function () {
    Decisao::ambas($this->s, 0, 2, 'fail');
    Decisao::decidir(2);
    Decisao::ambas($this->s, 3, 5, 'pass');
    Decisao::decidir(5);   // recuperado
    $versao = DB::table('current_states')->value('version');

    // Um pedido atrasado, com o relógio de antes da recuperação, não pode reabrir nada.
    expect(Decisao::decidir(2))->toBe(['health' => 'up', 'incident' => null]);

    expect(DB::table('incidents')->count())->toBe(1)
        ->and(DB::table('incidents')->whereNull('confirmed_end_at')->count())->toBe(0)
        ->and(DB::table('notification_outbox')->count())->toBe(2)
        ->and(DB::table('current_states')->value('health'))->toBe('up')
        ->and(DB::table('current_states')->value('version'))->toBe($versao);
});

it('a versão do estado atual sobe quando algo MATERIAL muda, e só então', function () {
    Decisao::ambas($this->s, 0, 2, 'pass');
    Decisao::decidir(2);
    $v1 = DB::table('current_states')->value('version');
    expect(DB::table('current_states')->value('health'))->toBe('up');

    // Mesma saúde, mais evidência: não é um estado novo.
    Decisao::ambas($this->s, 3, 3, 'pass');
    Decisao::decidir(3);
    expect(DB::table('current_states')->value('version'))->toBe($v1);

    // A saúde muda: é.
    Decisao::ambas($this->s, 4, 6, 'fail');
    Decisao::decidir(6);
    expect(DB::table('current_states')->value('version'))->toBe($v1 + 1);
});

it('a validade do estado é a última observação elegível mais o frescor da política (132 s)', function () {
    Decisao::ambas($this->s, 0, 2, 'pass');
    Decisao::decidir(2);

    $e = DB::table('current_states')->first();
    expect(strtotime($e->last_valid_observation_at))->toBe(Decisao::slot(2)->getTimestamp())
        ->and(strtotime($e->valid_until))->toBe(Decisao::slot(2)->getTimestamp() + 132);
});

it('só a COBERTURA muda (mesma saúde, mesmos domínios): é um estado novo, e a versão sobe', function () {
    // Domínio A com DUAS sondas: uma mede, a outra está calada. Domínio B mede. Saúde `up`, 2 de 2 domínios, cobertura reduzida.
    $a2 = Tests\Support\Ingestao::sonda('sonda-a2', 'dom-a');
    foreach ([0, 1, 2] as $n) {
        Decisao::amostra($this->s['a'], $n, 'pass');
        Decisao::amostra($this->s['b'], $n, 'pass');
    }
    Decisao::decidir(2);
    $e = DB::table('current_states')->first();
    expect([$e->health, $e->coverage, $e->valid_domains, $e->planned_domains])->toBe(['up', 'reduced', 2, 2]);

    // A sonda calada passa a medir: cobertura plena, e NADA MAIS mudou.
    foreach ([0, 1, 2] as $n) {
        Decisao::amostra($a2, $n, 'pass');
    }
    Decisao::decidir(2, 11);
    $depois = DB::table('current_states')->first();

    expect([$depois->health, $depois->coverage, $depois->valid_domains, $depois->planned_domains])->toBe(['up', 'full', 2, 2])
        ->and($depois->version)->toBe($e->version + 1);
});

it('resultado gravado com a família IP errada para o assignment NÃO conta (defesa em profundidade do INV-03)', function () {
    for ($n = 0; $n <= 2; $n++) {
        foreach (['a', 'b'] as $k) {
            Decisao::amostra($this->s[$k], $n, 'fail', ['ip_family' => 'ipv6']);
        }
    }

    expect(Decisao::decidir(2)['incident'])->toBeNull()->and(Decisao::incidentes())->toBe([]);
});

it('a janela de histórico vale: uma queda confirmada ANTES da janela não decide o presente', function () {
    // Queda confirmada muito antes da janela (3600 s)...
    Decisao::ambas($this->s, 0, 2, 'fail');
    // ...e, 75 minutos depois, só dois sucessos (não confirmam recuperação).
    Decisao::ambas($this->s, 150, 151, 'pass');

    $r = Decisao::decidir(151);

    // Sem o histórico antigo não há estado confirmado: o presente é `unknown`, e NÃO uma queda herdada de 75 minutos atrás.
    expect($r)->toBe(['health' => 'unknown', 'incident' => null])->and(Decisao::incidentes())->toBe([]);
});

it('o Notifier é idempotente: pedir a mesma intenção duas vezes grava uma só', function () {
    Decisao::ambas($this->s, 0, 2, 'fail');
    Decisao::decidir(2);
    $i = Decisao::incidentes()[0];
    $n = new App\Decision\Notifier([['channel' => 'dev', 'recipient' => 'lab'], ['channel' => 'dev', 'recipient' => 'outro']]);

    DB::transaction(function () use ($n, $i) {
        $n->request($i->id, 1, 'opened', ['x' => 1]);
        $n->request($i->id, 1, 'opened', ['x' => 1]);
    });

    // O `opened` v1 de 'lab' já existia (da decisão), o de 'outro' é novo: dois destinatários, cada um UMA vez.
    expect(DB::table('notification_outbox')->where('reason', 'opened')->count())->toBe(2)
        ->and(DB::table('notification_outbox')->where('reason', 'opened')->pluck('recipient')->sort()->values()->all())->toBe(['lab', 'outro']);
});

it('o resultado de CANÁRIO (execution_kind canary), mesmo com horário agendado, não entra na sequência do check', function () {
    // O esquema só amarra agendado e manual; o canário pode trazer `scheduled_at`. Ele não é uma medição deste check.
    for ($n = 0; $n <= 2; $n++) {
        foreach (['a', 'b'] as $k) {
            Decisao::amostra($this->s[$k], $n, 'fail', ['execution_kind' => 'canary']);
        }
    }

    expect(Decisao::decidir(2)['incident'])->toBeNull()->and(Decisao::incidentes())->toBe([]);
});
