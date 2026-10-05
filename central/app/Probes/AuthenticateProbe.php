<?php

declare(strict_types=1);

namespace App\Probes;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica a SONDA pelo token Bearer (06 §7). A credencial de sonda só serve aos endpoints da sonda: ela não
 * acessa APIs humanas, e este middleware só é posto nas rotas `/probe/*`.
 *
 * Recusa, com 401 e sem dizer qual foi o motivo (para não ensinar quem sonda): token ausente, desconhecido,
 * credencial revogada, ou sonda suspensa ou revogada.
 */
final class AuthenticateProbe
{
    public const ATRIBUTO = 'probe_id';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $linha = $token === null || $token === '' ? null : DB::table('probe_credentials as c')
            ->join('probes as p', 'p.id', '=', 'c.probe_id')
            ->where('c.token_hash', ProbeEnrollment::hash($token))
            ->whereNull('c.revoked_at')
            ->where('p.status', 'active')
            ->first(['c.id as credential_id', 'c.probe_id', 'c.last_used_at']);

        if ($linha === null) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Credencial de sonda ausente ou inválida.'], 401)
                ->header('WWW-Authenticate', 'Bearer');
        }

        // `last_used_at` só se atualiza a cada minuto: uma escrita por requisição seria custo sem valor.
        if ($linha->last_used_at === null || strtotime((string) $linha->last_used_at) < time() - 60) {
            DB::table('probe_credentials')->where('id', $linha->credential_id)->update(['last_used_at' => now()]);
        }

        $request->attributes->set(self::ATRIBUTO, $linha->probe_id);

        return $next($request);
    }
}
