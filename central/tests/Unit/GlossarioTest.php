<?php

declare(strict_types=1);

use App\Ops\Glossario;

/*
| O banco e o contrato não podem divergir em silêncio. `contracts/schemas/common.schema.json` espelha os enums do
| glossário 14 §3 e §4, e `Glossario` é de onde as migrações tiram os valores dos CHECK. Se alguém acrescentar um
| valor em um e esquecer do outro, este teste quebra.
*/

function defsDoContrato(): array
{
    $arquivo = dirname(__DIR__, 3).'/contracts/schemas/common.schema.json';

    return json_decode(file_get_contents($arquivo), true, 512, JSON_THROW_ON_ERROR)['$defs'];
}

it('mantém os enums do banco idênticos aos do contrato', function (string $metodo, string $definicao) {
    $doContrato = defsDoContrato()[$definicao]['enum'];
    $doBanco = Glossario::$metodo();

    sort($doContrato);
    sort($doBanco);
    expect($doBanco)->toBe($doContrato);
})->with([
    'família IP' => ['ipFamily', 'ip_family'],
    'tipo de execução' => ['executionKind', 'execution_kind'],
    'outcome' => ['outcome', 'outcome'],
    'reason_code' => ['reasonCode', 'reason_code'],
    'integridade' => ['integrity', 'integrity'],
    'estado do relógio' => ['clockStatus', 'clock_status'],
    'espécie de verificação' => ['checkKind', 'check_kind'],
]);

it('monta a lista de um CHECK com aspas escapadas', function () {
    expect(Glossario::sql(['a', 'b']))->toBe("'a','b'")
        ->and(Glossario::sql(["o'brien"]))->toBe("'o''brien'");
});
