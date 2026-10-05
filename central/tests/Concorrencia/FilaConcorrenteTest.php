<?php

declare(strict_types=1);

use App\Work\Fila;
use App\Work\Handler;
use App\Work\Resultado;
use App\Work\WorkQueue;
use App\Work\Worker;
use Illuminate\Support\Facades\DB;
use Tests\Support\Trabalho;

/*
| O que só se prova com COMMIT de verdade: várias conexões, e um worker que morre de verdade.
|
| Estes testes NÃO rodam dentro da transação do RefreshDatabase (uma conexão não enxerga o que a outra ainda não
| commitou), então usam `DatabaseMigrations`: o esquema sobe do zero, o teste commita o que quiser, e o esquema
| desce no fim. É mais lento, por isso são poucos e só os que precisam.
*/

beforeEach(function () {
    DB::unprepared('DROP TABLE IF EXISTS efeitos_teste');
    DB::unprepared('CREATE TABLE efeitos_teste (item_id bigint NOT NULL, quem text NOT NULL)');
    $this->q = new WorkQueue(maxAttempts: 3, backoffBaseS: 10, backoffCapS: 60);
});

afterEach(function () {
    // A segunda conexão pode ter ficado com transação aberta (um teste que falha antes do rollback). Sem fechá-la, o
    // `migrate:fresh` do PRÓXIMO teste espera o lock dela para sempre e a suíte trava (visto na mutação).
    DB::purge('segunda');
    DB::statement('RESET lock_timeout');
    DB::unprepared('DROP TABLE IF EXISTS efeitos_teste');
});

function efeitos(): array
{
    return array_map(fn ($l) => $l->quem.':'.$l->item_id, DB::select('select * from efeitos_teste order by item_id, quem'));
}

/** Uma segunda conexão ao mesmo banco de teste. */
function outraConexao(): Illuminate\Database\Connection
{
    config(['database.connections.segunda' => config('database.connections.'.config('database.default'))]);
    DB::purge('segunda');

    return DB::connection('segunda');
}

it('SKIP LOCKED: um item que outro worker está pegando agora é PULADO, e o claim não espera', function () {
    // Dois itens pendentes, COMMITADOS (fora de qualquer transação de teste).
    DB::table('decision_work')->insert([
        ['check_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'ip_family' => 'ipv4', 'available_at' => '2026-09-28T15:00:00Z'],
        ['check_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'ip_family' => 'ipv4', 'available_at' => '2026-09-28T15:00:01Z'],
    ]);
    $primeiro = (int) DB::table('decision_work')->orderBy('id')->value('id');
    $segundo = (int) DB::table('decision_work')->orderByDesc('id')->value('id');

    // Outra conexão está PEGANDO o primeiro agora (lock aberto, sem commit).
    $outra = outraConexao();
    $outra->beginTransaction();
    $outra->selectOne('select id from decision_work where id = ? for update', [$primeiro]);

    // Sem SKIP LOCKED o claim ficaria esperando o lock: o `lock_timeout` transforma a espera em erro, em vez de
    // travar a suíte.
    DB::statement("set lock_timeout = '2s'");
    try {
        $pego = $this->q->claim(Fila::Decide, 'w2', 60, Trabalho::t());
    } finally {
        $outra->rollBack();
        DB::statement('RESET lock_timeout');
    }

    expect($pego?->id)->toBe($segundo);
    // Liberado o lock, o primeiro volta a poder ser pego.
    expect($this->q->claim(Fila::Decide, 'w3', 60, Trabalho::t())?->id)->toBe($primeiro);
});

it('hold TRANCA a linha: o reconciliador de outra conexão não tira o item de quem o está concluindo', function () {
    DB::table('decision_work')->insert(['check_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'ip_family' => 'ipv4', 'available_at' => '2026-09-28T15:00:00Z']);
    $item = $this->q->claim(Fila::Decide, 'A', 30, Trabalho::t());
    $outra = outraConexao();
    $outra->statement("set lock_timeout = '500ms'");

    $codigo = null;
    DB::transaction(function () use ($item, $outra, &$codigo) {
        expect($this->q->hold(Fila::Decide, $item->id, 'A'))->toBeTrue();

        // Com a lease vencida, o reconciliador (outra conexão) tentaria devolver o item... mas A está concluindo.
        try {
            $outra->update("update decision_work set status = 'pending', lease_owner = null, lease_expires_at = null where id = ?", [$item->id]);
        } catch (Illuminate\Database\QueryException $e) {
            $codigo = $e->getCode();
        }
    });

    // 55P03 = lock_not_available: a outra conexão ESPEROU pelo lock e desistiu no timeout.
    expect($codigo)->toBe('55P03')
        ->and(DB::table('decision_work')->where('id', $item->id)->value('status'))->toBe('processing');
});

it('dois claims concorrentes nunca recebem o mesmo item', function () {
    DB::table('decision_work')->insert(array_map(
        fn ($n) => ['check_id' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $n), 'ip_family' => 'ipv4', 'available_at' => '2026-09-28T15:00:00Z'],
        range(1, 6),
    ));
    $outra = outraConexao();

    // Entrelaçados: A pega e deixa a transação aberta (o `FOR UPDATE` dele segura), B pega por outra conexão.
    $pegos = [];
    for ($i = 0; $i < 3; $i++) {
        $pegos[] = $this->q->claim(Fila::Decide, 'A', 60, Trabalho::t())->id;
        $linha = $outra->selectOne(
            "update decision_work set status='processing', attempts=attempts+1, lease_owner='B', lease_expires_at = ?::timestamptz
              where id = (select id from decision_work where status='pending' order by available_at, id for update skip locked limit 1)
              returning id",
            ['2026-09-28T15:01:10Z'],
        );
        $pegos[] = $linha->id;
    }

    expect($pegos)->toHaveCount(6)->and(array_unique($pegos))->toHaveCount(6);
});

it('WORKER MORTO no meio (SIGKILL de verdade): o efeito some, o item não se perde, e o reenvio o processa UMA vez', function () {
    $item = Trabalho::pendente(Fila::Ingest);
    $pronto = sys_get_temp_dir().'/b3mon-travado-'.getmypid();
    @unlink($pronto);

    $proc = proc_open(
        [PHP_BINARY, __DIR__.'/worker_travado.php', $pronto, 'worker-morto'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 2),
    );
    expect(is_resource($proc))->toBeTrue();

    // Espera o worker chegar ao meio do processamento: efeito gravado, transação aberta, item trancado.
    $limite = microtime(true) + 20;
    while (! file_exists($pronto) && microtime(true) < $limite) {
        usleep(50_000);
    }
    // Sem bloquear: o filho segura o pipe aberto enquanto dorme, e um `stream_get_contents` esperaria o sono inteiro.
    stream_set_blocking($pipes[2], false);
    $erro = (string) stream_get_contents($pipes[2]);
    expect(file_exists($pronto))->toBeTrue("o worker não chegou ao meio do processamento: $erro");

    // Enquanto ele está vivo e no meio, o item está trancado e nenhum efeito é visível.
    expect(DB::table('ingestion_work')->where('id', $item->id)->value('status'))->toBe('processing')
        ->and(efeitos())->toBe([]);

    // Mata o processo, sem aviso: é a queda da máquina, o OOM killer.
    posix_kill(proc_get_status($proc)['pid'], SIGKILL);
    proc_close($proc);
    @unlink($pronto);

    // O PostgreSQL desfez a transação inteira: o efeito não existe, e o item continua `processing` do morto.
    $l = DB::table('ingestion_work')->where('id', $item->id)->first();
    expect(efeitos())->toBe([])
        ->and($l->status)->toBe('processing')->and($l->lease_owner)->toBe('worker-morto');

    // Antes de a lease vencer, o reconciliador NÃO mexe (o worker poderia estar só lento).
    expect($this->q->reconcile(Fila::Ingest, now()->utc()->toDateTimeImmutable()))->toBe(['requeued' => 0, 'dead' => 0]);

    // Vencida a lease (30 s), o item volta, e outro worker o processa.
    $depois = now()->utc()->addSeconds(31)->toDateTimeImmutable();
    expect($this->q->reconcile(Fila::Ingest, $depois))->toBe(['requeued' => 1, 'dead' => 0]);

    $h = Trabalho::handler(fn ($i) => DB::insert('insert into efeitos_teste (item_id, quem) values (?, ?)', [$i->id, 'vivo']));
    $w = new Worker($this->q, leaseS: 30);
    expect($w->step(Fila::Ingest, $h, 'worker-vivo', $depois->modify('+1 second')))->toBe(Resultado::Concluido);

    // Nada se perdeu e nada aconteceu duas vezes: um efeito, do worker vivo; o item concluído; tentativa 2.
    $l = DB::table('ingestion_work')->where('id', $item->id)->first();
    expect(efeitos())->toBe(['vivo:'.$item->id])
        ->and($l->status)->toBe('done')->and($l->attempts)->toBe(2)
        // E o resultado, que é o que importa, continua no ledger e em results.
        ->and(DB::table('result_receipts')->where('result_id', $item->result_id)->count())->toBe(1)
        ->and(DB::table('results')->where('result_id', $item->result_id)->count())->toBe(1)
        // Um segundo passo não encontra nada para fazer.
        ->and($w->step(Fila::Ingest, $h, 'worker-vivo', $depois->modify('+2 seconds')))->toBe(Resultado::Ocioso)
        ->and(efeitos())->toBe(['vivo:'.$item->id]);
});
