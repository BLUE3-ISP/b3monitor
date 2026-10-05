<?php

declare(strict_types=1);

namespace App\Decision;

use App\State\Evaluation;
use App\State\Health;
use App\State\Policy;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Abre, atualiza e fecha o incidente de SERVIÇO de um (check, família IP), e grava a intenção de notificar na MESMA
 * transação (03 §7 e §9.4; outbox transacional).
 *
 * A decisão olha só o PRESENTE da avaliação, e não as transições históricas. É o que faz o replay não gerar tempestade:
 * cem amostras antigas recompõem o histórico (`StateProjector`), mas, se o presente é `unknown` por falta de evidência
 * recente, nada se abre e ninguém é avisado. "Incidente que começou e terminou no passado" é histórico, não push.
 *
 * | Presente | Sem incidente aberto | Com incidente aberto |
 * |---|---|---|
 * | `down` / `degraded` | **abre** (e avisa uma vez) | muda gravidade/abrangência: atualiza; avisa só o AGRAVAMENTO (warning → critical) |
 * | `up` | nada | **fecha**, e avisa a recuperação só se a abertura foi avisada |
 * | `unknown` | nada | **nada**: não fecha por falta de evidência (nunca verde antigo) e não repete aviso |
 *
 * A confirmação e a recuperação "sustentadas" já vêm do motor (3 falhas ou 3 sucessos consecutivos por perspectiva, com
 * quórum de domínios): `down` e `up` só aparecem aqui depois delas.
 *
 * Idempotente por construção: a decisão compara a avaliação com o incidente aberto, e reavaliar o mesmo estado não muda
 * nada. A unicidade da outbox é a rede por baixo.
 *
 * O que NÃO está aqui: reabertura por recaída dentro de `reopen_window_s`, instabilidade (anti-flap, 03 §7.1), integridade
 * persistente (§7.2) e aviso de perda de cobertura. São do F0-04.
 */
final class IncidentManager
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @return string|null o que foi decidido: `opened`, `updated`, `recovered`, ou null se nada mudou
     */
    public function apply(string $checkId, string $family, Evaluation $e, Policy $policy): ?string
    {
        $chave = "service:{$checkId}:{$family}";
        $aberto = DB::selectOne(
            'SELECT * FROM incidents WHERE correlation_key = ? AND confirmed_end_at IS NULL FOR UPDATE',
            [$chave],
        );
        $comFalha = $e->health === Health::Down || $e->health === Health::Degraded;

        if ($aberto === null) {
            return $comFalha ? $this->open($checkId, $family, $chave, $e, $policy) : null;
        }
        if ($e->health === Health::Up) {
            return $this->recover($aberto, $e, $policy);
        }
        if ($comFalha) {
            return $this->update($aberto, $e);
        }

        return null;   // unknown: não fecha, não repete
    }

    private function open(string $checkId, string $family, string $chave, Evaluation $e, Policy $policy): string
    {
        $id = (string) Str::uuid();
        $inicio = $this->observedStart($e, $policy);

        DB::insert(<<<'SQL'
            INSERT INTO incidents (id, check_id, ip_family, incident_class, severity, scope, correlation_key,
                                   observed_start_at, confirmed_at, version)
            VALUES (?, ?, ?, 'service', ?, ?, ?, ?::timestamptz, ?::timestamptz, 1)
        SQL, [$id, $checkId, $family, $this->severity($e), $e->scope->value, $chave, $this->ts($inicio), $this->ts($e->asOf)]);

        $this->event($id, 'opened', $e->asOf, ['health' => $e->health->value, 'scope' => $e->scope->value, 'policy_revision' => $e->policyRevision]);
        $this->notifier->request($id, 1, 'opened', $this->payload($id, $checkId, $family, $e, 'opened', 1, $inicio, $e->asOf));

        return 'opened';
    }

    private function update(object $aberto, Evaluation $e): ?string
    {
        $gravidade = $this->severity($e);
        if ($aberto->severity === $gravidade && $aberto->scope === $e->scope->value) {
            return null;
        }

        $versao = (int) $aberto->version + 1;
        DB::update(
            'UPDATE incidents SET severity = ?, scope = ?, version = ?, updated_at = now() WHERE id = ?',
            [$gravidade, $e->scope->value, $versao, $aberto->id],
        );
        $this->event($aberto->id, 'updated', $e->asOf, [
            'severity' => ['de' => $aberto->severity, 'para' => $gravidade],
            'scope' => ['de' => $aberto->scope, 'para' => $e->scope->value],
            'health' => $e->health->value,
        ]);

        // Só o AGRAVAMENTO avisa (03 §9.4: "um aviso consolidado de agravamento"). Melhorar de down para degraded atualiza a
        // linha do tempo e fica quieto.
        if ($aberto->severity === 'warning' && $gravidade === 'critical') {
            $inicio = new DateTimeImmutable($aberto->observed_start_at);
            $this->notifier->request($aberto->id, $versao, 'worsened', $this->payload(
                $aberto->id, $aberto->check_id, $aberto->ip_family, $e, 'worsened', $versao, $inicio, new DateTimeImmutable($aberto->confirmed_at),
            ));
        }

        return 'updated';
    }

    private function recover(object $aberto, Evaluation $e, Policy $policy): string
    {
        $versao = (int) $aberto->version + 1;
        $inicio = new DateTimeImmutable($aberto->observed_start_at);
        $fim = $this->observedEnd($e, $inicio, $policy);

        DB::update(<<<'SQL'
            UPDATE incidents
               SET observed_end_at = ?::timestamptz, confirmed_end_at = ?::timestamptz, version = ?, updated_at = now()
             WHERE id = ?
        SQL, [$this->ts($fim), $this->ts($e->asOf), $versao, $aberto->id]);
        $this->event($aberto->id, 'recovered', $e->asOf, ['health' => $e->health->value, 'observed_end_at' => $this->ts($fim)]);

        // Recuperação só é avisada se a queda foi: aviso de "voltou" para quem nunca soube que caiu é ruído.
        $foiAvisado = DB::selectOne(
            "SELECT 1 AS sim FROM notification_outbox WHERE incident_id = ? AND reason = 'opened' LIMIT 1",
            [$aberto->id],
        ) !== null;
        if ($foiAvisado) {
            $this->notifier->request($aberto->id, $versao, 'recovered', $this->payload(
                $aberto->id, $aberto->check_id, $aberto->ip_family, $e, 'recovered', $versao, $inicio, new DateTimeImmutable($aberto->confirmed_at), $this->peakSeverity($aberto),
            ));
        }

        return 'recovered';
    }

    /**
     * A MAIOR gravidade que o incidente já teve. O aviso de recuperação diz quão ruim foi, e um incidente que chegou a `down` e aliviou
     * para `degraded` antes de fechar foi crítico: a gravidade do instante do fechamento esconderia isso.
     */
    private function peakSeverity(object $aberto): string
    {
        $chegouADown = DB::selectOne(
            "SELECT 1 AS sim FROM incident_events WHERE incident_id = ? AND payload->>'health' = 'down' LIMIT 1",
            [$aberto->id],
        ) !== null;

        return $chegouADown ? 'critical' : $aberto->severity;
    }

    private function severity(Evaluation $e): string
    {
        return $e->health === Health::Down ? 'critical' : 'warning';
    }

    /**
     * Quando a queda COMEÇOU a ser vista: a primeira falha do episódio mais antigo entre as perspectivas que estão em
     * `down` agora. A transição do motor carrega o slot que CONFIRMOU a queda (a terceira falha), e a confirmação exige
     * `failureCount` slots consecutivos, então o início é `failureCount - 1` intervalos antes dela. Diferente de quando foi
     * confirmada (03 §7: início observado e instante de detecção são coisas distintas). Nunca depois da avaliação.
     */
    private function observedStart(Evaluation $e, Policy $policy): DateTimeImmutable
    {
        $inicio = null;
        foreach ($e->perspectives as $p) {
            if ($p->health !== Health::Down) {
                continue;
            }
            $ultimaQueda = null;
            foreach ($p->transitions as $t) {
                if ($t->to === Health::Down) {
                    $ultimaQueda = $t->at;
                }
            }
            if ($ultimaQueda !== null && ($inicio === null || $ultimaQueda < $inicio)) {
                $inicio = $ultimaQueda;
            }
        }

        if ($inicio === null) {
            return $e->asOf;
        }
        $inicio = $inicio->modify('-'.(($policy->failureCount - 1) * $policy->intervalS).' seconds');

        return $inicio > $e->asOf ? $e->asOf : $inicio;
    }

    /**
     * Quando o serviço voltou a ser visto bom: o primeiro sucesso da ÚLTIMA perspectiva a se recuperar (a transição
     * confirma no `recoveryCount`-ésimo sucesso consecutivo). Nunca antes do início.
     */
    private function observedEnd(Evaluation $e, DateTimeImmutable $inicio, Policy $policy): DateTimeImmutable
    {
        $fim = null;
        foreach ($e->perspectives as $p) {
            foreach ($p->transitions as $t) {
                if ($t->to === Health::Up && $t->at >= $inicio && ($fim === null || $t->at > $fim)) {
                    $fim = $t->at;
                }
            }
        }

        if ($fim === null) {
            return $e->asOf;
        }
        $fim = $fim->modify('-'.(($policy->recoveryCount - 1) * $policy->intervalS).' seconds');
        if ($fim < $inicio) {
            $fim = $inicio;
        }

        return $fim > $e->asOf ? $e->asOf : $fim;
    }

    /** @return array<string, mixed> */
    private function payload(string $id, string $checkId, string $family, Evaluation $e, string $reason, int $versao, DateTimeImmutable $inicio, DateTimeImmutable $confirmado, ?string $gravidade = null): array
    {
        return [
            'incident_id' => $id,
            'check_id' => $checkId,
            'ip_family' => $family,
            'class' => 'service',
            'reason' => $reason,
            'decision_version' => $versao,
            'health' => $e->health->value,
            // A gravidade é a do INCIDENTE: na recuperação a avaliação é `up`, e "warning" ali diria que o incidente foi leve.
            'severity' => $gravidade ?? $this->severity($e),
            'scope' => $e->scope->value,
            'observed_start_at' => $this->ts($inicio),
            'confirmed_at' => $this->ts($confirmado),
            'as_of' => $this->ts($e->asOf),
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function event(string $incidenteId, string $tipo, DateTimeImmutable $at, array $payload): void
    {
        DB::insert(
            'INSERT INTO incident_events (incident_id, event_type, at, payload) VALUES (?, ?, ?::timestamptz, ?::jsonb)',
            [$incidenteId, $tipo, $this->ts($at), json_encode($payload, JSON_THROW_ON_ERROR)],
        );
    }

    private function ts(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
