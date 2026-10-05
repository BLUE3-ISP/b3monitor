<?php

declare(strict_types=1);

namespace App\Decision;

use Illuminate\Support\Facades\DB;

/**
 * Escreve a INTENÇÃO de notificar (a outbox), um item por destinatário configurado. Roda dentro da transação da
 * decisão: ou a transição e a intenção entram juntas, ou nenhuma delas.
 *
 * Não envia nada. Quem envia é o worker `notify`, depois do commit, e pode entregar mais de uma vez (03 §9.4).
 */
final class Notifier
{
    /**
     * @param  list<array{channel: string, recipient: string}>|null  $destinos  quando null, vem de `b3mon.notifications.destinations`
     */
    public function __construct(private readonly ?array $destinos = null) {}

    /** @param  array<string, mixed>  $payload */
    public function request(string $incidenteId, int $versao, string $motivo, array $payload): void
    {
        foreach ($this->destinos ?? (array) config('b3mon.notifications.destinations') as $d) {
            // A unicidade `(incident_id, decision_version, reason, channel, recipient)` é a deduplicação lógica: a mesma
            // decisão reavaliada não cria uma segunda intenção.
            DB::insert(<<<'SQL'
                INSERT INTO notification_outbox (incident_id, decision_version, reason, channel, recipient, payload)
                VALUES (?, ?, ?, ?, ?, ?::jsonb)
                ON CONFLICT DO NOTHING
            SQL, [$incidenteId, $versao, $motivo, $d['channel'], $d['recipient'], json_encode($payload, JSON_THROW_ON_ERROR)]);
        }
    }
}
