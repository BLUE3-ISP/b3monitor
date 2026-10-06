<?php

declare(strict_types=1);

namespace App\Panel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O painel NÃO TEM autenticação ainda (ela é do F0-05: contas, 2FA e a sessão longa do 08), então não pode estar aberto a quem alcança a
 * central. A trava é dupla e o padrão é FECHADO:
 *
 *  - `B3MON_PANEL_ENABLED` desligado (o padrão): 404, como se a rota não existisse;
 *  - ligado, só atende o endereço de loopback: 403 para qualquer outro, e a cabeça `X-Forwarded-For` NÃO conta (um proxy que a preenche
 *    não transforma um cliente remoto em local).
 *
 * É o suficiente para o laboratório e para a máquina do desenvolvedor, e nada além disso. Quem expuser o painel antes do F0-05 expôs o
 * estado operacional da Blue3 sem login.
 */
final class PanelAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('b3mon.panel.enabled')) {
            abort(404);
        }
        // `server('REMOTE_ADDR')`, e não `ip()`: `ip()` pode confiar em cabeçalho de proxy.
        $origem = (string) $request->server('REMOTE_ADDR', '');
        if (! in_array($origem, ['127.0.0.1', '::1'], true)) {
            abort(403, 'O painel ainda não tem autenticação (F0-05) e só atende o loopback.');
        }

        return $next($request);
    }
}
