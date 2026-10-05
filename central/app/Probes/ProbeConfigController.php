<?php

declare(strict_types=1);

namespace App\Probes;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/probe/config (06 §4): o bundle da sonda AUTENTICADA, e só o dela. A identidade vem da credencial (middleware
 * `probe.auth`), nunca de um parâmetro: não existe `?probe_id=` para a sonda pedir o de outra.
 */
final class ProbeConfigController
{
    public function __invoke(Request $request, BundleBuilder $builder): JsonResponse
    {
        $bundle = $builder->build((string) $request->attributes->get(AuthenticateProbe::ATRIBUTO), now()->toDateTimeImmutable());

        // Sem cache: a sonda tem de ver a revisão vigente, e um intermediário não pode servir o bundle de outra.
        return response()->json($bundle)->header('Cache-Control', 'no-store');
    }
}
