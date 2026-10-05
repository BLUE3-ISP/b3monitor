<?php

declare(strict_types=1);

use App\Notifications\DevLogProvider;
use App\Notifications\Provider;
use App\Work\Handler;
use App\Work\Handlers\NotifyHandler;
use App\Work\Resultado;
use App\Work\WorkQueue;
use App\Work\Worker;
use Illuminate\Support\Facades\DB;
use Tests\Support\Decisao;
use Tests\Support\Trabalho;

/*
| B5: a entrega. O canal `dev` é SIMULADO (escreve num arquivo, sem token), a entrega é pelo menos uma vez, e canal sem
| provedor falha alto em vez de descartar o aviso.
*/

beforeEach(function () {
    $this->log = sys_get_temp_dir().'/b3mon-dev-'.getmypid().'-'.bin2hex(random_bytes(3)).'.log';
    @unlink($this->log);
    $this->q = new WorkQueue(maxAttempts: 3, backoffBaseS: 1, backoffCapS: 1);
    $this->w = new Worker($this->q, leaseS: 30);

    // Uma queda confirmada: a decisão grava a intenção na outbox (disponível "desde sempre").
    $s = Decisao::sondas();
    Decisao::ambas($s, 0, 2, 'fail');
    Decisao::decidir(2);
    DB::table('notification_outbox')->update(['available_at' => '2026-09-28T15:00:00Z']);
    $this->outboxId = (int) DB::table('notification_outbox')->value('id');
});

afterEach(fn () => @unlink($this->log));

function entregar(object $teste, ?Handler $h = null, string $quando = '+0 seconds'): Resultado
{
    $h ??= new NotifyHandler(['dev' => new DevLogProvider($teste->log)]);

    return $teste->w->step(App\Work\Fila::Notify, $h, 'w', Trabalho::t($quando));
}

it('o canal dev escreve uma linha que se IDENTIFICA como simulada, e registra a tentativa aceita', function () {
    expect(entregar($this))->toBe(Resultado::Concluido);

    $linhas = file($this->log, FILE_IGNORE_NEW_LINES);
    expect($linhas)->toHaveCount(1)->and($linhas[0])->toStartWith('[SIMULADO] ');
    $j = json_decode(substr($linhas[0], strlen('[SIMULADO] ')), true);
    expect($j)->toMatchArray([
        'simulated' => true, 'provider' => 'dev-log', 'channel' => 'dev', 'recipient' => 'lab',
        'reason' => 'opened', 'decision_version' => 1, 'idempotency_key' => 'outbox-'.$this->outboxId,
    ])->and($j['payload']['health'])->toBe('down');

    $t = DB::table('notification_attempts')->get();
    expect($t)->toHaveCount(1)
        ->and([$t[0]->outbox_id, $t[0]->attempt_no, $t[0]->provider, $t[0]->status])->toBe([$this->outboxId, 1, 'dev-log', 'accepted_by_provider'])
        ->and(DB::table('notification_outbox')->where('id', $this->outboxId)->value('status'))->toBe('done');
});

it('a mesma intenção não é entregue de novo depois de concluída', function () {
    entregar($this);

    expect(entregar($this, null, '+1 minute'))->toBe(Resultado::Ocioso)
        ->and(file($this->log))->toHaveCount(1);
});

it('provedor que falha: o item volta com espera, a segunda tentativa entrega, e nada fica pela metade', function () {
    $interno = new DevLogProvider($this->log);
    $provedor = new class($interno) implements Provider
    {
        public bool $falhou = false;

        public function __construct(private DevLogProvider $interno) {}

        public function name(): string
        {
            return $this->interno->name();
        }

        public function send(stdClass $outbox, string $idempotencyKey): string
        {
            if (! $this->falhou) {
                $this->falhou = true;
                throw new RuntimeException('provedor fora do ar');
            }

            return $this->interno->send($outbox, $idempotencyKey);
        }
    };
    $h = new NotifyHandler(['dev' => $provedor]);

    expect(entregar($this, $h))->toBe(Resultado::Falhou);
    $o = DB::table('notification_outbox')->where('id', $this->outboxId)->first();
    expect($o->status)->toBe('pending')->and($o->last_error)->toContain('provedor fora do ar')
        ->and(DB::table('notification_attempts')->count())->toBe(0)
        ->and(file_exists($this->log))->toBeFalse();

    expect(entregar($this, $h, '+1 minute'))->toBe(Resultado::Concluido);
    // É a SEGUNDA tentativa do item: o número reflete isso.
    expect(DB::table('notification_attempts')->value('attempt_no'))->toBe(2)
        ->and(file($this->log))->toHaveCount(1);
});

it('PELO MENOS UMA VEZ: se o processo morre depois do envio e antes do commit, a repetição leva a MESMA chave', function () {
    $provedor = new DevLogProvider($this->log);
    // Um handler que envia e, antes de registrar a tentativa, "morre" (a transação volta; o arquivo não).
    $h = new class($provedor, new NotifyHandler(['dev' => $provedor])) implements Handler
    {
        public bool $morrer = true;

        public function __construct(private DevLogProvider $p, private NotifyHandler $real) {}

        public function handle(stdClass $item, DateTimeImmutable $now): void
        {
            if ($this->morrer) {
                $this->morrer = false;
                $this->p->send($item, 'outbox-'.$item->id);

                throw new RuntimeException('morreu depois de enviar');
            }
            $this->real->handle($item, $now);
        }
    };
    expect(entregar($this, $h))->toBe(Resultado::Falhou);
    expect(entregar($this, $h, '+1 minute'))->toBe(Resultado::Concluido);

    // Duas linhas, UMA chave: a entrega duplicada é visível e identificável, e o produto não promete "exatamente uma vez".
    $chaves = array_map(fn ($l) => json_decode(substr($l, 11), true)['idempotency_key'], file($this->log, FILE_IGNORE_NEW_LINES));
    expect($chaves)->toBe(['outbox-'.$this->outboxId, 'outbox-'.$this->outboxId])
        ->and(DB::table('notification_attempts')->count())->toBe(1);
});

it('canal SEM provedor (telegram, push, e-mail) falha alto e para como dead: o aviso não some em silêncio', function () {
    DB::table('notification_outbox')->where('id', $this->outboxId)->update(['channel' => 'telegram']);
    $h = new NotifyHandler(['dev' => new DevLogProvider($this->log)]);

    foreach ([1, 2, 3] as $n) {
        DB::table('notification_outbox')->where('id', $this->outboxId)->update(['available_at' => '2026-09-28T15:00:00Z']);
        expect(entregar($this, $h, "+{$n} minutes"))->toBe(Resultado::Falhou);
    }

    $o = DB::table('notification_outbox')->where('id', $this->outboxId)->first();
    expect($o->status)->toBe('dead')->and($o->last_error)->toContain('telegram')->and($o->last_error)->toContain('não tem provedor')
        ->and(file_exists($this->log))->toBeFalse();
});

it('o provedor padrão do canal dev vem da configuração', function () {
    $h = new NotifyHandler;   // sem mapa: lê `b3mon.notifications.providers`
    config(['b3mon.notifications.dev_log_path' => $this->log]);

    expect(entregar($this, $h))->toBe(Resultado::Concluido)
        ->and(file($this->log))->toHaveCount(1);
});
