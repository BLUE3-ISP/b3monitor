<?php

declare(strict_types=1);

namespace App\Work;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * As operações sobre uma fila de trabalho no PostgreSQL (D-04): pegar com lease, segurar, concluir, falhar e
 * reconciliar. Sem Redis e sem broker: o banco já é o registro durável, e a fila é só uma leitura dele.
 *
 * O instante vem de FORA em toda operação, como no motor de estado: o que expira e quando é determinístico, e os
 * testes não esperam relógio de verdade.
 *
 * A vida de um item: `pending` → (claim) `processing` → (finish) `done`. Se o worker morre no meio, o item fica
 * `processing` até a lease vencer, e o reconciliador o devolve a `pending`. Se o handler falha, `fail` o devolve a
 * `pending` com espera crescente. Passou do limite de tentativas, vira `dead`: para, visível, com o motivo, em vez
 * de voltar para sempre ou sumir.
 */
final class WorkQueue
{
    public function __construct(
        private readonly int $maxAttempts = 5,
        private readonly int $backoffBaseS = 5,
        private readonly int $backoffCapS = 300,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (int) config('b3mon.work.max_attempts'),
            (int) config('b3mon.work.backoff_base_s'),
            (int) config('b3mon.work.backoff_cap_s'),
        );
    }

    /**
     * Pega o próximo item pronto e o põe `processing`, com dono e prazo. Uma instrução só: o `SELECT … FOR UPDATE
     * SKIP LOCKED` escolhe uma linha que nenhum outro worker está pegando agora, e o `UPDATE` a reivindica no mesmo
     * passo, então dois workers nunca recebem o mesmo item.
     */
    public function claim(Fila $fila, string $owner, int $leaseS, DateTimeImmutable $now): ?stdClass
    {
        $t = $fila->table();
        $linhas = DB::select(<<<SQL
            UPDATE $t
               SET status = 'processing', attempts = attempts + 1, lease_owner = ?, lease_expires_at = ?::timestamptz
             WHERE id = (
                SELECT id FROM $t
                 WHERE status = 'pending' AND available_at <= ?::timestamptz
                 ORDER BY available_at, id
                 FOR UPDATE SKIP LOCKED
                 LIMIT 1)
            RETURNING *
        SQL, [$owner, $this->ts($now->modify("+$leaseS seconds")), $this->ts($now)]);

        return $linhas[0] ?? null;
    }

    /**
     * Tranca a linha do item SE ele ainda é deste dono. Falso quer dizer que a lease se perdeu. É a primeira coisa
     * que o worker faz dentro da transação do processamento, e o lock dura até o commit: o reconciliador não tira o
     * item de quem o está concluindo, e um segundo worker não o conclui junto.
     */
    public function hold(Fila $fila, int $id, string $owner): bool
    {
        $t = $fila->table();

        return DB::selectOne(
            "SELECT id FROM $t WHERE id = ? AND status = 'processing' AND lease_owner = ? FOR UPDATE",
            [$id, $owner],
        ) !== null;
    }

    public function finish(Fila $fila, int $id, string $owner, DateTimeImmutable $now): bool
    {
        $t = $fila->table();

        return DB::update(<<<SQL
            UPDATE $t
               SET status = 'done', processed_at = ?::timestamptz, lease_owner = NULL, lease_expires_at = NULL, last_error = NULL
             WHERE id = ? AND status = 'processing' AND lease_owner = ?
        SQL, [$this->ts($now), $id, $owner]) === 1;
    }

    /**
     * O handler falhou. Só vale se o item ainda é deste dono (senão outro já assumiu e não há o que devolver).
     * `attempts` já foi incrementado no `claim`.
     *
     * @return string|null o novo status (`pending` ou `dead`), ou `null` se a lease já não era deste worker
     */
    public function fail(Fila $fila, int $id, string $owner, string $erro, DateTimeImmutable $now): ?string
    {
        $t = $fila->table();
        $linha = DB::selectOne(<<<SQL
            UPDATE $t
               SET status = CASE WHEN attempts >= ? THEN 'dead' ELSE 'pending' END,
                   available_at = ?::timestamptz + (LEAST(?::integer, ?::integer * power(2, LEAST(attempts - 1, 20))::integer) * interval '1 second'),
                   lease_owner = NULL, lease_expires_at = NULL, last_error = ?
             WHERE id = ? AND status = 'processing' AND lease_owner = ?
            RETURNING status
        SQL, [$this->maxAttempts, $this->ts($now), $this->backoffCapS, $this->backoffBaseS, mb_substr($erro, 0, 500), $id, $owner]);

        return $linha?->status;
    }

    /**
     * Devolve à fila o que ficou `processing` com a lease vencida: o worker morreu, travou ou perdeu a conexão.
     * Idempotente e seguro de rodar em vários processos ao mesmo tempo (uma instrução, e a linha que alguém está
     * concluindo está trancada). Um item que já gastou todas as tentativas vira `dead`: um item que MATA o worker
     * toda vez que é pego não pode ser reenfileirado para sempre.
     *
     * @return array{requeued: int, dead: int}
     */
    public function reconcile(Fila $fila, DateTimeImmutable $now): array
    {
        $t = $fila->table();
        $linhas = DB::select(<<<SQL
            UPDATE $t
               SET status = CASE WHEN attempts >= ? THEN 'dead' ELSE 'pending' END,
                   available_at = ?::timestamptz, lease_owner = NULL, lease_expires_at = NULL,
                   last_error = 'lease vencida: o worker morreu, travou ou perdeu a conexão'
             WHERE status = 'processing' AND lease_expires_at < ?::timestamptz
            RETURNING status
        SQL, [$this->maxAttempts, $this->ts($now), $this->ts($now)]);

        $morto = count(array_filter($linhas, fn (stdClass $l) => $l->status === 'dead'));

        return ['requeued' => count($linhas) - $morto, 'dead' => $morto];
    }

    private function ts(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
