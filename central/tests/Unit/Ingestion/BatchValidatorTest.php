<?php

declare(strict_types=1);

use App\Ingestion\BatchValidator;

/*
| O validador próprio é uma SEGUNDA implementação das regras do contrato (ADR-014 em aberto), e este teste é o que o
| impede de divergir dele em silêncio: o manifesto de `contracts/fixtures` é o oráculo. Cada `valido-*` tem de passar
| e cada `invalido-*` tem de ser recusada, e quem mudar um schema sem mudar o validador quebra aqui.
*/

function contratosDir(): string
{
    return dirname(__DIR__, 4).'/contracts';
}

dataset('fixtures de ingestão', function () {
    $m = json_decode(file_get_contents(contratosDir().'/fixtures/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($m['fixtures'] as $f) {
        // Só os schemas que a ingestão RECEBE: o resultado e o lote. Resposta, bundle e heartbeat são de saída ou de outros endpoints.
        if (in_array($f['schema'], ['result.schema.json', 'ingest-batch.schema.json'], true)) {
            yield $f['file'] => [$f];
        }
    }
});

it('concorda com o manifesto do contrato em cada fixture', function (array $f) {
    $dado = json_decode(file_get_contents(contratosDir().'/fixtures/'.$f['file']), true, 512, JSON_THROW_ON_ERROR);
    $validador = new BatchValidator;

    $erros = $f['schema'] === 'result.schema.json' ? $validador->validateResult($dado) : $validador->validateBatch($dado);

    if ($f['valid']) {
        expect($erros)->toBe([], $f['file'].' é válida e foi recusada: '.implode(' | ', $erros));
    } else {
        expect($erros)->not->toBe([], $f['file'].' é inválida ('.$f['regra'].') e foi aceita');
    }
})->with('fixtures de ingestão');

it('recusa um lote com 501 resultados e aceita um com 500, como o contrato', function () {
    $um = json_decode(file_get_contents(contratosDir().'/fixtures/result/valido-http-ok.json'), true);
    $lote = fn (int $n) => ['batch_id' => '66666666-6666-4666-8666-666666666666', 'mode' => 'live', 'replay_session_id' => null,
        'results' => array_fill(0, $n, $um)];

    expect((new BatchValidator)->validateBatch($lote(500)))->toBe([])
        ->and((new BatchValidator)->validateBatch($lote(501)))->not->toBe([])
        ->and((new BatchValidator)->validateBatch($lote(0)))->not->toBe([]);
});

it('recusa o que não é objeto, e um resultado que não é objeto', function () {
    $v = new BatchValidator;

    expect($v->validateBatch([]))->not->toBe([])
        ->and($v->validateBatch('texto'))->not->toBe([])
        ->and($v->validateBatch(null))->not->toBe([])
        ->and($v->validateResult([1, 2, 3]))->not->toBe([]);
});

it('não devolve o valor recebido nas mensagens de erro: só o caminho e a regra', function () {
    $dado = json_decode(file_get_contents(contratosDir().'/fixtures/result/valido-http-ok.json'), true);
    $dado['policy_revision'] = 'SEGREDO-NA-SONDA';
    $dado['reason_code'] = 'valor-secreto-fora-do-glossario';

    $erros = (new BatchValidator)->validateResult($dado);

    expect($erros)->not->toBe([]);
    expect(implode(' ', $erros))->not->toContain('valor-secreto')->not->toContain('SEGREDO');
});

it('limita a quantidade de erros devolvidos', function () {
    $erros = (new BatchValidator)->validateResult(array_fill_keys(range(1, 100), 'x'));

    expect(count($erros))->toBeLessThanOrEqual(20);
});

it('exige inteiros de verdade: texto e decimal não passam por inteiro', function () {
    $base = json_decode(file_get_contents(contratosDir().'/fixtures/result/valido-http-ok.json'), true);

    foreach (['duration_ms' => '120', 'config_generation' => 3.0, 'http_status' => true] as $campo => $valor) {
        $copia = $base;
        $copia[$campo] = $valor;
        expect((new BatchValidator)->validateResult($copia))->not->toBe([], "$campo=".var_export($valor, true));
    }
});

it('recusa datas que não existem, mesmo com o formato certo', function () {
    $base = json_decode(file_get_contents(contratosDir().'/fixtures/result/valido-http-ok.json'), true);

    foreach (['2026-02-30T10:00:00Z', '2026-13-01T10:00:00Z', '2026-09-28T25:00:00Z', '2026-09-28T10:61:00Z'] as $data) {
        $copia = $base;
        $copia['started_at'] = $data;
        expect((new BatchValidator)->validateResult($copia))->not->toBe([], $data);
    }
});
