<?php

declare(strict_types=1);

namespace Tests\Support;

use App\State\ClockStatus;
use App\State\Integrity;
use App\State\Observation;
use App\State\Outcome;
use App\State\PlannedPerspective;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Monta cenários do motor a partir de listas curtas, no estilo do 10 §10.
 *
 * O relógio é sempre explícito: nada aqui lê a hora real. Os slots são `T0 + n × 30 s`, e "recente" quer dizer
 * 10 s depois do último slot do cenário.
 */
final class Cenario
{
    public const T0 = '2026-09-28T15:00:00Z';

    public const INTERVALO = 30;

    public static function slot(int $n): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::T0, new DateTimeZone('UTC')))->modify(sprintf('%+d seconds', $n * self::INTERVALO));
    }

    /** Instante de avaliação `$depois` segundos após o slot `$n`. */
    public static function depoisDoSlot(int $n, int $depois = 10): DateTimeImmutable
    {
        return self::slot($n)->modify("+{$depois} seconds");
    }

    /**
     * Perspectivas previstas. `['A' => 'dom-a', 'B' => 'dom-b']`; sem informar, cada uma é o seu domínio.
     *
     * @param  list<string>|array<string,string>  $ids
     * @return list<PlannedPerspective>
     */
    public static function plano(array $ids, int $geracao = 1): array
    {
        $plano = [];
        foreach ($ids as $chave => $valor) {
            $plano[] = is_int($chave)
                ? new PlannedPerspective($valor, 'dom-'.strtolower($valor), $geracao)
                : new PlannedPerspective($chave, $valor, $geracao);
        }

        return $plano;
    }

    /**
     * `['A' => ['pass', 'fail', 'missing']]` vira observações nos slots 0, 1 e 2; `missing` não gera nada.
     * Aceita ainda `'pass:invalid'`, `'pass:clock_unknown'` e `'pass:clock_unsynchronized'` para os casos de
     * integridade e relógio, e `'fail@5'` para fixar o slot de uma amostra fora da sequência.
     *
     * @param  array<string, list<string>>  $entradas
     * @return list<Observation>
     */
    public static function observacoes(array $entradas, int $geracao = 1, int $deslocamento = 0): array
    {
        $lista = [];
        foreach ($entradas as $perspectiva => $simbolos) {
            foreach ($simbolos as $i => $simbolo) {
                if ($simbolo === 'missing') {
                    continue;
                }
                $lista[] = self::observacao($perspectiva, $simbolo, $i + $deslocamento, $geracao);
            }
        }

        return $lista;
    }

    public static function observacao(string $perspectiva, string $simbolo, int $slot, int $geracao = 1): Observation
    {
        if (str_contains($simbolo, '@')) {
            [$simbolo, $fixo] = explode('@', $simbolo);
            $slot = (int) $fixo;
        }
        [$resultado, $modo] = array_pad(explode(':', $simbolo, 2), 2, 'ok');

        return new Observation(
            $perspectiva,
            $geracao,
            self::slot($slot),
            Outcome::from($resultado),
            integrity: $modo === 'invalid' ? Integrity::Invalid : ($modo === 'suspect' ? Integrity::Suspect : Integrity::Valid),
            clock: match ($modo) {
                'clock_unknown' => ClockStatus::Unknown,
                'clock_unsynchronized' => ClockStatus::Unsynchronized,
                default => ClockStatus::Synchronized,
            },
        );
    }
}
