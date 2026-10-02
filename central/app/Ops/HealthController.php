<?php

declare(strict_types=1);

namespace App\Ops;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GET /api/v1/health — a central está de pé e alcança o banco?
 *
 * ⚠️ Isto NÃO é o canário de processamento (F0-07). Um 200 aqui prova só que o processo web
 * responde e o PostgreSQL atende; não prova que os workers andam nem que o motor decide.
 * "API 200 com worker parado" é exatamente o caso que o canário existe para pegar, e por
 * isso este endpoint não diz "tudo operacional".
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        $banco = $this->bancoResponde();

        return response()->json([
            'status' => $banco ? 'ok' : 'unavailable',
            'service' => 'b3mon-central',
            // RFC 3339 em UTC, como manda o 14 §6 do pacote 2.0.
            'as_of' => now()->utc()->toRfc3339String(),
            'checks' => ['database' => $banco ? 'ok' : 'fail'],
        ], $banco ? 200 : 503);
    }

    private function bancoResponde(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
