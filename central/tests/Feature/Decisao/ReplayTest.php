<?php

declare(strict_types=1);

use App\Ingestion\IngestResults;
use App\Work\Fila;
use App\Work\Handlers\DecideHandler;
use App\Work\Handlers\IngestHandler;
use App\Work\Resultado;
use App\Work\WorkQueue;
use App\Work\Worker;
use Illuminate\Support\Facades\DB;
use Tests\Support\Banco;
use Tests\Support\Decisao;
use Tests\Support\Ingestao;

/*
| B5, a prova que o item pede: replay de 100 amostras antigas NÃO dispara notificação, e o mesmo incidente é notificado UMA
| vez por decisão. Os dois pelo caminho INTEIRO: ingestão → fila ingest → fila decide → outbox.
|
| A decisão olha o PRESENTE (03 §9.4). Cem amostras antigas recompõem o histórico, mas o presente delas é `unknown`, e
| "só há evidência antiga e nenhuma cobertura atual" não inventa queda.
*/

/** @return list<array<string, mixed>> */
function resultadosDoReplay(array $sondas, int $de, int $ate, string $outcome): array
{
    $base = json_decode(file_get_contents(dirname(__DIR__, 4).'/contracts/fixtures/result/'.($outcome === 'pass' ? 'valido-http-ok' : 'valido-http-timeout').'.json'), true);
    $lote = [];
    for ($n = $de; $n <= $ate; $n++) {
        foreach ($sondas as $s) {
            $slot = Decisao::slot($n);
            $lote[] = array_merge($base, [
                'result_id' => (string) Illuminate\Support\Str::uuid(),
                'probe_id' => $s['probe'], 'assignment_id' => $s['assignment'],
                'scheduled_at' => $slot->format('Y-m-d\TH:i:s\Z'),
                'started_at' => $slot->modify('+2 seconds')->format('Y-m-d\TH:i:s\Z'),
                'finished_at' => $slot->modify('+3 seconds')->format('Y-m-d\TH:i:s\Z'),
            ]);
        }
    }

    return $lote;
}

/** Ingere o lote e roda as duas filas até esvaziar, no relógio de mentira `$agora`. */
function percorrer(array $lote, DateTimeImmutable $agora): array
{
    $respostas = app(IngestResults::class)->handle($lote, $agora);
    expect(array_unique(array_column($respostas, 'status')))->toBe(['accepted']);

    $q = new WorkQueue;
    $w = new Worker($q, leaseS: 60);
    $feitos = ['ingest' => 0, 'decide' => 0];

    // O `available_at` padrão é o relógio REAL do banco; os testes vivem no de mentira.
    DB::table('ingestion_work')->update(['available_at' => '2026-09-28T00:00:00Z']);
    while (($r = $w->step(Fila::Ingest, new IngestHandler, 'wi', $agora)) !== Resultado::Ocioso) {
        expect($r)->toBe(Resultado::Concluido);
        $feitos['ingest']++;
    }
    DB::table('decision_work')->update(['available_at' => '2026-09-28T00:00:00Z']);
    while (($r = $w->step(Fila::Decide, app(DecideHandler::class), 'wd', $agora)) !== Resultado::Ocioso) {
        expect($r)->toBe(Resultado::Concluido);
        $feitos['decide']++;
    }

    return $feitos;
}

beforeEach(fn () => $this->s = Decisao::sondas());

it('REPLAY de 100 amostras antigas, todas falhando: NENHUMA notificação e nenhum incidente', function () {
    // 50 slots (25 min) × 2 sondas = 100 amostras, todas de falha, entregues de uma vez 20 minutos depois do último slot.
    $lote = resultadosDoReplay($this->s, 0, 49, 'fail');
    expect($lote)->toHaveCount(100);

    $feitos = percorrer($lote, Decisao::slot(49)->modify('+20 minutes'));

    // Cem itens de ingestão viram UM pedido de avaliação (coalescido), e uma decisão só.
    expect($feitos)->toBe(['ingest' => 100, 'decide' => 1]);

    expect(DB::table('notification_outbox')->count())->toBe(0)
        ->and(DB::table('incidents')->count())->toBe(0)
        ->and(DB::table('incident_events')->count())->toBe(0)
        // O presente é `unknown`: ninguém mediu nada nos últimos 20 minutos. Nunca "queda atual" inventada.
        ->and(DB::table('current_states')->value('health'))->toBe('unknown')
        ->and(DB::table('current_states')->value('coverage'))->toBe('insufficient')
        // O HISTÓRICO, sim, foi recomposto, com a data em que aconteceu.
        ->and(DB::table('state_events')->whereNotNull('perspective_id')->where('to_state', 'down')->count())->toBe(2);
});

it('REPLAY de uma queda que já TERMINOU (falha e depois recuperação, tudo antigo): também nenhuma notificação', function () {
    $lote = array_merge(resultadosDoReplay($this->s, 0, 9, 'fail'), resultadosDoReplay($this->s, 10, 29, 'pass'));
    expect($lote)->toHaveCount(60);

    percorrer($lote, Decisao::slot(29)->modify('+20 minutes'));

    expect(DB::table('notification_outbox')->count())->toBe(0)->and(DB::table('incidents')->count())->toBe(0)
        ->and(DB::table('state_events')->whereNotNull('perspective_id')->count())->toBe(4);   // queda e volta, de cada sonda
});

it('uma queda ATUAL chegando em replay abre UM incidente e notifica UMA vez, por mais itens e decisões que haja', function () {
    // A queda está em curso: os slots vão até agora. Chegam todos de uma vez (reconexão da sonda).
    $lote = resultadosDoReplay($this->s, 0, 19, 'fail');

    percorrer($lote, Decisao::slot(19)->modify('+10 seconds'));

    $i = DB::table('incidents')->get();
    expect($i)->toHaveCount(1)->and($i[0]->severity)->toBe('critical')
        // O início observado é o do episódio, no PASSADO, e não o instante em que a central ficou sabendo.
        ->and(strtotime($i[0]->observed_start_at))->toBe(Decisao::slot(0)->getTimestamp())
        ->and(DB::table('notification_outbox')->count())->toBe(1);

    // Chegam mais amostras da MESMA queda, e a fila decide de novo várias vezes: nenhuma intenção nova.
    $mais = resultadosDoReplay($this->s, 20, 24, 'fail');
    percorrer($mais, Decisao::slot(24)->modify('+10 seconds'));
    foreach ([1, 2, 3] as $_) {
        DB::table('decision_work')->insert(['check_id' => Banco::CHECK, 'ip_family' => 'ipv4', 'available_at' => '2026-09-28T00:00:00Z']);
        (new Worker(new WorkQueue))->step(Fila::Decide, app(DecideHandler::class), 'wd', Decisao::slot(24)->modify('+10 seconds'));
    }

    expect(DB::table('incidents')->count())->toBe(1)
        ->and(DB::table('incident_events')->where('event_type', 'opened')->count())->toBe(1)
        ->and(DB::table('notification_outbox')->where('reason', 'opened')->count())->toBe(1);
});

it('o MESMO incidente é notificado uma vez POR DECISÃO: abertura, agravamento e recuperação, cada uma com a sua versão', function () {
    // Abre degradado (A cai, B de pé), agrava (B cai), recupera (as duas voltam).
    for ($n = 0; $n <= 2; $n++) {
        Decisao::amostra($this->s['a'], $n, 'fail');
        Decisao::amostra($this->s['b'], $n, 'pass');
    }
    Decisao::decidir(2);
    for ($n = 3; $n <= 5; $n++) {
        Decisao::amostra($this->s['a'], $n, 'fail');
        Decisao::amostra($this->s['b'], $n, 'fail');
    }
    Decisao::decidir(5);
    Decisao::ambas($this->s, 6, 8, 'pass');
    Decisao::decidir(8);

    // Repetir cada decisão não cria intenção nova.
    foreach ([2, 5, 8] as $n) {
        Decisao::decidir($n);
    }

    $o = array_map(fn ($x) => [$x->reason, $x->decision_version], Decisao::outbox());
    expect($o)->toBe([['opened', 1], ['worsened', 2], ['recovered', 3]]);
    expect(DB::table('incidents')->count())->toBe(1);
});

it('a unicidade da outbox é a rede por baixo: a mesma intenção inserida duas vezes é uma só', function () {
    Decisao::ambas($this->s, 0, 2, 'fail');
    Decisao::decidir(2);
    $i = Decisao::incidentes()[0];

    $dup = fn () => DB::table('notification_outbox')->insert([
        'incident_id' => $i->id, 'decision_version' => 1, 'reason' => 'opened', 'channel' => 'dev', 'recipient' => 'lab', 'payload' => '{}',
    ]);

    expect(Banco::sqlstate($dup))->toBe(Banco::UNICA);
});
