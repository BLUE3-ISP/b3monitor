<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Decision\Decider;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Apoio dos testes de decisão: duas sondas de DOMÍNIOS diferentes medindo o mesmo check (o quórum do perfil crítico
 * exige dois domínios), amostras em slots de 30 s a partir de `Cenario::T0`, e um relógio de mentira explícito.
 */
final class Decisao
{
    /** @return array{a: array{probe: string, assignment: string}, b: array{probe: string, assignment: string}} */
    public static function sondas(): array
    {
        $a = Ingestao::sonda('sonda-a', 'dom-a');
        $b = Ingestao::sonda('sonda-b', 'dom-b');

        return ['a' => $a, 'b' => $b];
    }

    public static function slot(int $n): DateTimeImmutable
    {
        return Cenario::slot($n);
    }

    /** Insere o resultado AGENDADO do slot `$n` (a ingestão tem os próprios testes; aqui o que importa é a decisão). */
    public static function amostra(array $sonda, int $n, string $outcome = 'pass', array $sobrepor = []): void
    {
        $slot = self::slot($n);
        Banco::resultado(array_merge([
            'probe_id' => $sonda['probe'],
            'assignment_id' => $sonda['assignment'],
            'scheduled_at' => $slot->format('Y-m-d\TH:i:s\Z'),
            'started_at' => $slot->modify('+2 seconds')->format('Y-m-d\TH:i:s\Z'),
            'finished_at' => $slot->modify('+3 seconds')->format('Y-m-d\TH:i:s\Z'),
            'received_at' => $slot->modify('+4 seconds')->format('Y-m-d\TH:i:s\Z'),
            'outcome' => $outcome,
            'reason_code' => $outcome === 'pass' ? 'http_assertions_passed' : 'target_timeout',
            'result_id' => (string) \Illuminate\Support\Str::uuid(),
        ], $sobrepor));
    }

    /** Amostras `$de..$ate` (slots, inclusive) das duas sondas, com o mesmo resultado. */
    public static function ambas(array $sondas, int $de, int $ate, string $outcome): void
    {
        for ($n = $de; $n <= $ate; $n++) {
            self::amostra($sondas['a'], $n, $outcome);
            self::amostra($sondas['b'], $n, $outcome);
        }
    }

    /** Decide o check de teste no instante `$depois` s depois do slot `$n`. */
    public static function decidir(int $n, int $depois = 10): array
    {
        return app(Decider::class)->decide(Banco::CHECK, 'ipv4', self::slot($n)->modify("+{$depois} seconds"));
    }

    public static function incidentes(): array
    {
        return DB::table('incidents')->orderBy('created_at')->orderBy('id')->get()->all();
    }

    public static function outbox(): array
    {
        return DB::table('notification_outbox')->orderBy('id')->get()->all();
    }
}
