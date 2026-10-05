<?php

declare(strict_types=1);

use App\Ingestion\HeartbeatValidator;
use Illuminate\Support\Facades\DB;
use Tests\Support\Ingestao;

/*
| B7b: POST /api/v1/probe/heartbeat. O validador é uma implementação própria das regras do contrato (ADR-014 em aberto), guardada pelo
| manifesto das fixtures; o endpoint guarda o ESTADO ATUAL da sonda, e uma sonda não relata a saúde de outra.
*/

dataset('fixtures de heartbeat', function () {
    $m = json_decode(file_get_contents(dirname(__DIR__, 4).'/contracts/fixtures/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($m['fixtures'] as $f) {
        if ($f['schema'] === 'heartbeat.schema.json') {
            yield $f['file'] => [$f];
        }
    }
});

function heartbeatValido(array $sonda, array $sobrepor = []): array
{
    $base = json_decode(file_get_contents(dirname(__DIR__, 4).'/contracts/fixtures/heartbeat/valido.json'), true);

    return array_replace_recursive($base, ['probe_id' => $sonda['probe']], $sobrepor);
}

function enviarHeartbeat(array $sonda, mixed $corpo, array $cabecalhos = []): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/probe/heartbeat', $corpo, ['Authorization' => 'Bearer '.$sonda['token']] + $cabecalhos);
}

it('concorda com o manifesto do contrato em cada fixture de heartbeat', function (array $f) {
    $dado = json_decode(file_get_contents(dirname(__DIR__, 4).'/contracts/fixtures/'.$f['file']), true, 512, JSON_THROW_ON_ERROR);

    $erros = (new HeartbeatValidator)->validate($dado);

    if ($f['valid']) {
        expect($erros)->toBe([], $f['file'].' é válida e foi recusada: '.implode(' | ', $erros));
    } else {
        expect($erros)->not->toBe([], $f['file'].' é inválida ('.$f['regra'].') e foi aceita');
    }
})->with('fixtures de heartbeat');

beforeEach(fn () => $this->a = Ingestao::sonda('sonda-a', 'dom-a'));

it('recusa sem credencial', function () {
    $this->postJson('/api/v1/probe/heartbeat', heartbeatValido($this->a))->assertStatus(401);
});

it('guarda o estado atual, com o desvio de relógio entre a sonda e a central', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-28T15:00:31.500Z'));

    enviarHeartbeat($this->a, heartbeatValido($this->a))->assertNoContent();

    $s = DB::table('probe_status')->first();
    expect($s->probe_id)->toBe($this->a['probe'])->and($s->heartbeats)->toBe(1)->and($s->bundle_revision)->toBe(3)
        // enviado 15:00:30,000; recebido 15:00:31,500
        ->and($s->clock_skew_ms)->toBe(1500)
        ->and(json_decode($s->payload, true)['build']['version'])->toBe('0.0.0-lab');
});

it('o desvio pode ser NEGATIVO (relógio da sonda adiantado) e não se perde o sinal', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-28T15:00:29Z'));

    enviarHeartbeat($this->a, heartbeatValido($this->a))->assertNoContent();

    expect(DB::table('probe_status')->value('clock_skew_ms'))->toBe(-1000);
});

it('um segundo heartbeat ATUALIZA a linha da sonda e conta', function () {
    enviarHeartbeat($this->a, heartbeatValido($this->a))->assertNoContent();
    enviarHeartbeat($this->a, heartbeatValido($this->a, ['uptime_s' => 86460, 'applied' => ['bundle_revision' => 4]]))->assertNoContent();

    $s = DB::table('probe_status')->get();
    expect($s)->toHaveCount(1)->and($s[0]->heartbeats)->toBe(2)->and($s[0]->bundle_revision)->toBe(4)
        ->and(json_decode($s[0]->payload, true)['uptime_s'])->toBe(86460);
});

it('revisão aplicada nula (sonda recém-iniciada, sem bundle) é guardada como nula, não como zero', function () {
    $recem = json_decode(file_get_contents(dirname(__DIR__, 4).'/contracts/fixtures/heartbeat/valido-recem-iniciada.json'), true);
    $recem['probe_id'] = $this->a['probe'];

    enviarHeartbeat($this->a, $recem)->assertNoContent();

    expect(DB::table('probe_status')->value('bundle_revision'))->toBeNull();
});

it('recusa o heartbeat que viola o contrato, dizendo o caminho e a regra e NUNCA o valor', function () {
    $r = enviarHeartbeat($this->a, heartbeatValido($this->a, ['applied' => ['local_policy_hash' => 'segredo-do-cliente']]))->assertStatus(400);

    expect($r->json('error'))->toBe('invalid_heartbeat')
        ->and(implode(' ', $r->json('errors')))->toContain('local_policy_hash')
        ->and($r->getContent())->not->toContain('segredo-do-cliente');
    expect(DB::table('probe_status')->count())->toBe(0);
});

it('recusa campo extra (não há URL de callback nem instrução)', function () {
    enviarHeartbeat($this->a, heartbeatValido($this->a, ['callback_url' => 'https://evil.example/']))->assertStatus(400);
});

it('recusa corpo que não é JSON', function () {
    $this->call('POST', '/api/v1/probe/heartbeat', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->a['token'], 'CONTENT_TYPE' => 'application/json'], '{nao-eh-json')
        ->assertStatus(400);
});

it('recusa o heartbeat de OUTRA sonda com 403: uma sonda não relata a saúde de outra', function () {
    $b = Ingestao::sonda('sonda-b', 'dom-b');

    enviarHeartbeat($this->a, heartbeatValido($b))->assertStatus(403);

    expect(DB::table('probe_status')->count())->toBe(0);
});

it('recusa acima de 64 KiB', function () {
    $r = $this->call('POST', '/api/v1/probe/heartbeat', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->a['token'], 'CONTENT_TYPE' => 'application/json'], str_repeat('x', 70000));

    $r->assertStatus(413);
});

it('relógio desconhecido com offset não nulo é recusado (INV-02: desconhecido não é zero)', function () {
    enviarHeartbeat($this->a, heartbeatValido($this->a, ['clock' => ['status' => 'unknown', 'offset_ms' => 0]]))->assertStatus(400);
    enviarHeartbeat($this->a, heartbeatValido($this->a, ['clock' => ['status' => 'unknown', 'offset_ms' => null]]))->assertNoContent();
});

it('capacidade fora do glossário é recusada', function () {
    enviarHeartbeat($this->a, heartbeatValido($this->a, ['capabilities' => ['ipv6' => 'not_contracted']]))->assertStatus(400);
});

it('sonda suspensa não registra heartbeat', function () {
    DB::table('probes')->where('id', $this->a['probe'])->update(['status' => 'suspended']);

    enviarHeartbeat($this->a, heartbeatValido($this->a))->assertStatus(401);
});
