<?php

declare(strict_types=1);

namespace App\Probes;

use App\Ingestion\HeartbeatValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * POST /api/v1/probe/heartbeat (04 §8): o autodiagnóstico da sonda. Guarda o ESTADO ATUAL (`probe_status`, um por sonda); o
 * histórico de saúde da sonda é do F0-07. Heartbeat é capacidade e saúde, nunca resultado de alvo, e não vira incidente aqui.
 *
 * - 400: corpo que não é JSON ou que viola o contrato (a lista diz o caminho e a regra, nunca o valor);
 * - 403: o `probe_id` do corpo não é o da credencial. Uma sonda não relata a saúde de outra;
 * - 413: acima de 64 KiB (um heartbeat tem ~1 KB).
 */
final class ProbeHeartbeatController
{
    private const MAX_BYTES = 65536;

    public function __invoke(Request $request, HeartbeatValidator $validador): JsonResponse|Response
    {
        $texto = $request->getContent();
        if (strlen($texto) > self::MAX_BYTES) {
            return $this->erro(413, 'payload_too_large', 'O heartbeat passa de 64 KiB.');
        }
        try {
            $h = json_decode($texto, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->erro(400, 'bad_request', 'O corpo não é um JSON válido.');
        }

        $erros = $validador->validate($h);
        if ($erros !== []) {
            return $this->erro(400, 'invalid_heartbeat', 'O heartbeat viola o contrato.', $erros);
        }

        $sonda = (string) $request->attributes->get(AuthenticateProbe::ATRIBUTO);
        if (strtolower($h['probe_id']) !== strtolower($sonda)) {
            return $this->erro(403, 'forbidden', 'O probe_id do heartbeat não é o da credencial.');
        }

        $recebido = now()->utc();
        $enviado = new \DateTimeImmutable($h['sent_at']);
        // A diferença entre o relógio da sonda e o da central, em ms: o dado que 04 §8 pede e que o painel mostra. Pode ser negativa.
        $desvioMs = (int) round(($recebido->getTimestamp() - $enviado->getTimestamp()) * 1000
            + ((int) $recebido->format('v') - (int) $enviado->format('v')));

        DB::statement(<<<'SQL'
            INSERT INTO probe_status (probe_id, received_at, sent_at, clock_skew_ms, heartbeats, bundle_revision, payload)
            VALUES (?, ?::timestamptz, ?::timestamptz, ?, 1, ?, ?::jsonb)
            ON CONFLICT (probe_id) DO UPDATE
               SET received_at = EXCLUDED.received_at, sent_at = EXCLUDED.sent_at, clock_skew_ms = EXCLUDED.clock_skew_ms,
                   heartbeats = probe_status.heartbeats + 1, bundle_revision = EXCLUDED.bundle_revision, payload = EXCLUDED.payload
        SQL, [
            $sonda, $recebido->format('Y-m-d\TH:i:s.u\Z'), $enviado->format('Y-m-d\TH:i:s.u\Z'), $desvioMs,
            $h['applied']['bundle_revision'], json_encode($h, JSON_THROW_ON_ERROR),
        ]);

        return response()->noContent();
    }

    /** @param  list<string>|null  $erros */
    private function erro(int $status, string $codigo, string $mensagem, ?array $erros = null): JsonResponse
    {
        return response()->json(array_filter(
            ['error' => $codigo, 'message' => $mensagem, 'errors' => $erros],
            fn ($v) => $v !== null,
        ), $status);
    }
}
