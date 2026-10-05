<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Work\Fila;
use App\Work\WorkQueue;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Devolve à fila o que ficou `processing` com a lease vencida, em todas as filas. Roda a cada minuto pelo
 * `schedule:run` (cron). O worker também reconcilia a própria fila; este comando cobre o caso de a fila estar sem
 * worker vivo, para que o item já esteja pronto quando um subir.
 */
final class ReconcileWorkCommand extends Command
{
    protected $signature = 'b3mon:work-reconcile';

    protected $description = 'Reenfileira itens de trabalho com lease vencida';

    public function handle(): int
    {
        $queue = WorkQueue::fromConfig();
        $agora = CarbonImmutable::now('UTC');
        foreach (Fila::cases() as $fila) {
            $r = $queue->reconcile($fila, $agora);
            $this->line(sprintf('%-7s devolvidos=%d dead=%d', $fila->value, $r['requeued'], $r['dead']));
        }

        return self::SUCCESS;
    }
}
