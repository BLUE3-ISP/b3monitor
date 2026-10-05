<?php

declare(strict_types=1);

namespace App\Work\Handlers;

use App\Work\Handler;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * A fila `ingest`: um resultado já aceito (recibo, linha em `results` e este item, tudo na mesma transação da
 * ingestão) vira um PEDIDO DE AVALIAÇÃO do check dele.
 *
 * Não decide nada: quem roda o motor é a fila `decide` (B5). Aqui só se confirma que o resultado de fato está lá,
 * e se enfileira a reavaliação do (check, família IP), coalescida: dez resultados do mesmo check em sequência
 * geram um pedido pendente, e o do meio do processamento ainda gera outro (índice parcial de `decision_work`).
 *
 * Idempotente: processar o mesmo item duas vezes termina com o mesmo pedido pendente.
 */
final class IngestHandler implements Handler
{
    public function handle(stdClass $item, DateTimeImmutable $now): void
    {
        // O recibo prometeu "durável". Se o resultado não está em `results`, há um defeito, e a resposta é falhar
        // alto (o item tenta de novo e, esgotadas as tentativas, para como `dead`) em vez de pular em silêncio.
        $alvo = DB::selectOne(<<<'SQL'
            SELECT a.check_id, r.ip_family
              FROM results r
              JOIN assignments a ON a.id = r.assignment_id
             WHERE r.result_id = ? AND r.started_at = ?::timestamptz
        SQL, [$item->result_id, $item->result_started_at]);

        if ($alvo === null) {
            throw new RuntimeException("O resultado {$item->result_id} está no ledger e não está em results (ou sem assignment).");
        }

        DB::insert(<<<'SQL'
            INSERT INTO decision_work (check_id, ip_family) VALUES (?, ?)
            ON CONFLICT (check_id, ip_family) WHERE status = 'pending' DO NOTHING
        SQL, [$alvo->check_id, $alvo->ip_family]);
    }
}
