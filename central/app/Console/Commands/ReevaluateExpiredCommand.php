<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pede a reavaliação dos estados cuja evidência ENVELHECEU (03 §3; T-008): a expiração é por TEMPO.
 *
 * O motor é puro e não pede nada a ninguém: ele só reavalia quando há um pedido em `decision_work`, e o `IngestHandler` faz esse pedido
 * a cada resultado novo. Sem resultado novo (a sonda parou, a rede caiu, a central ficou fora), ninguém pede, e um `up` de ontem ficaria
 * `up` para sempre: exatamente o verde antigo que o produto promete nunca mostrar. Este comando fecha o buraco: para todo estado que não
 * é `unknown` e cuja validade (`valid_until`) já passou, enfileira um pedido (coalescido: no máximo um pendente por check e família).
 *
 * Roda a cada 10 s pelo agendador. A decisão que sai dele segue as regras de sempre: `unknown` NÃO fecha um incidente aberto e não
 * repete aviso, então expirar não inventa queda nem recuperação.
 */
// O instante vem do relógio da aplicação (e não do `now()` do banco), como nas operações da fila: o teste move o relógio.
final class ReevaluateExpiredCommand extends Command
{
    protected $signature = 'b3mon:reevaluate-expired';

    protected $description = 'Enfileira a reavaliação dos estados cuja evidência envelheceu (expiração por tempo)';

    public function handle(): int
    {
        $n = DB::affectingStatement(<<<'SQL'
            INSERT INTO decision_work (check_id, ip_family)
            SELECT check_id, ip_family
              FROM current_states
             WHERE health <> 'unknown' AND valid_until IS NOT NULL AND valid_until < ?::timestamptz
            ON CONFLICT (check_id, ip_family) WHERE status = 'pending' DO NOTHING
        SQL, [now()->utc()->format('Y-m-d\TH:i:s.u\Z')]);

        $this->line("reavaliações pedidas: {$n}");

        return self::SUCCESS;
    }
}
