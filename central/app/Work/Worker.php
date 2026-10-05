<?php

declare(strict_types=1);

namespace App\Work;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Um passo do worker: pega um item, processa e conclui (D-04).
 *
 * O processamento e a conclusão acontecem na MESMA transação, depois de trancar a linha com a lease conferida
 * (`WorkQueue::hold`). Disso saem as duas garantias que o B4 promete:
 *
 *  - **worker morto no meio não perde nem duplica:** a transação some com o processo, o efeito some junto, o item
 *    continua `processing` e a lease vencida o devolve à fila; quem o pegar refaz do zero;
 *  - **worker lento que perdeu a lease não duplica:** quando ele acorda, a conferência da lease falha antes de
 *    qualquer efeito, e o passo todo é descartado.
 */
final class Worker
{
    public function __construct(private readonly WorkQueue $queue, private readonly int $leaseS = 60) {}

    public function step(Fila $fila, Handler $handler, string $owner, DateTimeImmutable $now): Resultado
    {
        $item = $this->queue->claim($fila, $owner, $this->leaseS, $now);
        if ($item === null) {
            return Resultado::Ocioso;
        }

        return $this->process($fila, $handler, $item, $owner, $now);
    }

    /**
     * Processa um item JÁ pego por `$owner`. Separado do `step` para que um worker lento, que acorda depois de
     * perder a lease, seja exercitado sem relógio de verdade.
     */
    public function process(Fila $fila, Handler $handler, stdClass $item, string $owner, DateTimeImmutable $now): Resultado
    {
        try {
            DB::transaction(function () use ($fila, $handler, $item, $owner, $now): void {
                if (! $this->queue->hold($fila, (int) $item->id, $owner)) {
                    throw new LeaseLost("A lease do item {$item->id} não é mais deste worker.");
                }
                $handler->handle($item);
                if (! $this->queue->finish($fila, (int) $item->id, $owner, $now)) {
                    throw new LeaseLost("Não foi possível concluir o item {$item->id}: a lease mudou.");
                }
            });

            return Resultado::Concluido;
        } catch (LeaseLost) {
            return Resultado::LeasePerdida;
        } catch (Throwable $e) {
            // A transação já voltou, então nenhum efeito do handler ficou. O item é devolvido (ou parado).
            $this->queue->fail($fila, (int) $item->id, $owner, $e::class.': '.$e->getMessage(), $now);

            return Resultado::Falhou;
        }
    }
}
