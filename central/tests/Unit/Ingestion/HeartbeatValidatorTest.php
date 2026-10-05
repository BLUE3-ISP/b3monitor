<?php

declare(strict_types=1);

use App\Ingestion\HeartbeatValidator;

/*
| O validador de heartbeat, regra a regra. O teste de conformidade com o manifesto (tests/Feature/Sonda/HeartbeatTest.php) prova que
| as fixtures batem; este prova que CADA regra do schema é imposta, partindo da fixture válida e estragando UMA coisa.
*/

function heartbeatBase(): array
{
    return json_decode(file_get_contents(dirname(__DIR__, 4).'/contracts/fixtures/heartbeat/valido.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** Troca o valor no caminho `a.b.c`; `null` como segundo argumento remove a chave. */
function com(array $h, string $caminho, mixed $valor, bool $remover = false): array
{
    $ref = &$h;
    $partes = explode('.', $caminho);
    $ultima = array_pop($partes);
    foreach ($partes as $p) {
        $ref = &$ref[$p];
    }
    if ($remover) {
        unset($ref[$ultima]);
    } else {
        $ref[$ultima] = $valor;
    }

    return $h;
}

it('a fixture válida passa', function () {
    expect((new HeartbeatValidator)->validate(heartbeatBase()))->toBe([]);
});

it('recusa cada violação, e a mensagem cita o CAMINHO', function (string $caminho, mixed $valor) {
    $erros = (new HeartbeatValidator)->validate(com(heartbeatBase(), $caminho, $valor));

    expect($erros)->not->toBe([], "$caminho = ".json_encode($valor).' devia ser recusado')
        ->and(implode(' ', $erros))->toContain($caminho === 'clock.offset_ms' ? 'offset_ms' : explode('.', $caminho)[count(explode('.', $caminho)) - 1]);
})->with([
    'versão do schema' => ['schema_version', '2.0'],
    'probe_id sem UUID' => ['probe_id', 'nao-e-uuid'],
    'sent_at sem Z' => ['sent_at', '2026-09-28T15:00:30'],
    'sent_at com fuso' => ['sent_at', '2026-09-28T15:00:30+03:00'],
    'sent_at inexistente' => ['sent_at', '2026-02-30T15:00:30Z'],
    'build.version vazia' => ['build.version', ''],
    'build.commit longo demais' => ['build.commit', str_repeat('x', 129)],
    'revisão aplicada zero' => ['applied.bundle_revision', 0],
    'revisão aplicada texto' => ['applied.bundle_revision', '3'],
    'config_hash curto' => ['applied.config_hash', 'abc'],
    'hash da política em maiúsculas' => ['applied.local_policy_hash', str_repeat('A', 64)],
    'hash da política nulo' => ['applied.local_policy_hash', null],
    'uptime negativo' => ['uptime_s', -1],
    'uptime decimal' => ['uptime_s', 1.5],
    'última execução sem Z' => ['last_execution_at', '2026-09-28 15:00:02'],
    'atraso negativo' => ['schedule_lag_ms', -1],
    'spool.rows negativo' => ['spool.rows', -1],
    'spool.bytes negativo' => ['spool.bytes', -1],
    'spool.oldest_age_s negativo' => ['spool.oldest_age_s', -5],
    'spool.dropped negativo' => ['spool.dropped', -1],
    'disco livre negativo' => ['disk_free_bytes', -1],
    'capacidade ipv4 fora do glossário' => ['capabilities.ipv4', 'sim'],
    'capacidade icmp fora do glossário' => ['capabilities.icmp', 'not_contracted'],
    'estado do relógio fora do glossário' => ['clock.status', 'quase'],
    'reference_kind vazio' => ['clock.reference_kind', ''],
    'offset do relógio decimal' => ['clock.offset_ms', 1.5],
]);

it('recusa campo obrigatório ausente, em todo nível', function (string $caminho) {
    $erros = (new HeartbeatValidator)->validate(com(heartbeatBase(), $caminho, null, remover: true));

    expect($erros)->not->toBe([], "$caminho ausente devia ser recusado");
})->with([
    'schema_version', 'probe_id', 'sent_at', 'build', 'applied', 'uptime_s', 'last_execution_at', 'schedule_lag_ms', 'spool',
    'disk_free_bytes', 'capabilities', 'clock', 'build.version', 'build.commit', 'applied.bundle_revision', 'applied.config_hash',
    'applied.local_policy_hash', 'spool.rows', 'spool.bytes', 'spool.oldest_age_s', 'spool.dropped', 'capabilities.ipv4',
    'capabilities.ipv6', 'capabilities.icmp', 'clock.status', 'clock.offset_ms', 'clock.reference_kind',
]);

it('recusa campo extra, em todo nível (não há URL de callback nem instrução)', function (string $onde) {
    $h = heartbeatBase();
    $alvo = $onde === '' ? $h : $h[$onde];
    $alvo['extra'] = 'x';
    $h = $onde === '' ? $alvo : [...$h, $onde => $alvo];

    expect((new HeartbeatValidator)->validate($h))->not->toBe([], "campo extra em '$onde' devia ser recusado");
})->with(['', 'build', 'applied', 'spool', 'capabilities', 'clock']);

it('recusa o que não é objeto', function (mixed $h) {
    expect((new HeartbeatValidator)->validate($h))->not->toBe([]);
})->with([null, 'texto', 42, [[]], [1, 2]]);

it('aceita os nulos que o contrato permite, e só eles', function () {
    $h = heartbeatBase();
    $h['applied']['bundle_revision'] = null;
    $h['applied']['config_hash'] = null;
    $h['last_execution_at'] = null;
    $h['schedule_lag_ms'] = null;
    $h['spool']['oldest_age_s'] = null;
    $h['clock'] = ['status' => 'unknown', 'offset_ms' => null, 'reference_kind' => 'none'];

    expect((new HeartbeatValidator)->validate($h))->toBe([]);

    // O que NÃO pode ser nulo:
    foreach (['uptime_s', 'disk_free_bytes', 'sent_at', 'spool.rows', 'build.version'] as $c) {
        expect((new HeartbeatValidator)->validate(com(heartbeatBase(), $c, null)))->not->toBe([], "$c nulo devia ser recusado");
    }
});

it('limita a quantidade de erros devolvidos', function () {
    $h = com(com(com(heartbeatBase(), 'spool', 'x'), 'capabilities', 'y'), 'clock', 'z');
    foreach (range(1, 60) as $i) {
        $h["extra$i"] = 1;
    }

    expect(count((new HeartbeatValidator)->validate($h)))->toBeLessThanOrEqual(20);
});
