<?php

declare(strict_types=1);

namespace App\Ingestion;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A ingestão durável (06 §3 e §6; item B3 da fila).
 *
 * Cada resultado é gravado numa transação PRÓPRIA: o recibo no ledger, a linha em `results` e o item em
 * `ingestion_work` entram juntos ou não entram. O `accepted` só é devolvido depois do commit, então uma queda
 * entre o commit e a resposta deixa a sonda reenviar, e o reenvio é um `duplicate`: nenhum segundo trabalho, nenhum
 * incidente novo.
 *
 * A autenticação e a autorização já foram feitas (a credencial corresponde à sonda e aos assignments do lote), e o
 * schema já foi validado. Aqui só se decide o destino de cada item.
 */
final class IngestResults
{
    public function __construct(private readonly ResultHasher $hasher) {}

    /**
     * @param  list<array<string, mixed>>  $resultados
     * @return list<array<string, mixed>> um item de resposta por resultado, na ordem recebida (06 §6)
     */
    public function handle(array $resultados, DateTimeImmutable $agora): array
    {
        $agora = $agora->setTimezone(new DateTimeZone('UTC'));
        $itens = [];
        foreach ($resultados as $resultado) {
            $itens[] = $this->item($resultado, $agora);
        }

        return $itens;
    }

    /** @param  array<string, mixed>  $r */
    private function item(array $r, DateTimeImmutable $agora): array
    {
        $recebido = $agora->format('Y-m-d\TH:i:s.u\Z');

        // Mais velho que a janela de aceitação histórica: rejeitado de forma EXPLÍCITA, e nada é gravado. Depois do
        // expurgo, é esta regra de idade que impede um resultado antigo de ser aceito de novo (06 §3).
        $limite = $agora->modify('-'.(int) config('b3mon.retention.acceptance_days').' days');
        if ($this->instante($r['started_at']) < $limite) {
            return $this->resposta($r, 'rejected_terminal', $recebido, durable: false);
        }

        $hash = $this->hasher->hash($r);

        try {
            return DB::transaction(function () use ($r, $hash, $agora, $recebido): array {
                $guardar = $agora->modify('+'.(int) config('b3mon.retention.ledger_days').' days')->format('Y-m-d\TH:i:s.u\Z');

                // `ON CONFLICT DO NOTHING` sem alvo cobre as TRÊS chaves únicas do ledger de uma vez: o result_id e as
                // duas chaves naturais (slot agendado e pedido manual). Quem perde uma corrida espera o commit do outro.
                $novo = DB::selectOne(
                    'insert into result_receipts (result_id, probe_id, assignment_id, config_generation, execution_kind,
                        scheduled_at, manual_request_id, content_hash, result_started_at, received_at, retain_until)
                     values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) on conflict do nothing returning result_id',
                    [$r['result_id'], $r['probe_id'], $r['assignment_id'], $r['config_generation'], $r['execution_kind'],
                        $r['scheduled_at'], $r['manual_request_id'] ?? null, $hash, $r['started_at'], $recebido, $guardar],
                );

                if ($novo === null) {
                    return $this->jaConhecido($r, $hash, $recebido);
                }

                DB::table('results')->insert([
                    'result_id' => $r['result_id'],
                    'probe_id' => $r['probe_id'],
                    'assignment_id' => $r['assignment_id'],
                    'config_generation' => $r['config_generation'],
                    'policy_revision' => $r['policy_revision'],
                    'execution_kind' => $r['execution_kind'],
                    'manual_request_id' => $r['manual_request_id'] ?? null,
                    'scheduled_at' => $r['scheduled_at'],
                    'started_at' => $r['started_at'],
                    'finished_at' => $r['finished_at'],
                    'duration_ms' => $r['duration_ms'],
                    'ip_family' => $r['ip_family'],
                    'outcome' => $r['outcome'],
                    'reason_code' => $r['reason_code'],
                    'clock_status' => $r['clock']['status'],
                    'probe_integrity' => $r['integrity']['probe_assessment'],
                    'payload' => $this->hasher->canonico($r),
                    'content_hash' => $hash,
                    'received_at' => $recebido,
                ]);

                DB::table('ingestion_work')->insert([
                    'result_id' => $r['result_id'],
                    'result_started_at' => $r['started_at'],
                ]);

                return $this->resposta($r, 'accepted', $recebido, durable: true);
            });
        } catch (QueryException $e) {
            // Sem partição para o dia do resultado, o INSERT falha ALTO (check_violation) e a transação INTEIRA volta,
            // inclusive o recibo: nada fica órfão. É um defeito da plataforma, não do resultado, então a sonda tenta de novo.
            if ($e->getCode() === '23514' && str_contains($e->getMessage(), 'partition')) {
                return $this->resposta($r, 'retryable', $recebido, durable: false);
            }
            throw $e;
        }
    }

    /**
     * O ledger já conhecia este resultado, ou esta chave. Mesmo conteúdo é um reenvio (`duplicate`, durável); conteúdo
     * diferente é um CONFLITO: a observação original não é sobrescrita e a divergência é auditada (06 §3).
     *
     * @param  array<string, mixed>  $r
     */
    private function jaConhecido(array $r, string $hash, string $recebido): array
    {
        $existente = DB::table('result_receipts')->where('result_id', $r['result_id'])->first(['content_hash']);

        if ($existente !== null && hash_equals(trim((string) $existente->content_hash), $hash)) {
            return $this->resposta($r, 'duplicate', $recebido, durable: true);
        }

        DB::table('audit_events')->insert([
            'actor_type' => 'probe',
            'actor_id' => $r['probe_id'],
            'action' => 'ingest.conflict',
            'resource_type' => 'result',
            'resource_id' => $r['result_id'],
            'diff' => json_encode([
                'motivo' => $existente === null ? 'mesma chave natural com outro result_id' : 'mesmo result_id com outro conteúdo',
                'hash_recebido' => $hash,
                'hash_guardado' => $existente === null ? null : trim((string) $existente->content_hash),
            ], JSON_THROW_ON_ERROR),
        ]);

        return $this->resposta($r, 'conflict', $recebido, durable: false);
    }

    /** @param  array<string, mixed>  $r */
    private function resposta(array $r, string $status, string $recebido, bool $durable): array
    {
        return [
            'result_id' => $r['result_id'],
            'status' => $status,
            'received_at' => $recebido,
            'durable' => $durable,
        ];
    }

    private function instante(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }
}
