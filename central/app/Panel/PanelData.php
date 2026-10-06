<?php

declare(strict_types=1);

namespace App\Panel;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * O que o painel mostra (08 §5; D-06; C-12), montado SÓ do que o banco já tem. Nada aqui decide estado: o motor decide, o painel LÊ, e
 * a regra de leitura que importa é uma só: **nunca mostrar verde antigo**.
 *
 * A saúde exibida de um estado é `unknown` quando a evidência EXPIROU (`valid_until` passou) ou nunca existiu. O estado registrado fica
 * ao lado, marcado como histórico ("último confirmado"), porque o painel é lido a qualquer momento e a reavaliação por tempo
 * (`b3mon:reevaluate-expired`, a cada 10 s) pode ainda não ter rodado: um `up` de dois minutos atrás não pode aparecer como o presente.
 *
 * Cobertura e integridade são colunas SEPARADAS da saúde (C-12): "verde" com cobertura reduzida não é a mesma coisa que "verde" com
 * cobertura plena, e o estado desconhecido por falta de cobertura não é uma falha do alvo.
 */
final class PanelData
{
    /** Heartbeat mais velho que isto é "sem heartbeat": a sonda está calada ou a central não a ouve. */
    public const HEARTBEAT_VELHO_S = 90;

    /** @return array<string, mixed> */
    public function snapshot(DateTimeImmutable $agora): array
    {
        return [
            'agora' => $agora,
            'estados' => $this->estados($agora),
            'sondas' => $this->sondas($agora),
            'incidentes' => $this->incidentes($agora),
            'avisos' => $this->avisos(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function estados(DateTimeImmutable $agora): array
    {
        $linhas = DB::select(<<<'SQL'
            SELECT s.check_id, s.ip_family, s.health, s.scope, s.coverage, s.valid_domains, s.planned_domains, s.as_of,
                   s.last_valid_observation_at, s.valid_until, s.policy_revision, s.evaluation, s.version,
                   COALESCE(c.name, s.check_id::text) AS nome
              FROM current_states s
              LEFT JOIN checks c ON c.id = s.check_id
             ORDER BY nome, s.ip_family
        SQL);

        $nomes = $this->nomesDeAssignment();

        return array_map(function (object $l) use ($agora, $nomes): array {
            $validade = $l->valid_until === null ? null : new DateTimeImmutable($l->valid_until);
            // Sem validade (nunca houve observação elegível) ou validade vencida: a evidência não vale mais.
            $expirado = $l->health !== 'unknown' && ($validade === null || $agora > $validade);
            $evaluation = json_decode((string) $l->evaluation, true) ?: [];
            $perspectivas = array_map(fn (array $p) => [
                'sonda' => $nomes[$p['id']] ?? substr((string) $p['id'], 0, 8),
                'dominio' => $p['domain'],
                'saude' => $p['health'],
                'pendente' => $p['pending'],
                'ultimo_slot' => $p['last_eligible_slot'],
                'motivo' => $p['reason'],
            ], $evaluation['perspectives'] ?? []);

            return [
                'verificacao' => $l->nome,
                'familia' => $l->ip_family,
                // O que o painel MOSTRA como saúde, e o que o banco registra, que só aparece rotulado como histórico.
                'saude' => $expirado ? 'unknown' : $l->health,
                'saude_registrada' => $l->health,
                'expirado' => $expirado,
                'abrangencia' => $expirado ? 'unknown' : $l->scope,
                'cobertura' => $l->coverage,
                'dominios' => "{$l->valid_domains}/{$l->planned_domains}",
                'integridade' => $this->integridade($perspectivas),
                'relogio' => $this->relogio($perspectivas),
                'as_of' => new DateTimeImmutable($l->as_of),
                'valid_until' => $validade,
                'idade_s' => max(0, $agora->getTimestamp() - (new DateTimeImmutable($l->as_of))->getTimestamp()),
                'ultima_observacao' => $l->last_valid_observation_at === null ? null : new DateTimeImmutable($l->last_valid_observation_at),
                'perspectivas' => $perspectivas,
                'politica' => $l->policy_revision,
            ];
        }, $linhas);
    }

    /**
     * Integridade da MEDIÇÃO (as sondas medem direito?), separada da saúde do alvo. `comprometida` se alguma perspectiva foi descartada
     * por integridade; `sem dado` se nenhuma tem amostra; `válida` no resto.
     *
     * @param  list<array<string, mixed>>  $perspectivas
     */
    private function integridade(array $perspectivas): string
    {
        foreach ($perspectivas as $p) {
            if ($p['motivo'] === 'integrity_not_valid') {
                return 'comprometida';
            }
        }
        $comDado = array_filter($perspectivas, fn ($p) => $p['ultimo_slot'] !== null);

        return $comDado === [] ? 'sem dado' : 'válida';
    }

    /** @param  list<array<string, mixed>>  $perspectivas */
    private function relogio(array $perspectivas): string
    {
        foreach ($perspectivas as $p) {
            if ($p['motivo'] === 'clock_untrusted') {
                return 'não confiável';
            }
        }

        return 'ok';
    }

    /** @return array<string, string> assignment_id => nome da sonda */
    private function nomesDeAssignment(): array
    {
        $out = [];
        foreach (DB::select('SELECT a.id, p.name FROM assignments a JOIN probes p ON p.id = a.probe_id') as $l) {
            $out[$l->id] = $l->name;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function sondas(DateTimeImmutable $agora): array
    {
        $linhas = DB::select(<<<'SQL'
            SELECT p.id, p.name, p.observation_domain, p.status, p.bundle_revision AS revisao_central,
                   st.received_at, st.clock_skew_ms, st.heartbeats, st.bundle_revision AS revisao_aplicada, st.payload
              FROM probes p
              LEFT JOIN probe_status st ON st.probe_id = p.id
             ORDER BY p.name
        SQL);

        return array_map(function (object $l) use ($agora): array {
            $recebido = $l->received_at === null ? null : new DateTimeImmutable($l->received_at);
            $idade = $recebido === null ? null : max(0, $agora->getTimestamp() - $recebido->getTimestamp());
            $h = $l->payload === null ? [] : (json_decode((string) $l->payload, true) ?: []);

            return [
                'nome' => $l->name,
                'dominio' => $l->observation_domain,
                'status' => $l->status,
                'heartbeat_idade_s' => $idade,
                // Nunca recebeu, ou o último passou do limite: a sonda está calada para a central.
                'calada' => $idade === null || $idade > self::HEARTBEAT_VELHO_S,
                'versao' => $h['build']['version'] ?? null,
                'revisao_central' => (int) $l->revisao_central,
                'revisao_aplicada' => $l->revisao_aplicada === null ? null : (int) $l->revisao_aplicada,
                'desvio_relogio_ms' => $l->clock_skew_ms === null ? null : (int) $l->clock_skew_ms,
                'relogio' => $h['clock']['status'] ?? null,
                'relogio_referencia' => $h['clock']['reference_kind'] ?? null,
                'spool_linhas' => $h['spool']['rows'] ?? null,
                'spool_idade_s' => $h['spool']['oldest_age_s'] ?? null,
                'descartados' => $h['spool']['dropped'] ?? null,
                'uptime_s' => $h['uptime_s'] ?? null,
            ];
        }, $linhas);
    }

    /** @return list<array<string, mixed>> */
    public function incidentes(DateTimeImmutable $agora): array
    {
        $linhas = DB::select(<<<'SQL'
            SELECT i.id, i.ip_family, i.incident_class, i.severity, i.scope, i.observed_start_at, i.confirmed_at,
                   i.observed_end_at, i.confirmed_end_at, i.version,
                   COALESCE(c.name, i.check_id::text) AS nome,
                   (SELECT count(*) FROM notification_outbox o WHERE o.incident_id = i.id) AS avisos
              FROM incidents i
              LEFT JOIN checks c ON c.id = i.check_id
             ORDER BY (i.confirmed_end_at IS NULL) DESC, i.confirmed_at DESC
             LIMIT 25
        SQL);

        return array_map(function (object $l) use ($agora): array {
            $inicio = new DateTimeImmutable($l->observed_start_at);
            $fim = $l->confirmed_end_at === null ? null : new DateTimeImmutable($l->confirmed_end_at);

            return [
                'id' => $l->id,
                'curto' => substr($l->id, 0, 8),
                'verificacao' => $l->nome,
                'familia' => $l->ip_family,
                'classe' => $l->incident_class,
                'gravidade' => $l->severity,
                'abrangencia' => $l->scope,
                'aberto' => $fim === null,
                'inicio_observado' => $inicio,
                'confirmado_em' => new DateTimeImmutable($l->confirmed_at),
                'fim_confirmado' => $fim,
                'duracao_s' => max(0, ($fim ?? $agora)->getTimestamp() - $inicio->getTimestamp()),
                'versao' => (int) $l->version,
                'avisos' => (int) $l->avisos,
            ];
        }, $linhas);
    }

    /** @return list<array<string, mixed>> */
    public function avisos(): array
    {
        $linhas = DB::select(<<<'SQL'
            SELECT o.id, o.incident_id, o.reason, o.decision_version, o.channel, o.recipient, o.simulated, o.status, o.attempts,
                   o.created_at
              FROM notification_outbox o
             ORDER BY o.id DESC
             LIMIT 10
        SQL);

        return array_map(fn (object $l) => [
            'incidente' => substr($l->incident_id, 0, 8),
            'motivo' => $l->reason,
            'versao' => (int) $l->decision_version,
            'canal' => $l->channel,
            'destinatario' => $l->recipient,
            'simulado' => (bool) $l->simulated,
            'status' => $l->status,
            'criado_em' => new DateTimeImmutable($l->created_at),
            'tentativas' => (int) $l->attempts,
        ], $linhas);
    }

    /** Formata um instante em UTC (14 §6: persiste em UTC, apresenta em Brasília), com o fuso dito. */
    public static function hora(?DateTimeImmutable $t, bool $comData = false): string
    {
        if ($t === null) {
            return '—';
        }

        return $t->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format($comData ? 'd/m H:i:s' : 'H:i:s');
    }

    /** "há 12 s", "há 3 min", "há 2 h". */
    public static function idade(?int $segundos): string
    {
        return match (true) {
            $segundos === null => '—',
            $segundos < 60 => "{$segundos} s",
            $segundos < 3600 => intdiv($segundos, 60).' min',
            default => intdiv($segundos, 3600).' h '.intdiv($segundos % 3600, 60).' min',
        };
    }
}
