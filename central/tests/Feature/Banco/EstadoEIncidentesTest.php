<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Banco;

// `current_states`, `state_events`, `incidents` e `incident_events` (06 §2; 03 §5.1 e §7).

it('grava um estado atual coerente', function () {
    Banco::estado();

    expect(DB::table('current_states')->where('check_id', Banco::CHECK)->value('health'))->toBe('up');
});

it('INV-11: recusa um estado "verde" com cobertura insuficiente, e unknown sem abrangência unknown', function () {
    // Cobertura insuficiente não conclui nada: só ela pode deixar a saúde desconhecida, e a saúde desconhecida só vem dela.
    expect(Banco::sqlstate(fn () => Banco::estado(['coverage' => 'insufficient', 'health' => 'up', 'scope' => 'none'])))
        ->toBe(Banco::CHECK_VIOLADO);
    expect(Banco::sqlstate(fn () => Banco::estado(['coverage' => 'full', 'health' => 'unknown', 'scope' => 'unknown'])))
        ->toBe(Banco::CHECK_VIOLADO);
    expect(Banco::sqlstate(fn () => Banco::estado(['coverage' => 'insufficient', 'health' => 'unknown', 'scope' => 'none'])))
        ->toBe(Banco::CHECK_VIOLADO);

    // E o caso certo passa: insuficiente, unknown / unknown.
    Banco::estado(['coverage' => 'insufficient', 'health' => 'unknown', 'scope' => 'unknown', 'valid_domains' => 1]);
    expect(DB::table('current_states')->count())->toBe(1);
});

it('recusa mais domínios válidos que previstos, e uma validade anterior à medição', function () {
    expect(Banco::sqlstate(fn () => Banco::estado(['valid_domains' => 4, 'planned_domains' => 3])))->toBe(Banco::CHECK_VIOLADO);
    expect(Banco::sqlstate(fn () => Banco::estado(['valid_until' => '2026-09-28T15:00:00Z'])))->toBe(Banco::CHECK_VIOLADO);
});

it('guarda um estado por verificação e família: IPv4 e IPv6 são linhas separadas', function () {
    Banco::estado(['ip_family' => 'ipv4']);
    Banco::estado(['ip_family' => 'ipv6', 'health' => 'down', 'scope' => 'all_observed']);

    expect(Banco::sqlstate(fn () => Banco::estado(['ip_family' => 'ipv4'])))->toBe(Banco::UNICA);
    expect(DB::table('current_states')->count())->toBe(2);
});

it('registra uma transição uma vez só, e recusa transição de um estado para ele mesmo', function () {
    $evento = [
        'check_id' => Banco::CHECK, 'ip_family' => 'ipv4', 'perspective_id' => 'A', 'from_state' => 'unknown',
        'to_state' => 'down', 'at' => '2026-09-28T15:01:00Z', 'policy_revision' => 'critical-v1',
    ];
    DB::table('state_events')->insert($evento);

    // Reprocessar (replay, retry) a mesma transição não gera um segundo evento.
    expect(Banco::sqlstate(fn () => DB::table('state_events')->insert($evento)))->toBe(Banco::UNICA);
    expect(Banco::sqlstate(fn () => DB::table('state_events')->insert([...$evento, 'perspective_id' => 'B', 'from_state' => 'up', 'to_state' => 'up'])))
        ->toBe(Banco::CHECK_VIOLADO);
});

it('trata a transição agregada (sem perspectiva) como uma só, apesar do NULL', function () {
    $agregado = [
        'check_id' => Banco::CHECK, 'ip_family' => 'ipv4', 'perspective_id' => null, 'from_state' => 'up',
        'to_state' => 'degraded', 'at' => '2026-09-28T15:02:00Z', 'policy_revision' => 'critical-v1',
    ];
    DB::table('state_events')->insert($agregado);

    expect(Banco::sqlstate(fn () => DB::table('state_events')->insert($agregado)))->toBe(Banco::UNICA);
});

it('recusa UPDATE e DELETE em state_events: a transição é histórico', function () {
    DB::table('state_events')->insert([
        'check_id' => Banco::CHECK, 'ip_family' => 'ipv4', 'from_state' => 'unknown', 'to_state' => 'down',
        'at' => '2026-09-28T15:01:00Z', 'policy_revision' => 'critical-v1',
    ]);

    expect(Banco::sqlstate(fn () => DB::table('state_events')->update(['to_state' => 'up'])))->toBe(Banco::IMUTAVEL);
    expect(Banco::sqlstate(fn () => DB::table('state_events')->delete()))->toBe(Banco::IMUTAVEL);
});

// ---------------------------------------------------------------------------------------------------------------

it('03 §7: só UM incidente aberto por chave de correlação; fechado o episódio, um novo pode abrir', function () {
    $primeiro = Banco::incidente();

    expect(Banco::sqlstate(fn () => Banco::incidente()))->toBe(Banco::UNICA);

    // Outra chave (outra família) é outro episódio.
    Banco::incidente(['correlation_key' => 'service:'.Banco::CHECK.':ipv6', 'ip_family' => 'ipv6']);

    // Recuperado o primeiro, a mesma chave pode abrir de novo.
    DB::table('incidents')->where('id', $primeiro)->update([
        'observed_end_at' => '2026-09-28T15:10:00Z', 'confirmed_end_at' => '2026-09-28T15:11:00Z',
    ]);
    Banco::incidente();

    expect(DB::table('incidents')->count())->toBe(3);
});

it('03 §7: início observado e confirmação são coisas diferentes, e a recuperação vem depois da confirmação', function () {
    expect(Banco::sqlstate(fn () => Banco::incidente(['confirmed_at' => '2026-09-28T14:59:00Z'])))->toBe(Banco::CHECK_VIOLADO);
    expect(Banco::sqlstate(fn () => Banco::incidente(['confirmed_end_at' => '2026-09-28T15:00:30Z'])))->toBe(Banco::CHECK_VIOLADO);
});

it('recusa classe, gravidade e abrangência fora do glossário', function (string $coluna, string $valor) {
    expect(Banco::sqlstate(fn () => Banco::incidente([$coluna => $valor])))->toBe(Banco::CHECK_VIOLADO);
})->with([
    'classe' => ['incident_class', 'network'],
    'gravidade' => ['severity', 'fatal'],
    'abrangência' => ['scope', 'everything'],
]);

it('reconhecimento sem autor é recusado: toda ação humana tem quem a fez', function () {
    expect(Banco::sqlstate(fn () => Banco::incidente(['acknowledged_at' => '2026-09-28T15:02:00Z'])))->toBe(Banco::CHECK_VIOLADO);

    Banco::incidente(['acknowledged_at' => '2026-09-28T15:02:00Z', 'acknowledged_by' => (string) Str::uuid()]);
    expect(DB::table('incidents')->count())->toBe(1);
});

it('a linha do tempo do incidente é imutável e exige um incidente que existe', function () {
    expect(Banco::sqlstate(fn () => DB::table('incident_events')->insert([
        'incident_id' => (string) Str::uuid(), 'event_type' => 'opened', 'at' => '2026-09-28T15:01:00Z',
    ])))->toBe(Banco::FK);

    $id = Banco::incidente();
    DB::table('incident_events')->insert(['incident_id' => $id, 'event_type' => 'opened', 'at' => '2026-09-28T15:01:00Z']);

    expect(Banco::sqlstate(fn () => DB::table('incident_events')->update(['event_type' => 'recovered'])))->toBe(Banco::IMUTAVEL);
    expect(Banco::sqlstate(fn () => DB::table('incident_events')->delete()))->toBe(Banco::IMUTAVEL);
    expect(Banco::sqlstate(fn () => DB::table('incident_events')->insert(['incident_id' => $id, 'event_type' => 'resolved_by_hand', 'at' => '2026-09-28T15:02:00Z'])))
        ->toBe(Banco::CHECK_VIOLADO);   // não existe "fechar como resolvido" à mão (03 §7)
});
