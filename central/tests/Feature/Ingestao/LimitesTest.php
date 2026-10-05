<?php

declare(strict_types=1);

use App\Ingestion\BodyReader;
use Illuminate\Support\Facades\DB;
use Tests\Support\Ingestao;

/*
| Limites de TRANSPORTE da ingestão (06 §6; C-19): 4 MiB na requisição, 16 MiB depois de descomprimir, de 1 a 500 itens.
| Não cabem em schema, então o servidor os impõe, e estes testes provam que impõe.
*/

beforeEach(function () {
    $this->travelTo(Ingestao::AGORA);
    $this->sonda = Ingestao::sonda();
});

function postarBruto($teste, string $corpo, array $servidor = [])
{
    return $teste->call('POST', '/api/v1/probe/results', [], [], [], array_merge([
        'HTTP_AUTHORIZATION' => 'Bearer '.$teste->sonda['token'], 'CONTENT_TYPE' => 'application/json',
    ], $servidor), $corpo);
}

function loteDe(array $sonda, int $n): array
{
    $resultados = [];
    $inicio = new DateTimeImmutable('2026-09-28T00:00:00Z');
    for ($i = 0; $i < $n; $i++) {
        $slot = $inicio->modify('+'.($i * 30).' seconds')->format('Y-m-d\TH:i:s\Z');
        $resultados[] = Ingestao::resultado($sonda, ['scheduled_at' => $slot, 'started_at' => $slot, 'finished_at' => $slot]);
    }

    return Ingestao::lote($resultados);
}

it('aceita um lote de 500 resultados e recusa com 400 um de 501', function () {
    $quinhentos = loteDe($this->sonda, 500);
    $resposta = postarBruto($this, json_encode($quinhentos));

    $resposta->assertOk();
    expect(collect($resposta->json('items'))->pluck('status')->unique()->all())->toBe(['accepted']);
    expect(DB::table('results')->count())->toBe(500)->and(DB::table('ingestion_work')->count())->toBe(500);

    postarBruto($this, json_encode(loteDe($this->sonda, 501)))->assertStatus(400)->assertJsonPath('error', 'invalid_batch');
    expect(DB::table('results')->count())->toBe(500);   // o de 501 não gravou nem o primeiro
});

it('recusa com 413 um corpo acima de 4 MiB, sem sequer tentar interpretá-lo', function () {
    $acima = str_repeat('a', BodyReader::MAX_BODY_BYTES + 1);

    postarBruto($this, $acima)->assertStatus(413)->assertJsonPath('error', 'payload_too_large');
    expect(DB::table('results')->count())->toBe(0);
});

it('aceita um lote gzip válido', function () {
    $lote = Ingestao::lote([Ingestao::resultado($this->sonda)]);

    postarBruto($this, gzencode(json_encode($lote)), ['HTTP_CONTENT_ENCODING' => 'gzip'])
        ->assertOk()->assertJsonPath('items.0.status', 'accepted');
});

it('C-19: uma bomba de descompressão é recusada com 413, mesmo pequena no fio', function () {
    // 17 MiB de zeros comprimem para dezenas de KiB: o tamanho COMPRIMIDO não previne a expansão.
    $bomba = gzencode(str_repeat("\0", BodyReader::MAX_INFLATED_BYTES + 1024 * 1024), 9);
    expect(strlen($bomba))->toBeLessThan(BodyReader::MAX_BODY_BYTES)->and(strlen($bomba))->toBeLessThan(100 * 1024);

    postarBruto($this, $bomba, ['HTTP_CONTENT_ENCODING' => 'gzip'])
        ->assertStatus(413)->assertJsonPath('error', 'payload_too_large');
});

it('aceita um gzip que expande exatamente até o limite, e recusa um byte a mais', function () {
    // Um JSON válido preenchido por um texto longo até o tamanho desejado não passa do schema, então se confere o
    // LEITOR: ele devolve o texto expandido inteiro no limite e lança um byte depois.
    $leitor = new BodyReader;
    $noLimite = str_repeat('x', BodyReader::MAX_INFLATED_BYTES);
    $req = fn (string $corpo) => Illuminate\Http\Request::create('/', 'POST', [], [], [], ['HTTP_CONTENT_ENCODING' => 'gzip'], gzencode($corpo, 9));

    expect(strlen($leitor->read($req($noLimite))))->toBe(BodyReader::MAX_INFLATED_BYTES);
    expect(fn () => $leitor->read($req($noLimite.'x')))->toThrow(App\Ingestion\BodyTooLarge::class);
});

it('recusa com 400 um gzip corrompido, um truncado e uma codificação desconhecida', function () {
    $lote = json_encode(Ingestao::lote([Ingestao::resultado($this->sonda)]));

    postarBruto($this, 'isto não é gzip', ['HTTP_CONTENT_ENCODING' => 'gzip'])->assertStatus(400);
    postarBruto($this, substr(gzencode($lote), 0, -8), ['HTTP_CONTENT_ENCODING' => 'gzip'])->assertStatus(400);   // sem o rodapé
    postarBruto($this, $lote, ['HTTP_CONTENT_ENCODING' => 'br'])->assertStatus(400)->assertJsonPath('error', 'bad_request');

    expect(DB::table('results')->count())->toBe(0);
});

it('não confia no Content-Encoding para pular o limite: gzip declarado e corpo cru de verdade', function () {
    postarBruto($this, str_repeat('a', 1000), ['HTTP_CONTENT_ENCODING' => 'gzip'])->assertStatus(400);
});

it('recusa um gzip válido seguido de bytes que não são dele', function () {
    $lote = json_encode(Ingestao::lote([Ingestao::resultado($this->sonda)]));

    postarBruto($this, gzencode($lote).'LIXO', ['HTTP_CONTENT_ENCODING' => 'gzip'])->assertStatus(400);
    expect(DB::table('results')->count())->toBe(0);
});
