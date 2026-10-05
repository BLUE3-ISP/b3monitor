<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Work\Fila;
use App\Work\Handler;
use App\Work\Resultado;
use App\Work\WorkQueue;
use App\Work\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * O worker de uma fila (D-04): `php artisan b3mon:work ingest`. Um processo por fila, sob systemd
 * (`deploy/systemd/b3mon-worker@.service`), com `Restart=always`.
 *
 * Termina de forma limpa no SIGTERM/SIGINT: acaba o item em andamento e sai, sem abandonar uma lease. Se o
 * processo morre à força (SIGKILL, queda da máquina), a lease vence e o reconciliador devolve o item.
 */
final class WorkCommand extends Command
{
    protected $signature = 'b3mon:work
        {fila : ingest, decide ou notify}
        {--once : processa no máximo um item e sai}
        {--max-jobs=0 : sai depois de N itens (0 = sem limite)}';

    protected $description = 'Roda o worker de uma fila de trabalho (ingest, decide, notify)';

    private bool $parar = false;

    public function handle(): int
    {
        $fila = Fila::tryFrom((string) $this->argument('fila'));
        if ($fila === null) {
            $this->error('Fila desconhecida. Use: '.implode(', ', array_map(fn (Fila $f) => $f->value, Fila::cases())).'.');

            return self::INVALID;
        }

        $classe = $fila->handler();
        if ($classe === null) {
            // Recusar é mais honesto que subir e consumir itens sem fazer nada com eles.
            $this->error("não implementado: o worker da fila `{$fila->value}` nasce no item {$fila->pendingItem()} da fila do loop.");

            return self::FAILURE;
        }
        /** @var Handler $handler */
        $handler = app($classe);

        $this->instalarSinais();

        $leaseS = (int) config('b3mon.work.lease_s');
        $ociosoS = max(1, (int) config('b3mon.work.idle_s'));
        $reconciliarCadaS = max(1, (int) config('b3mon.work.reconcile_every_s'));
        $owner = gethostname().':'.getmypid().':'.bin2hex(random_bytes(4));
        $worker = new Worker($queue = WorkQueue::fromConfig(), $leaseS);

        $feitos = 0;
        $limite = (int) $this->option('max-jobs');
        $proximaReconciliacao = 0;

        while (! $this->parar) {
            $agora = CarbonImmutable::now('UTC');

            // A reconciliação também roda aqui, e não só no agendador: com o cron parado, um worker vivo ainda
            // recolhe a lease de um colega morto.
            if ($agora->getTimestamp() >= $proximaReconciliacao) {
                $r = $queue->reconcile($fila, $agora);
                if ($r['requeued'] + $r['dead'] > 0) {
                    $this->warn("reconciliador: {$r['requeued']} devolvido(s) à fila, {$r['dead']} parado(s) como dead.");
                }
                $proximaReconciliacao = $agora->getTimestamp() + $reconciliarCadaS;
            }

            $resultado = $worker->step($fila, $handler, $owner, $agora);

            if ($resultado === Resultado::Ocioso) {
                if ($this->option('once')) {
                    break;
                }
                $this->dormir($ociosoS);

                continue;
            }
            $this->line("{$fila->value}: {$resultado->value}");
            $feitos++;

            if ($this->option('once') || ($limite > 0 && $feitos >= $limite)) {
                break;
            }
        }

        return self::SUCCESS;
    }

    private function instalarSinais(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $sinal) {
            pcntl_signal($sinal, function (): void {
                $this->parar = true;
            });
        }
    }

    /** Dorme em fatias de 100 ms, para o sinal de parada ser atendido logo. */
    private function dormir(int $segundos): void
    {
        for ($i = 0; $i < $segundos * 10 && ! $this->parar; $i++) {
            usleep(100_000);
        }
    }
}
