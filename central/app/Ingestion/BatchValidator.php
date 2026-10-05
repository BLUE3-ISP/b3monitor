<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Ops\Glossario;
use DateTimeImmutable;

/**
 * Valida o corpo de `POST /api/v1/probe/results` contra o contrato (`contracts/schemas/ingest-batch.schema.json` e
 * `result.schema.json`).
 *
 * ⚠️ É UMA SEGUNDA IMPLEMENTAÇÃO das regras do contrato, escrita à mão, porque a biblioteca de JSON Schema ainda
 * depende de decisão do dono (ADR-014, item A8b). O que impede que ela divirja do contrato em silêncio é o teste
 * `BatchValidatorTest`: ele passa cada fixture `valido-*` e exige que cada `invalido-*` seja recusada, com o
 * `manifest.json` como oráculo. Quem mudar um schema precisa mudar isto, e o teste quebra se não mudar.
 *
 * As mensagens dizem o CAMINHO e a REGRA, e nunca o valor recebido: uma sonda não ganha o seu próprio dado de volta.
 */
final class BatchValidator
{
    public const MAX_ITEMS = 500;

    use ValidaContrato;

    /**
     * @return list<string> vazio quando o lote é válido
     */
    public function validateBatch(mixed $lote): array
    {
        $this->erros = [];
        $this->lote($lote);

        return $this->erros;
    }

    /**
     * Valida um resultado isolado (schema `result`), útil nos testes de conformidade.
     *
     * @return list<string>
     */
    public function validateResult(mixed $resultado): array
    {
        $this->erros = [];
        $this->resultado($resultado, '$');

        return $this->erros;
    }

    private function lote(mixed $lote): void
    {
        if (! $this->objeto($lote)) {
            $this->erro('$', 'deve ser um objeto');

            return;
        }
        $this->exato($lote, ['batch_id', 'mode', 'replay_session_id', 'results'], [], '$');

        $this->uuid($lote['batch_id'] ?? null, '$.batch_id');
        $modo = $lote['mode'] ?? null;
        $this->enum($modo, ['live', 'replay'], '$.mode');

        // Replay carrega a sessão; fluxo corrente não carrega (06 §6).
        if ($modo === 'replay') {
            $this->uuid($lote['replay_session_id'] ?? null, '$.replay_session_id');
        } elseif (($lote['replay_session_id'] ?? null) !== null) {
            $this->erro('$.replay_session_id', 'deve ser nulo no fluxo corrente (mode live)');
        }

        $resultados = $lote['results'] ?? null;
        if (! is_array($resultados) || ! array_is_list($resultados)) {
            $this->erro('$.results', 'deve ser uma lista');

            return;
        }
        if (count($resultados) < 1 || count($resultados) > self::MAX_ITEMS) {
            $this->erro('$.results', 'deve ter de 1 a '.self::MAX_ITEMS.' resultados');
        }
        foreach ($resultados as $i => $resultado) {
            if ($this->cheio()) {
                return;
            }
            $caminho = "\$.results[$i]";
            $this->resultado($resultado, $caminho);
            // O canário tem endpoint próprio, e a credencial dele não injeta resultados de sondas (06 §7).
            if ($this->objeto($resultado) && ! in_array($resultado['execution_kind'] ?? null, ['scheduled', 'manual'], true)
                && is_string($resultado['execution_kind'] ?? null) && in_array($resultado['execution_kind'], Glossario::executionKind(), true)) {
                $this->erro("$caminho.execution_kind", 'só scheduled e manual entram por aqui; o canário tem endpoint próprio');
            }
        }
    }

    private function resultado(mixed $r, string $p): void
    {
        if (! $this->objeto($r)) {
            $this->erro($p, 'deve ser um objeto');

            return;
        }

        $this->exato($r, [
            'schema_version', 'result_id', 'probe_id', 'assignment_id', 'config_generation', 'policy_revision',
            'execution_kind', 'scheduled_at', 'started_at', 'finished_at', 'duration_ms', 'ip_family', 'outcome',
            'reason_code', 'actual_destination', 'metrics', 'clock', 'integrity', 'path_evidence', 'executor',
        ], ['manual_request_id'], $p);

        if (($r['schema_version'] ?? null) !== '1.0') {
            $this->erro("$p.schema_version", 'deve ser "1.0"');
        }
        $this->uuid($r['result_id'] ?? null, "$p.result_id");
        $this->uuid($r['probe_id'] ?? null, "$p.probe_id");
        $this->uuid($r['assignment_id'] ?? null, "$p.assignment_id");
        $this->inteiro($r['config_generation'] ?? null, 1, null, "$p.config_generation");
        $this->texto($r['policy_revision'] ?? null, "$p.policy_revision");
        $this->enum($r['execution_kind'] ?? null, Glossario::executionKind(), "$p.execution_kind");
        $this->timestamp($r['started_at'] ?? null, "$p.started_at");
        $this->timestamp($r['finished_at'] ?? null, "$p.finished_at");
        $this->inteiro($r['duration_ms'] ?? null, 0, null, "$p.duration_ms");
        $this->enum($r['ip_family'] ?? null, Glossario::ipFamily(), "$p.ip_family");
        $this->enum($r['outcome'] ?? null, Glossario::outcome(), "$p.outcome");
        $this->enum($r['reason_code'] ?? null, Glossario::reasonCode(), "$p.reason_code");

        $this->agendamento($r, $p);
        $this->destino($r['actual_destination'] ?? null, $r['ip_family'] ?? null, "$p.actual_destination");
        $this->metricas($r['metrics'] ?? null, "$p.metrics");
        $this->relogio($r['clock'] ?? null, "$p.clock");
        $this->integridade($r['integrity'] ?? null, "$p.integrity");
        $this->caminho($r['path_evidence'] ?? null, "$p.path_evidence");
        $this->executor($r['executor'] ?? null, "$p.executor");
    }

    /** Agendado tem slot e não tem manual_request_id; manual é o inverso (06 §3 e §12). */
    private function agendamento(array $r, string $p): void
    {
        $tipo = $r['execution_kind'] ?? null;
        $slot = $r['scheduled_at'] ?? null;

        if ($slot !== null) {
            $this->timestamp($slot, "$p.scheduled_at");
        }
        if (array_key_exists('manual_request_id', $r)) {
            $this->uuid($r['manual_request_id'], "$p.manual_request_id");
        }

        if ($tipo === 'scheduled') {
            if ($slot === null) {
                $this->erro("$p.scheduled_at", 'a execução agendada tem de ter o slot');
            }
            if (array_key_exists('manual_request_id', $r)) {
                $this->erro("$p.manual_request_id", 'a execução agendada não tem manual_request_id');
            }
        } elseif ($tipo === 'manual') {
            if (! array_key_exists('manual_request_id', $r)) {
                $this->erro("$p.manual_request_id", 'a execução manual exige manual_request_id');
            }
            if ($slot !== null) {
                $this->erro("$p.scheduled_at", 'a execução manual não tem slot');
            }
        }
    }

    /** INV-03: IPv4 e IPv6 são verificações independentes, sem fallback. O IP efetivo tem de ser da família do assignment. */
    private function destino(mixed $d, mixed $familia, string $p): void
    {
        if ($d === null) {
            return;
        }
        if (! $this->objeto($d)) {
            $this->erro($p, 'deve ser nulo ou um objeto');

            return;
        }
        $this->exato($d, ['ip', 'port', 'hostname'], [], $p);

        $ip = $d['ip'] ?? null;
        $ehV4 = is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $ehV6 = is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (! $ehV4 && ! $ehV6) {
            $this->erro("$p.ip", 'deve ser um endereço IPv4 ou IPv6');
        } elseif ($familia === 'ipv4' && ! $ehV4) {
            $this->erro("$p.ip", 'o assignment é ipv4, e o IP efetivo tem de ser ipv4 (sem fallback entre famílias)');
        } elseif ($familia === 'ipv6' && ! $ehV6) {
            $this->erro("$p.ip", 'o assignment é ipv6, e o IP efetivo tem de ser ipv6 (sem fallback entre famílias)');
        }

        $this->inteiro($d['port'] ?? null, 1, 65535, "$p.port");
        if (($d['hostname'] ?? null) !== null) {
            $this->texto($d['hostname'], "$p.hostname");
        }
    }

    private function metricas(mixed $m, string $p): void
    {
        if (! $this->objeto($m)) {
            $this->erro($p, 'deve ser um objeto');

            return;
        }
        $this->exato($m, [], ['http_status', 'total_duration_ms', 'icmp_sent', 'icmp_received'], $p);
        if (($m['http_status'] ?? null) !== null) {
            $this->inteiro($m['http_status'], 100, 599, "$p.http_status");
        }
        foreach (['total_duration_ms', 'icmp_sent', 'icmp_received'] as $campo) {
            if (($m[$campo] ?? null) !== null) {
                $this->inteiro($m[$campo], 0, null, "$p.$campo");
            }
        }
    }

    /** INV-02: relógio desconhecido nunca vira zero. */
    private function relogio(mixed $c, string $p): void
    {
        if (! $this->objeto($c)) {
            $this->erro($p, 'deve ser um objeto');

            return;
        }
        $this->exato($c, ['status', 'offset_ms', 'reference_kind'], [], $p);
        $this->enum($c['status'] ?? null, Glossario::clockStatus(), "$p.status");
        if (($c['offset_ms'] ?? null) !== null && ! is_int($c['offset_ms'])) {
            $this->erro("$p.offset_ms", 'deve ser nulo ou inteiro');
        }
        if (($c['status'] ?? null) === 'unknown' && ($c['offset_ms'] ?? null) !== null) {
            $this->erro("$p.offset_ms", 'com o relógio desconhecido o offset é nulo, e nunca zero');
        }
        $this->texto($c['reference_kind'] ?? null, "$p.reference_kind");
    }

    private function integridade(mixed $i, string $p): void
    {
        if (! $this->objeto($i)) {
            $this->erro($p, 'deve ser um objeto');

            return;
        }
        $this->exato($i, ['probe_assessment', 'reason_codes', 'local_policy_revision'], [], $p);
        $this->enum($i['probe_assessment'] ?? null, Glossario::integrity(), "$p.probe_assessment");
        $this->texto($i['local_policy_revision'] ?? null, "$p.local_policy_revision");

        $codigos = $i['reason_codes'] ?? null;
        if (! is_array($codigos) || ! array_is_list($codigos)) {
            $this->erro("$p.reason_codes", 'deve ser uma lista');

            return;
        }
        foreach ($codigos as $n => $codigo) {
            $this->enum($codigo, Glossario::reasonCode(), "$p.reason_codes[$n]");
        }
        if (count($codigos) !== count(array_unique(array_map('strval', $codigos)))) {
            $this->erro("$p.reason_codes", 'os itens devem ser únicos');
        }
    }

    private function caminho(mixed $c, string $p): void
    {
        if (! $this->objeto($c)) {
            $this->erro($p, 'deve ser um objeto');

            return;
        }
        $this->exato($c, ['status'], ['profile_id', 'profile_revision', 'mechanism', 'measured_at', 'valid_until',
            'forwarding_observation', 'evidence_refs'], $p);

        $this->enum($c['status'] ?? null, ['valid', 'not_required', 'missing', 'expired', 'contradicted'], "$p.status");
        if (array_key_exists('profile_id', $c)) {
            $this->uuid($c['profile_id'], "$p.profile_id");
        }
        if (array_key_exists('profile_revision', $c)) {
            $this->inteiro($c['profile_revision'], 1, null, "$p.profile_revision");
        }
        if (array_key_exists('mechanism', $c)) {
            $this->enum($c['mechanism'], ['netns_pbr', 'dedicated_interface', 'router_readback', 'traceroute_hop', 'none'], "$p.mechanism");
        }
        foreach (['measured_at', 'valid_until'] as $campo) {
            if (array_key_exists($campo, $c)) {
                $this->timestamp($c[$campo], "$p.$campo");
            }
        }
        if (array_key_exists('forwarding_observation', $c)) {
            $this->enum($c['forwarding_observation'], ['expected_egress', 'blocked_by_policy', 'divergent_egress', 'not_observed'], "$p.forwarding_observation");
        }
        if (array_key_exists('evidence_refs', $c)) {
            if (! is_array($c['evidence_refs']) || ! array_is_list($c['evidence_refs'])) {
                $this->erro("$p.evidence_refs", 'deve ser uma lista');
            } else {
                foreach ($c['evidence_refs'] as $n => $ref) {
                    $this->texto($ref, "$p.evidence_refs[$n]");
                }
            }
        }

        // Evidência de caminho exige mecanismo, aferição e validade, a não ser que o caminho não seja exigido.
        if (($c['status'] ?? null) !== 'not_required' && is_string($c['status'] ?? null)) {
            foreach (['mechanism', 'measured_at', 'valid_until', 'forwarding_observation'] as $campo) {
                if (! array_key_exists($campo, $c)) {
                    $this->erro("$p.$campo", 'obrigatório quando a evidência de caminho não é "not_required"');
                }
            }
        }
    }

    /** C-05: o executor é nativo (D-02). O `blackbox-adapter` do 1.0 não existe mais. */
    private function executor(mixed $e, string $p): void
    {
        if (! $this->objeto($e)) {
            $this->erro($p, 'deve ser um objeto');

            return;
        }
        $this->exato($e, ['name', 'build'], [], $p);
        if (($e['name'] ?? null) !== 'native') {
            $this->erro("$p.name", 'deve ser "native"');
        }
        $this->texto($e['build'] ?? null, "$p.build");
    }
}
