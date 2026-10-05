<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\Banco;

// `notification_outbox`, `notification_attempts` e `audit_events` (06 §10; 03 §9.4; glossário 14 §2).

function intencao(string $incidente, array $sobrepor = []): array
{
    return array_merge([
        'incident_id' => $incidente, 'decision_version' => 1, 'reason' => 'incident_opened', 'channel' => 'dev',
        'recipient' => 'plantao', 'payload' => json_encode(['lab' => true]),
    ], $sobrepor);
}

it('deduplica a decisão lógica: a mesma intenção não entra duas vezes', function () {
    $incidente = Banco::incidente();
    DB::table('notification_outbox')->insert(intencao($incidente));

    // (incident_id, decision_version, reason, channel, recipient) é a chave única.
    expect(Banco::sqlstate(fn () => DB::table('notification_outbox')->insert(intencao($incidente))))->toBe(Banco::UNICA);

    // Mudando qualquer um dos cinco campos é OUTRA decisão, e entra.
    DB::table('notification_outbox')->insert(intencao($incidente, ['decision_version' => 2]));
    DB::table('notification_outbox')->insert(intencao($incidente, ['reason' => 'incident_recovered']));
    DB::table('notification_outbox')->insert(intencao($incidente, ['channel' => 'telegram']));
    DB::table('notification_outbox')->insert(intencao($incidente, ['recipient' => 'gestor']));

    expect(DB::table('notification_outbox')->count())->toBe(5);
});

it('identifica no banco o canal simulado: só o `dev` é simulado', function () {
    $incidente = Banco::incidente();
    DB::table('notification_outbox')->insert(intencao($incidente, ['channel' => 'dev']));
    DB::table('notification_outbox')->insert(intencao($incidente, ['channel' => 'telegram']));

    $simulado = DB::table('notification_outbox')->pluck('simulated', 'channel')->all();
    expect($simulado['dev'])->toBeTrue()->and($simulado['telegram'])->toBeFalse();

    // A coluna é gerada: a aplicação não pode contrariar o banco.
    expect(Banco::sqlstate(fn () => DB::table('notification_outbox')->insert(intencao($incidente, ['channel' => 'push', 'simulated' => true]))))
        ->not->toBeNull();
});

it('recusa canal fora do glossário, versão de decisão zero e intenção sem incidente', function () {
    $incidente = Banco::incidente();

    expect(Banco::sqlstate(fn () => DB::table('notification_outbox')->insert(intencao($incidente, ['channel' => 'sms']))))->toBe(Banco::CHECK_VIOLADO);
    expect(Banco::sqlstate(fn () => DB::table('notification_outbox')->insert(intencao($incidente, ['decision_version' => 0]))))->toBe(Banco::CHECK_VIOLADO);
    expect(Banco::sqlstate(fn () => DB::table('notification_outbox')->insert(intencao('99999999-9999-4999-8999-999999999999'))))->toBe(Banco::FK);
});

it('mantém o lease da outbox coerente, como o do trabalho de ingestão', function () {
    $incidente = Banco::incidente();
    DB::table('notification_outbox')->insert(intencao($incidente));

    expect(Banco::sqlstate(fn () => DB::table('notification_outbox')->update(['status' => 'processing'])))->toBe(Banco::CHECK_VIOLADO);
    expect(Banco::sqlstate(fn () => DB::table('notification_outbox')->update(['status' => 'done'])))->toBe(Banco::CHECK_VIOLADO);
});

it('registra tentativas numeradas e imutáveis, com o status do glossário', function () {
    $incidente = Banco::incidente();
    $outbox = DB::table('notification_outbox')->insertGetId(intencao($incidente));
    $tentativa = ['outbox_id' => $outbox, 'attempt_no' => 1, 'provider' => 'dev-log', 'status' => 'accepted_by_provider'];

    DB::table('notification_attempts')->insert($tentativa);

    expect(Banco::sqlstate(fn () => DB::table('notification_attempts')->insert($tentativa)))->toBe(Banco::UNICA);
    expect(Banco::sqlstate(fn () => DB::table('notification_attempts')->insert([...$tentativa, 'attempt_no' => 2, 'status' => 'delivered'])))
        ->toBe(Banco::CHECK_VIOLADO);   // `delivered` não existe: aceito pelo provedor não é entregue
    expect(Banco::sqlstate(fn () => DB::table('notification_attempts')->update(['status' => 'failed'])))->toBe(Banco::IMUTAVEL);
    expect(Banco::sqlstate(fn () => DB::table('notification_attempts')->delete()))->toBe(Banco::IMUTAVEL);
});

it('audita sem poder reescrever: o evento de auditoria é imutável', function () {
    DB::table('audit_events')->insert([
        'actor_type' => 'user', 'actor_id' => 'samir', 'action' => 'incident.acknowledge',
        'resource_type' => 'incident', 'resource_id' => 'x', 'diff' => json_encode(['acknowledged' => true]),
    ]);

    expect(Banco::sqlstate(fn () => DB::table('audit_events')->update(['action' => 'nada'])))->toBe(Banco::IMUTAVEL);
    expect(Banco::sqlstate(fn () => DB::table('audit_events')->delete()))->toBe(Banco::IMUTAVEL);
    expect(Banco::sqlstate(fn () => DB::table('audit_events')->insert([
        'actor_type' => 'robot', 'action' => 'x', 'resource_type' => 'y',
    ])))->toBe(Banco::CHECK_VIOLADO);
});
