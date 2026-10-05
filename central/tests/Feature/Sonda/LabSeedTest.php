<?php

declare(strict_types=1);

use App\Probes\ProbeEnrollment;
use Illuminate\Support\Facades\DB;

/*
| B8: o semeador do laboratório. Cria credenciais de sonda, então as travas importam tanto quanto o que ele cria.
*/

beforeEach(function () {
    $this->out = sys_get_temp_dir().'/b3mon-lab-'.getmypid().'-'.bin2hex(random_bytes(3));
    putenv('B3MON_LAB=1');
    config(['database.connections.pgsql.database' => 'b3mon_lab']);   // só o NOME que a trava lê; a conexão segue a do teste
});

afterEach(function () {
    putenv('B3MON_LAB');
    if (is_dir($this->out)) {
        foreach (glob($this->out.'/*/*') as $f) {
            @unlink($f);
        }
        foreach (glob($this->out.'/*') as $d) {
            @rmdir($d);
        }
        @rmdir($this->out);
    }
});

it('RECUSA fora do laboratório: sem B3MON_LAB=1', function () {
    putenv('B3MON_LAB');

    $this->artisan('b3mon:lab-seed', ['--out' => $this->out])->expectsOutputToContain('recusado')->assertFailed();

    expect(DB::table('probes')->count())->toBe(0);
});

it('RECUSA fora do laboratório: banco que não termina em _lab', function () {
    config(['database.connections.pgsql.database' => 'b3mon_dev']);

    $this->artisan('b3mon:lab-seed', ['--out' => $this->out])->expectsOutputToContain('recusado')->assertFailed();

    expect(DB::table('probes')->count())->toBe(0)->and(is_dir($this->out))->toBeFalse();
});

it('exige --out', function () {
    $this->artisan('b3mon:lab-seed')->assertExitCode(2);
});

it('cria duas sondas de domínios DIFERENTES, o check e um assignment de cada uma', function () {
    $this->artisan('b3mon:lab-seed', ['--out' => $this->out])->assertSuccessful();

    $s = DB::table('probes')->orderBy('name')->get(['name', 'observation_domain', 'status']);
    expect($s->pluck('name')->all())->toBe(['lab-a', 'lab-b'])
        ->and($s->pluck('observation_domain')->all())->toBe(['lab-dom-a', 'lab-dom-b'])
        ->and($s->pluck('status')->unique()->all())->toBe(['active']);
    $c = DB::table('checks')->get();
    expect($c)->toHaveCount(1)->and($c[0]->kind)->toBe('http')->and($c[0]->interval_s)->toBe(5)
        ->and(json_decode($c[0]->target, true)['url'])->toBe('http://127.0.0.1:18080/health');
    expect(DB::table('assignments')->count())->toBe(2)
        ->and(DB::table('assignments')->distinct()->count('probe_id'))->toBe(2);
});

it('o token vai para arquivo 0600 e o banco guarda só o SHA-256 dele', function () {
    $this->artisan('b3mon:lab-seed', ['--out' => $this->out])->assertSuccessful();

    foreach (['lab-a', 'lab-b'] as $nome) {
        $arq = "{$this->out}/{$nome}/token";
        $token = trim(file_get_contents($arq));
        expect(decoct(fileperms($arq) & 0777))->toBe('600')
            ->and($token)->toStartWith(ProbeEnrollment::PREFIXO)
            ->and(DB::table('probe_credentials')->where('token_hash', ProbeEnrollment::hash($token))->exists())->toBeTrue()
            // o token em texto NÃO está em coluna nenhuma
            ->and(json_encode(DB::table('probe_credentials')->get()))->not->toContain($token);
        expect(trim(file_get_contents("{$this->out}/{$nome}/probe_id")))->toBe(DB::table('probes')->where('name', $nome)->value('id'));
    }
});

it('é IDEMPOTENTE: rodar de novo não cria nada nem troca o token que está em disco', function () {
    $this->artisan('b3mon:lab-seed', ['--out' => $this->out])->assertSuccessful();
    $antes = [file_get_contents("{$this->out}/lab-a/token"), file_get_contents("{$this->out}/lab-b/token")];
    $revisao = DB::table('probes')->orderBy('name')->pluck('bundle_revision')->all();

    $this->artisan('b3mon:lab-seed', ['--out' => $this->out])->assertSuccessful();

    expect(DB::table('probes')->count())->toBe(2)->and(DB::table('checks')->count())->toBe(1)->and(DB::table('assignments')->count())->toBe(2)
        ->and(DB::table('probe_credentials')->count())->toBe(2)
        ->and([file_get_contents("{$this->out}/lab-a/token"), file_get_contents("{$this->out}/lab-b/token")])->toBe($antes)
        // E o bundle não mudou de revisão por um semeio que não mudou nada.
        ->and(DB::table('probes')->orderBy('name')->pluck('bundle_revision')->all())->toBe($revisao);
});

it('se o arquivo do token sumiu, emite uma credencial NOVA e REVOGA a antiga', function () {
    $this->artisan('b3mon:lab-seed', ['--out' => $this->out])->assertSuccessful();
    $velho = trim(file_get_contents("{$this->out}/lab-a/token"));
    unlink("{$this->out}/lab-a/token");

    $this->artisan('b3mon:lab-seed', ['--out' => $this->out])->expectsOutputToContain('NOVA')->assertSuccessful();

    $novo = trim(file_get_contents("{$this->out}/lab-a/token"));
    expect($novo)->not->toBe($velho);
    $this->getJson('/api/v1/probe/config', ['Authorization' => 'Bearer '.$velho])->assertStatus(401);
    $this->getJson('/api/v1/probe/config', ['Authorization' => 'Bearer '.$novo])->assertOk();
});

it('cada sonda semeada recebe o bundle com o MESMO check e só o seu assignment', function () {
    $this->artisan('b3mon:lab-seed', ['--out' => $this->out])->assertSuccessful();

    $a = $this->getJson('/api/v1/probe/config', ['Authorization' => 'Bearer '.trim(file_get_contents("{$this->out}/lab-a/token"))])->assertOk();
    $b = $this->getJson('/api/v1/probe/config', ['Authorization' => 'Bearer '.trim(file_get_contents("{$this->out}/lab-b/token"))])->assertOk();

    expect($a->json('assignments'))->toHaveCount(1)->and($b->json('assignments'))->toHaveCount(1)
        ->and($a->json('assignments.0.check_id'))->toBe($b->json('assignments.0.check_id'))
        ->and($a->json('assignments.0.assignment_id'))->not->toBe($b->json('assignments.0.assignment_id'))
        ->and($a->json('assignments.0.schedule.interval_s'))->toBe(5)
        // O timeout cabe no intervalo e a tolerância é de 20% dele (limitada a 1 s).
        ->and($a->json('assignments.0.schedule.timeout_ms'))->toBeLessThan(5000)
        ->and($a->json('assignments.0.schedule.start_tolerance_ms'))->toBe(1000);
});

it('respeita --interval e --target-url', function () {
    $this->artisan('b3mon:lab-seed', ['--out' => $this->out, '--interval' => 10, '--target-url' => 'http://127.0.0.1:18080/outro'])->assertSuccessful();

    $c = DB::table('checks')->first();
    expect($c->interval_s)->toBe(10)->and(json_decode($c->target, true)['url'])->toBe('http://127.0.0.1:18080/outro')
        ->and($c->start_tolerance_ms)->toBe(1000)->and($c->timeout_ms)->toBe(2000);
});

it('RECUSA um banco cujo nome só PARECE de laboratório (termina em "lab" sem o "_")', function () {
    config(['database.connections.pgsql.database' => 'b3mon_slab']);

    $this->artisan('b3mon:lab-seed', ['--out' => $this->out])->expectsOutputToContain('recusado')->assertFailed();

    expect(DB::table('probes')->count())->toBe(0);
});

it('o diretório de cada sonda é 0700 (o token não pode ser listado por outro usuário)', function () {
    $this->artisan('b3mon:lab-seed', ['--out' => $this->out])->assertSuccessful();

    foreach (['lab-a', 'lab-b'] as $nome) {
        expect(decoct(fileperms("{$this->out}/{$nome}") & 0777))->toBe('700');
    }
});
