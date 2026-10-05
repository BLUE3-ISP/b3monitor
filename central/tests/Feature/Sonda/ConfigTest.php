<?php

declare(strict_types=1);

use App\Probes\BundleBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Banco;
use Tests\Support\Ingestao;

/*
| B7b: GET /api/v1/probe/config. A sonda recebe o SEU bundle, e só ele; o bundle nunca leva política local de redes; a revisão sobe
| sozinha quando o que a sonda executa muda; o jitter é determinístico.
*/

function bundleDe(array $sonda): Illuminate\Testing\TestResponse
{
    return test()->getJson('/api/v1/probe/config', ['Authorization' => 'Bearer '.$sonda['token']]);
}


function formaDoJson(mixed $v): mixed
{
    if (is_array($v) && ! array_is_list($v)) {
        return array_map('formaDoJson', $v);
    }

    return is_array($v) ? 'lista' : 'folha';
}

beforeEach(function () {
    $this->check = Banco::check();
    $this->a = Ingestao::sonda('sonda-a', 'dom-a');
});

it('recusa sem credencial de sonda', function () {
    $this->getJson('/api/v1/probe/config')->assertStatus(401);
    $this->getJson('/api/v1/probe/config', ['Authorization' => 'Bearer b3m_p_errado'])->assertStatus(401);
});

it('entrega o bundle com a FORMA da fixture do contrato', function () {
    $r = bundleDe($this->a)->assertOk();
    $fixture = json_decode(file_get_contents(dirname(__DIR__, 4).'/contracts/fixtures/bundle/valido-http.json'), true);

    expect(formaDoJson($r->json()))->toBe(formaDoJson($fixture));
    $r->assertJsonPath('schema_version', '1.0')->assertJsonPath('probe_id', $this->a['probe'])
        ->assertJsonCount(1, 'assignments')
        ->assertJsonPath('assignments.0.assignment_id', $this->a['assignment'])
        ->assertJsonPath('assignments.0.config_generation', 3)
        ->assertJsonPath('assignments.0.kind', 'http')
        ->assertJsonPath('assignments.0.ip_family', 'ipv4')
        ->assertJsonPath('assignments.0.target.url', 'https://portal.monitor.example/health')
        ->assertJsonPath('assignments.0.schedule.epoch', '2026-09-28T14:00:00Z')
        ->assertJsonPath('assignments.0.schedule.interval_s', 30)
        ->assertJsonPath('assignments.0.schedule.timeout_ms', 5000)
        ->assertJsonPath('assignments.0.policy_revision', 'critical-v1')
        ->assertJsonPath('assignments.0.path_profile_id', null)
        ->assertJsonPath('assignments.0.credential_ref', null);
    expect($r->headers->get('Cache-Control'))->toContain('no-store');
});

it('o bundle NÃO traz política local de redes nem campo de execução (INV-09)', function () {
    $json = bundleDe($this->a)->assertOk()->getContent();

    foreach (['allowed_cidrs', 'allowed_destinations', 'allowed_kinds', 'callback', 'shell', 'command', 'script'] as $proibido) {
        expect($json)->not->toContain($proibido);
    }
});

it('só leva o que é DESTA sonda: o assignment de outra sonda nunca aparece', function () {
    $b = Ingestao::sonda('sonda-b', 'dom-b');
    $outroCheck = Banco::check(['id' => (string) Str::uuid(), 'name' => 'so-da-b']);
    DB::table('assignments')->where('id', $b['assignment'])->update(['check_id' => $outroCheck]);

    $deA = bundleDe($this->a)->assertOk()->json('assignments.*.assignment_id');
    $deB = bundleDe($b)->assertOk()->json('assignments.*.assignment_id');

    expect($deA)->toBe([$this->a['assignment']])->and($deB)->toBe([$b['assignment']]);
});

it('a identidade vem da credencial, nunca de um parâmetro: ?probe_id= não troca de sonda', function () {
    $b = Ingestao::sonda('sonda-b', 'dom-b');

    $r = $this->getJson('/api/v1/probe/config?probe_id='.$b['probe'], ['Authorization' => 'Bearer '.$this->a['token']])->assertOk();

    expect($r->json('probe_id'))->toBe($this->a['probe'])->and($r->json('assignments.*.assignment_id'))->toBe([$this->a['assignment']]);
});

it('um assignment cujo check ainda não existe não entra no bundle', function () {
    DB::table('checks')->where('id', $this->check)->delete();

    bundleDe($this->a)->assertOk()->assertJsonCount(0, 'assignments');
});

it('sonda suspensa não recebe bundle', function () {
    DB::table('probes')->where('id', $this->a['probe'])->update(['status' => 'suspended']);

    bundleDe($this->a)->assertStatus(401);
});

it('a validade do bundle é a configurada, e emitido/expira vêm em UTC com Z', function () {
    config(['b3mon.bundle.validity_s' => 7200]);

    $r = bundleDe($this->a)->assertOk();

    expect($r->json('issued_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and(strtotime($r->json('expires_at')) - strtotime($r->json('issued_at')))->toBe(7200);
});

// ---- A revisão sobe sozinha ----

it('a revisão começa em 1 e SOBE quando o conjunto da sonda muda (gatilho do banco)', function () {
    $r0 = bundleDe($this->a)->json('bundle_revision');
    // `Ingestao::sonda` inseriu um assignment: a revisão já subiu uma vez desde o cadastro (1 → 2).
    expect($r0)->toBe(2);

    // Um assignment novo.
    DB::table('assignments')->insert(['id' => (string) Str::uuid(), 'probe_id' => $this->a['probe'], 'check_id' => $this->check, 'ip_family' => 'ipv6', 'current_generation' => 1]);
    $r1 = bundleDe($this->a)->json('bundle_revision');
    // Nova geração.
    DB::table('assignments')->where('id', $this->a['assignment'])->update(['current_generation' => 4]);
    $r2 = bundleDe($this->a)->json('bundle_revision');
    // Mudou a DEFINIÇÃO do check que a sonda executa.
    DB::table('checks')->where('id', $this->check)->update(['interval_s' => 60]);
    $r3 = bundleDe($this->a)->json('bundle_revision');
    // Um assignment saiu.
    DB::table('assignments')->where('ip_family', 'ipv6')->where('probe_id', $this->a['probe'])->delete();
    $r4 = bundleDe($this->a)->json('bundle_revision');

    expect([$r1, $r2, $r3, $r4])->toBe([3, 4, 5, 6]);
});

it('a revisão de uma sonda NÃO sobe quando muda a configuração de outra', function () {
    $b = Ingestao::sonda('sonda-b', 'dom-b');
    $antes = bundleDe($this->a)->json('bundle_revision');

    DB::table('assignments')->where('id', $b['assignment'])->update(['current_generation' => 9]);

    expect(bundleDe($this->a)->json('bundle_revision'))->toBe($antes);
});

it('mudar um check que a sonda NÃO executa não sobe a revisão dela', function () {
    $outro = Banco::check(['id' => (string) Str::uuid(), 'name' => 'alheio']);
    $antes = bundleDe($this->a)->json('bundle_revision');

    DB::table('checks')->where('id', $outro)->update(['name' => 'alheio-2']);

    expect(bundleDe($this->a)->json('bundle_revision'))->toBe($antes);
});

// ---- Jitter determinístico ----

it('o jitter é determinístico, no máximo 10% do intervalo, e muda com a geração', function () {
    $id = $this->a['assignment'];

    $a = BundleBuilder::jitterMs($id, 3, 30);
    expect(BundleBuilder::jitterMs($id, 3, 30))->toBe($a)
        ->and($a)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(3000);

    // Muda com a geração (e não só em um caso por acaso): entre 20 gerações aparece mais de um valor.
    $valores = array_unique(array_map(fn ($g) => BundleBuilder::jitterMs($id, $g, 30), range(1, 20)));
    expect(count($valores))->toBeGreaterThan(1);
    foreach ([1, 10, 30, 60, 300] as $intervalo) {
        for ($g = 1; $g <= 50; $g++) {
            expect(BundleBuilder::jitterMs($id, $g, $intervalo))->toBeLessThanOrEqual(intdiv($intervalo * 1000, 10));
        }
    }
});

it('o bundle entrega o MESMO jitter em duas chamadas', function () {
    $um = bundleDe($this->a)->json('assignments.0.schedule.jitter_offset_ms');
    $dois = bundleDe($this->a)->json('assignments.0.schedule.jitter_offset_ms');

    expect($um)->toBe($dois)->and($um)->toBe(BundleBuilder::jitterMs($this->a['assignment'], 3, 30));
});

// ---- O esquema recusa o que o bundle não pode ter ----

it('o banco recusa um check cujo timeout não cabe no intervalo', function () {
    expect(Banco::sqlstate(fn () => Banco::check(['id' => (string) Str::uuid(), 'interval_s' => 5, 'timeout_ms' => 5000])))->toBe(Banco::CHECK_VIOLADO);
});

it('o banco recusa uma espécie de check fora do glossário', function () {
    expect(Banco::sqlstate(fn () => Banco::check(['id' => (string) Str::uuid(), 'kind' => 'blackbox'])))->toBe(Banco::CHECK_VIOLADO);
});

it('leva a família IP e a geração de CADA assignment, sem misturar', function () {
    DB::table('assignments')->insert([
        'id' => '55555555-5555-4555-8555-555555555551', 'probe_id' => $this->a['probe'], 'check_id' => $this->check,
        'ip_family' => 'ipv6', 'current_generation' => 7,
    ]);

    $r = bundleDe($this->a)->assertOk();
    $porId = collect($r->json('assignments'))->keyBy('assignment_id');

    expect($porId[$this->a['assignment']]['ip_family'])->toBe('ipv4')->and($porId[$this->a['assignment']]['config_generation'])->toBe(3)
        ->and($porId['55555555-5555-4555-8555-555555555551']['ip_family'])->toBe('ipv6')
        ->and($porId['55555555-5555-4555-8555-555555555551']['config_generation'])->toBe(7);
});
