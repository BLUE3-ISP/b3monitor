<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Garante as partições diárias de `results` (06 §9): de `days_behind` atrás a `days_ahead` à frente de hoje (UTC).
 *
 * Sem isto a migração cria só a janela do dia em que rodou, e quando o último dia passa a ingestão responde
 * `retryable` para todo resultado novo (falta de partição), que é falha ALTA de propósito. Idempotente: o que já existe
 * não é recriado. Roda de hora em hora pelo `schedule:run`; a falha de criação aparece como erro do comando, e o
 * monitoramento de "falha de criação de partição" (06 §9) é o item que a observa.
 */
final class EnsurePartitionsCommand extends Command
{
    protected $signature = 'b3mon:partitions';

    protected $description = 'Cria as partições diárias de results que faltam (idempotente)';

    public function handle(): int
    {
        $atras = (int) config('b3mon.partitions.days_behind');
        $frente = (int) config('b3mon.partitions.days_ahead');

        $r = DB::selectOne(
            "select b3mon_ensure_result_partitions((now() at time zone 'utc')::date - ?::integer, (now() at time zone 'utc')::date + ?::integer) as criadas",
            [$atras, $frente],
        );

        $this->line("partições criadas: {$r->criadas} (de {$atras} dia(s) atrás a {$frente} à frente)");

        return self::SUCCESS;
    }
}
