<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Probes\AuthenticateProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * POST /api/v1/probe/results (06 §6 e §7; contrato em contracts/openapi/openapi.yaml).
 *
 * Falhas de TRANSPORTE vêm antes de qualquer item ser processado e usam um corpo de erro simples: 400 (corpo ou schema
 * inválido), 401 (middleware), 403 (a credencial não corresponde ao lote), 413 (limite de tamanho). Passado isso, a
 * resposta é 200 com um status por item, e é ela que a sonda usa para decidir o que apagar do spool: o cliente não
 * assume aceitação de item sem esta confirmação.
 */
final class ProbeResultsController
{
    public function __invoke(Request $request, BodyReader $corpo, BatchValidator $validador, IngestResults $ingestao): JsonResponse
    {
        try {
            $texto = $corpo->read($request);
            $lote = json_decode($texto, true, 32, JSON_THROW_ON_ERROR);
        } catch (BodyTooLarge $e) {
            return $this->erro(413, 'payload_too_large', $e->getMessage());
        } catch (BadBody $e) {
            return $this->erro(400, 'bad_request', $e->getMessage());
        } catch (JsonException) {
            return $this->erro(400, 'bad_request', 'O corpo não é um JSON válido.');
        }

        $erros = $validador->validateBatch($lote);
        if ($erros !== []) {
            return $this->erro(400, 'invalid_batch', 'O lote viola o contrato. Nenhum item foi processado.', $erros);
        }

        $negado = $this->autorizar((string) $request->attributes->get(AuthenticateProbe::ATRIBUTO), $lote['results']);
        if ($negado !== null) {
            return $this->erro(403, 'forbidden', $negado);
        }

        $itens = $ingestao->handle($lote['results'], now()->toDateTimeImmutable());

        return response()->json(['batch_id' => $lote['batch_id'], 'items' => $itens]);
    }

    /**
     * A credencial só vale para a SUA sonda e para os SEUS assignments, numa geração que existe (06 §11). Qualquer item
     * fora disso derruba o lote inteiro: um lote misturado é sinal de credencial mal usada, e nada é gravado.
     *
     * @param  list<array<string, mixed>>  $resultados
     */
    private function autorizar(string $sondaAutenticada, array $resultados): ?string
    {
        $assignments = array_values(array_unique(array_column($resultados, 'assignment_id')));
        $vigentes = DB::table('assignments')->whereIn('id', $assignments)->where('probe_id', $sondaAutenticada)
            ->pluck('current_generation', 'id');

        foreach ($resultados as $r) {
            if ($r['probe_id'] !== $sondaAutenticada) {
                return 'O lote traz resultados de outra sonda.';
            }
            $geracao = $vigentes[$r['assignment_id']] ?? null;
            if ($geracao === null) {
                return 'O lote traz um assignment que não é desta sonda.';
            }
            if ($r['config_generation'] > $geracao) {
                return 'O lote traz uma geração de configuração que ainda não existe.';
            }
        }

        return null;
    }

    /** @param  list<string>  $detalhes */
    private function erro(int $status, string $codigo, string $mensagem, array $detalhes = []): JsonResponse
    {
        $corpo = ['error' => $codigo, 'message' => $mensagem];
        if ($detalhes !== []) {
            $corpo['errors'] = $detalhes;
        }

        return response()->json($corpo, $status);
    }
}
