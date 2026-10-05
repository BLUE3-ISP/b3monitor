<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Banco;
use Tests\Support\Ingestao;

/*
| POST /api/v1/probe/results (B3). Cada teste fala com o endpoint de verdade, e confere o banco em seguida: o que a
| resposta diz tem de ser o que foi gravado.
*/

beforeEach(function () {
    $this->travelTo(Ingestao::AGORA);
    $this->sonda = Ingestao::sonda();
});

function enviar($teste, array $lote, ?string $token = null, array $cabecalhos = [])
{
    return $teste->postJson('/api/v1/probe/results', $lote, array_merge(
        $token === null ? [] : ['Authorization' => "Bearer $token"],
        $cabecalhos,
    ));
}

function contagens(): array
{
    return [
        'results' => DB::table('results')->count(),
        'receipts' => DB::table('result_receipts')->count(),
        'work' => DB::table('ingestion_work')->count(),
    ];
}

// ---------------------------------------------------------------------------------------------------------------
// Autenticação (06 §7): só a credencial de SONDA entra, e sem dizer por que foi recusada
// ---------------------------------------------------------------------------------------------------------------

it('recusa com 401 sem token, com token desconhecido, com credencial revogada e com sonda suspensa', function () {
    $lote = Ingestao::lote([Ingestao::resultado($this->sonda)]);

    enviar($this, $lote)->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
    enviar($this, $lote, 'b3m_p_desconhecido')->assertStatus(401);

    DB::table('probe_credentials')->where('probe_id', $this->sonda['probe'])->update(['revoked_at' => now()]);
    enviar($this, $lote, $this->sonda['token'])->assertStatus(401);

    DB::table('probe_credentials')->where('probe_id', $this->sonda['probe'])->update(['revoked_at' => null]);
    DB::table('probes')->where('id', $this->sonda['probe'])->update(['status' => 'suspended']);
    enviar($this, $lote, $this->sonda['token'])->assertStatus(401);

    expect(contagens())->toBe(['results' => 0, 'receipts' => 0, 'work' => 0]);
});

it('guarda só o hash do token, nunca o token', function () {
    $hash = DB::table('probe_credentials')->where('probe_id', $this->sonda['probe'])->value('token_hash');

    expect(trim($hash))->toBe(hash('sha256', $this->sonda['token']))
        ->and(DB::table('probe_credentials')->where('token_hash', $this->sonda['token'])->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------------------------------
// accepted, duplicate e a resposta perdida (06 §3)
// ---------------------------------------------------------------------------------------------------------------

it('aceita um resultado válido, e só então o dá como durável: recibo, resultado e trabalho juntos', function () {
    $resultado = Ingestao::resultado($this->sonda);

    $resposta = enviar($this, Ingestao::lote([$resultado], ['batch_id' => '66666666-6666-4666-8666-666666666666']), $this->sonda['token']);

    $resposta->assertOk()
        ->assertJsonPath('batch_id', '66666666-6666-4666-8666-666666666666')
        ->assertJsonPath('items.0.result_id', $resultado['result_id'])
        ->assertJsonPath('items.0.status', 'accepted')
        ->assertJsonPath('items.0.durable', true);

    expect(contagens())->toBe(['results' => 1, 'receipts' => 1, 'work' => 1]);
    expect(DB::table('ingestion_work')->value('status'))->toBe('pending');
    expect(DB::table('results')->where('result_id', $resultado['result_id'])->value('outcome'))->toBe('pass');
});

it('o servidor decide received_at e retain_until: o relógio da sonda não manda', function () {
    $resultado = Ingestao::resultado($this->sonda);
    enviar($this, Ingestao::lote([$resultado]), $this->sonda['token'])->assertOk();

    $recibo = DB::table('result_receipts')->first();
    expect(Carbon\CarbonImmutable::parse($recibo->received_at)->toIso8601ZuluString())->toBe('2026-09-28T15:00:10Z');
    // O ledger vive 21 dias, e o bruto 14: o ledger sobrevive ao bruto (C-02).
    expect(Carbon\CarbonImmutable::parse($recibo->retain_until)->toIso8601ZuluString())->toBe('2026-10-19T15:00:10Z');
});

it('06 §3: a resposta se perdeu depois do commit, a sonda reenvia, e o reenvio é duplicate sem segundo trabalho', function () {
    $lote = Ingestao::lote([Ingestao::resultado($this->sonda)]);

    // A primeira tentativa foi processada e commitada, mas a sonda nunca recebeu a resposta.
    enviar($this, $lote, $this->sonda['token'])->assertOk()->assertJsonPath('items.0.status', 'accepted');
    expect(contagens())->toBe(['results' => 1, 'receipts' => 1, 'work' => 1]);

    // O reenvio do MESMO lote.
    $reenvio = enviar($this, $lote, $this->sonda['token']);

    $reenvio->assertOk()
        ->assertJsonPath('items.0.status', 'duplicate')
        ->assertJsonPath('items.0.durable', true);
    expect(contagens())->toBe(['results' => 1, 'receipts' => 1, 'work' => 1]);   // nenhum segundo trabalho
    expect(DB::table('audit_events')->count())->toBe(0);                          // e nenhuma auditoria: reenvio não é anomalia
});

it('trata o reenvio como o mesmo resultado mesmo com os campos serializados em outra ordem', function () {
    $resultado = Ingestao::resultado($this->sonda);
    enviar($this, Ingestao::lote([$resultado]), $this->sonda['token'])->assertOk();

    $embaralhado = array_reverse($resultado, true);
    ksort($embaralhado);
    $embaralhado = array_reverse($embaralhado, true);

    enviar($this, Ingestao::lote([$embaralhado]), $this->sonda['token'])
        ->assertOk()->assertJsonPath('items.0.status', 'duplicate');
    expect(contagens()['results'])->toBe(1);
});

it('responde um item por resultado, na ordem recebida, misturando destinos', function () {
    $a = Ingestao::resultado($this->sonda);
    $b = Ingestao::resultado($this->sonda, ['scheduled_at' => '2026-09-28T15:00:30Z', 'started_at' => '2026-09-28T15:00:32Z',
        'finished_at' => '2026-09-28T15:00:33Z']);
    enviar($this, Ingestao::lote([$a]), $this->sonda['token'])->assertOk();

    // Lote com o já visto (a) e um novo (b).
    $resposta = enviar($this, Ingestao::lote([$a, $b]), $this->sonda['token']);

    $resposta->assertOk()
        ->assertJsonPath('items.0.result_id', $a['result_id'])->assertJsonPath('items.0.status', 'duplicate')
        ->assertJsonPath('items.1.result_id', $b['result_id'])->assertJsonPath('items.1.status', 'accepted');
    expect(contagens()['results'])->toBe(2);
});

// ---------------------------------------------------------------------------------------------------------------
// conflict (06 §3): a observação original nunca é sobrescrita, e a divergência é auditada
// ---------------------------------------------------------------------------------------------------------------

it('mesmo result_id com outro conteúdo é conflict, não sobrescreve o original e gera auditoria', function () {
    $original = Ingestao::resultado($this->sonda);
    enviar($this, Ingestao::lote([$original]), $this->sonda['token'])->assertOk();

    $adulterado = [...$original, 'outcome' => 'fail', 'reason_code' => 'target_timeout'];
    $resposta = enviar($this, Ingestao::lote([$adulterado]), $this->sonda['token']);

    $resposta->assertOk()->assertJsonPath('items.0.status', 'conflict')->assertJsonPath('items.0.durable', false);
    expect(DB::table('results')->where('result_id', $original['result_id'])->value('outcome'))->toBe('pass');   // intacto
    expect(contagens())->toBe(['results' => 1, 'receipts' => 1, 'work' => 1]);

    $auditoria = DB::table('audit_events')->first();
    expect($auditoria->action)->toBe('ingest.conflict')
        ->and($auditoria->actor_type)->toBe('probe')
        ->and($auditoria->resource_id)->toBe($original['result_id']);
});

it('mesma chave natural com outro result_id também é conflict', function () {
    enviar($this, Ingestao::lote([Ingestao::resultado($this->sonda)]), $this->sonda['token'])->assertOk();

    // Outro result_id para o MESMO slot, da mesma sonda, assignment e geração.
    $outro = Ingestao::resultado($this->sonda, ['outcome' => 'fail', 'reason_code' => 'target_timeout']);
    enviar($this, Ingestao::lote([$outro]), $this->sonda['token'])
        ->assertOk()->assertJsonPath('items.0.status', 'conflict');

    expect(contagens()['results'])->toBe(1);
    expect(DB::table('audit_events')->where('action', 'ingest.conflict')->count())->toBe(1);
});

// ---------------------------------------------------------------------------------------------------------------
// rejected_terminal e retryable
// ---------------------------------------------------------------------------------------------------------------

it('rejeita de forma explícita um resultado mais velho que a janela de aceitação, sem gravar nada', function () {
    $antigo = Ingestao::resultado($this->sonda, [
        'scheduled_at' => '2026-09-20T15:00:00Z', 'started_at' => '2026-09-20T15:00:02Z', 'finished_at' => '2026-09-20T15:00:03Z',
    ]);

    enviar($this, Ingestao::lote([$antigo]), $this->sonda['token'])
        ->assertOk()->assertJsonPath('items.0.status', 'rejected_terminal')->assertJsonPath('items.0.durable', false);

    expect(contagens())->toBe(['results' => 0, 'receipts' => 0, 'work' => 0]);
});

it('aceita um resultado exatamente no limite da janela de aceitação', function () {
    // 7 dias antes de AGORA.
    $noLimite = Ingestao::resultado($this->sonda, [
        'scheduled_at' => '2026-09-21T15:00:10Z', 'started_at' => '2026-09-21T15:00:10Z', 'finished_at' => '2026-09-21T15:00:11Z',
    ]);
    Banco::garantirParticao('2026-09-21');

    enviar($this, Ingestao::lote([$noLimite]), $this->sonda['token'])->assertOk()->assertJsonPath('items.0.status', 'accepted');
});

it('sem partição para o dia, o item é retryable e a transação INTEIRA volta: nenhum recibo órfão', function () {
    $semParticao = Ingestao::resultado($this->sonda, [
        'scheduled_at' => '2026-09-28T15:00:30Z', 'started_at' => '2026-09-28T15:00:32Z', 'finished_at' => '2026-09-28T15:00:33Z',
    ]);
    // Um dia que o relógio de teste ainda aceita mas que não tem partição: aceitação de 7 dias para trás de AGORA.
    $semParticao['started_at'] = '2026-09-23T10:00:00Z';
    $semParticao['finished_at'] = '2026-09-23T10:00:01Z';
    $semParticao['scheduled_at'] = '2026-09-23T10:00:00Z';

    $resposta = enviar($this, Ingestao::lote([$semParticao]), $this->sonda['token']);

    $resposta->assertOk()->assertJsonPath('items.0.status', 'retryable')->assertJsonPath('items.0.durable', false);
    expect(contagens())->toBe(['results' => 0, 'receipts' => 0, 'work' => 0]);   // o recibo não ficou para trás
});

it('um item que falha não impede os outros do mesmo lote, e o que commitou fica', function () {
    $bom = Ingestao::resultado($this->sonda);
    $semParticao = Ingestao::resultado($this->sonda, ['scheduled_at' => '2026-09-23T10:00:00Z',
        'started_at' => '2026-09-23T10:00:00Z', 'finished_at' => '2026-09-23T10:00:01Z']);

    $resposta = enviar($this, Ingestao::lote([$semParticao, $bom]), $this->sonda['token']);

    $resposta->assertOk()
        ->assertJsonPath('items.0.status', 'retryable')
        ->assertJsonPath('items.1.status', 'accepted');
    expect(contagens())->toBe(['results' => 1, 'receipts' => 1, 'work' => 1]);
});

it('aceita o modo replay com a sessão, e o trata como qualquer lote durável', function () {
    $lote = Ingestao::lote([Ingestao::resultado($this->sonda)], ['mode' => 'replay', 'replay_session_id' => (string) Str::uuid()]);

    enviar($this, $lote, $this->sonda['token'])->assertOk()->assertJsonPath('items.0.status', 'accepted');
});

// ---------------------------------------------------------------------------------------------------------------
// 403: a credencial só vale para a SUA sonda e os SEUS assignments (06 §11)
// ---------------------------------------------------------------------------------------------------------------

it('recusa com 403 o lote que traz resultado de outra sonda, e não grava nada', function () {
    $outra = Ingestao::sonda('sonda-b', 'dom-b');
    $lote = Ingestao::lote([Ingestao::resultado($this->sonda), Ingestao::resultado($outra)]);

    enviar($this, $lote, $this->sonda['token'])->assertStatus(403)->assertJsonPath('error', 'forbidden');

    expect(contagens())->toBe(['results' => 0, 'receipts' => 0, 'work' => 0]);   // o lote inteiro, não só o item
});

it('recusa com 403 um assignment que não é da sonda e uma geração que ainda não existe', function () {
    $outra = Ingestao::sonda('sonda-b', 'dom-b');

    // O assignment é de outra sonda.
    $alheio = Ingestao::resultado($this->sonda, ['assignment_id' => $outra['assignment']]);
    enviar($this, Ingestao::lote([$alheio]), $this->sonda['token'])->assertStatus(403);

    // O assignment é desta sonda, mas a geração 4 ainda não existe (a vigente é a 3).
    $futura = Ingestao::resultado($this->sonda, ['config_generation' => 4]);
    enviar($this, Ingestao::lote([$futura]), $this->sonda['token'])->assertStatus(403);

    // E um assignment que nem existe.
    $fantasma = Ingestao::resultado($this->sonda, ['assignment_id' => (string) Str::uuid()]);
    enviar($this, Ingestao::lote([$fantasma]), $this->sonda['token'])->assertStatus(403);

    expect(contagens())->toBe(['results' => 0, 'receipts' => 0, 'work' => 0]);
});

it('aceita uma geração antiga: ela serve ao histórico daquele período', function () {
    $antiga = Ingestao::resultado($this->sonda, ['config_generation' => 2]);

    enviar($this, Ingestao::lote([$antiga]), $this->sonda['token'])->assertOk()->assertJsonPath('items.0.status', 'accepted');
});

// ---------------------------------------------------------------------------------------------------------------
// 400: corpo e contrato
// ---------------------------------------------------------------------------------------------------------------

it('recusa com 400 um JSON malformado e um lote que viola o contrato, sem processar item nenhum', function () {
    $this->call('POST', '/api/v1/probe/results', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$this->sonda['token'], 'CONTENT_TYPE' => 'application/json',
    ], '{"batch_id": ')->assertStatus(400)->assertJsonPath('error', 'bad_request');

    $ruim = Ingestao::resultado($this->sonda, ['duration_ms' => -1]);
    $resposta = enviar($this, Ingestao::lote([Ingestao::resultado($this->sonda), $ruim]), $this->sonda['token']);

    $resposta->assertStatus(400)->assertJsonPath('error', 'invalid_batch')->assertJsonStructure(['errors']);
    expect(contagens())->toBe(['results' => 0, 'receipts' => 0, 'work' => 0]);   // o item bom também não entrou
});

it('recusa o canário e o lote vazio, e não repete o dado recebido no erro', function () {
    $canario = Ingestao::resultado($this->sonda, ['execution_kind' => 'canary', 'scheduled_at' => null]);
    enviar($this, Ingestao::lote([$canario]), $this->sonda['token'])->assertStatus(400);
    enviar($this, Ingestao::lote([]), $this->sonda['token'])->assertStatus(400);

    $resposta = enviar($this, Ingestao::lote([Ingestao::resultado($this->sonda, ['policy_revision' => 'SEGREDO-DA-SONDA',
        'reason_code' => 'valor-secreto'])]), $this->sonda['token']);
    $resposta->assertStatus(400);
    expect($resposta->getContent())->not->toContain('SEGREDO-DA-SONDA')->not->toContain('valor-secreto');
});

it('a partir das fixtures do contrato: a válida entra e a inválida é recusada com 400, de ponta a ponta', function () {
    $m = json_decode(file_get_contents(dirname(__DIR__, 4).'/contracts/fixtures/manifest.json'), true);

    foreach ($m['fixtures'] as $i => $f) {
        if ($f['schema'] !== 'result.schema.json') {
            continue;
        }
        $dado = json_decode(file_get_contents(dirname(__DIR__, 4).'/contracts/fixtures/'.$f['file']), true);
        // Cada fixture entra com ids próprios, sem alterar o que a torna válida ou inválida.
        $dado['result_id'] = (string) Str::uuid();
        $dado['probe_id'] = $this->sonda['probe'];
        $dado['assignment_id'] = $this->sonda['assignment'];
        $dado['scheduled_at'] = $dado['scheduled_at'] === null ? null : sprintf('2026-09-28T15:%02d:00Z', $i);

        $resposta = enviar($this, Ingestao::lote([$dado]), $this->sonda['token']);

        $f['valid']
            ? $resposta->assertOk()
            : $resposta->assertStatus(400);
    }
});
