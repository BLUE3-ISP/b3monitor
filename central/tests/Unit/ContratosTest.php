<?php

declare(strict_types=1);

/*
| Estrutura dos contratos (contracts/). NÃO valida payload contra os schemas: isso exige uma
| biblioteca de JSON Schema, que é decisão do dono (ADR-014, proposta). Confere o que dá para
| conferir sem ela, e que quebra feio quando alguém mexe: JSON malformado, $ref para definição que
| não existe, fixture fora do manifesto, e fixture inválida sem dizer a regra que viola.
| O mesmo teste existe do lado Go (agents/probe/internal/contratos).
*/

function contratos(): string
{
    return dirname(base_path()).'/contracts';
}

function lerJson(string $arquivo): mixed
{
    return json_decode(file_get_contents($arquivo), true, 512, JSON_THROW_ON_ERROR);
}

it('tem cada schema como JSON bem formado, com $id e o dialeto 2020-12', function () {
    $schemas = glob(contratos().'/schemas/*.schema.json');
    expect($schemas)->not->toBeEmpty();

    foreach ($schemas as $arquivo) {
        $doc = lerJson($arquivo);
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $doc['$schema'] ?? null, basename($arquivo));
        $this->assertStringStartsWith('https://b3mon.example/contracts/schemas/', $doc['$id'] ?? '', basename($arquivo));
    }
});

it('resolve todo $ref entre schemas para uma definição que existe', function () {
    $docs = [];
    foreach (glob(contratos().'/schemas/*.schema.json') as $arquivo) {
        $docs[basename($arquivo)] = lerJson($arquivo);
    }

    $refs = [];
    $colher = function (mixed $no) use (&$colher, &$refs): void {
        if (! is_array($no)) {
            return;
        }
        foreach ($no as $chave => $valor) {
            if ($chave === '$ref' && is_string($valor)) {
                $refs[] = $valor;
            } else {
                $colher($valor);
            }
        }
    };

    foreach ($docs as $nome => $doc) {
        $refs = [];
        $colher($doc);
        foreach ($refs as $ref) {
            [$arquivo, $ponteiro] = array_pad(explode('#', $ref, 2), 2, '');
            $alvo = $arquivo === '' ? $nome : $arquivo;
            $this->assertArrayHasKey($alvo, $docs, "$nome → $ref");

            $no = $docs[$alvo];
            foreach (array_values(array_filter(explode('/', $ponteiro))) as $parte) {
                $this->assertArrayHasKey($parte, $no, "$nome → $ref");
                $no = $no[$parte];
            }
        }
    }
});

it('mantém o manifesto e as fixtures em correspondência, 1 para 1', function () {
    $manifesto = lerJson(contratos().'/fixtures/manifest.json');
    $listadas = array_column($manifesto['fixtures'], 'file');

    $emDisco = [];
    $base = contratos().'/fixtures';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $arq) {
        $rel = ltrim(substr($arq->getPathname(), strlen($base)), '/');
        if ($arq->isFile() && $arq->getExtension() === 'json' && $rel !== 'manifest.json') {
            $emDisco[] = $rel;
        }
    }

    sort($listadas);
    sort($emDisco);
    expect($listadas)->toBe($emDisco);
    expect(count($listadas))->toBe(count(array_unique($listadas)));
});

it('exige que toda fixture seja JSON válido, de um schema que existe, e que a inválida diga a regra', function () {
    $manifesto = lerJson(contratos().'/fixtures/manifest.json');

    foreach ($manifesto['fixtures'] as $f) {
        lerJson(contratos().'/fixtures/'.$f['file']);
        $this->assertFileExists(contratos().'/schemas/'.$f['schema'], $f['file']);

        // O nome do arquivo e o campo `valid` não podem discordar.
        $this->assertSame($f['valid'], str_starts_with(basename($f['file']), 'valido'), $f['file']);

        if (! $f['valid']) {
            $this->assertNotSame('', trim($f['regra'] ?? ''), $f['file'].' é inválida e não diz a regra que viola');
        }
    }

    $invalidas = array_filter($manifesto['fixtures'], fn ($f) => ! $f['valid']);
    expect(count($invalidas))->toBeGreaterThan(0);
});

it('usa só valores de enum do glossário nas fixtures válidas', function () {
    // O enum mora em common.schema.json ($defs), cópia do 14 §3. Aqui só se confere que a fixture
    // válida de resultado usa valores que o schema comum conhece.
    $comum = lerJson(contratos().'/schemas/common.schema.json')['$defs'];
    $fixture = lerJson(contratos().'/fixtures/result/valido-http-ok.json');

    expect($comum['outcome']['enum'])->toContain($fixture['outcome']);
    expect($comum['reason_code']['enum'])->toContain($fixture['reason_code']);
    expect($comum['ip_family']['enum'])->toContain($fixture['ip_family']);
    expect($comum['execution_kind']['enum'])->toContain($fixture['execution_kind']);
    expect($comum['clock_status']['enum'])->toContain($fixture['clock']['status']);
    expect($comum['path_binding_status']['enum'])->toContain($fixture['path_evidence']['status']);
    expect($fixture['executor']['name'])->toBe('native');
});
