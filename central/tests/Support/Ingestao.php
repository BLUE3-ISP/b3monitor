<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Probes\ProbeEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Apoio dos testes de ingestão: uma sonda cadastrada, um assignment e lotes montados a partir das fixtures do contrato. */
final class Ingestao
{
    /** O "agora" dos testes: logo depois do slot da fixture, dentro da janela de aceitação. */
    public const AGORA = '2026-09-28T15:00:10Z';

    /** @return array{probe: string, token: string, assignment: string} */
    public static function sonda(string $nome = 'sonda-a', string $dominio = 'dom-a', int $geracao = 3): array
    {
        [$probe, $token] = (new ProbeEnrollment)->enroll($nome, $dominio);
        $assignment = (string) Str::uuid();
        DB::table('assignments')->insert([
            'id' => $assignment, 'probe_id' => $probe, 'check_id' => Banco::CHECK, 'ip_family' => 'ipv4',
            'current_generation' => $geracao,
        ]);
        Banco::garantirParticao('2026-09-28');

        return ['probe' => $probe, 'token' => $token, 'assignment' => $assignment];
    }

    /** @return array<string, mixed> */
    public static function resultado(array $sonda, array $sobrepor = []): array
    {
        $base = json_decode(file_get_contents(dirname(__DIR__, 3).'/contracts/fixtures/result/valido-http-ok.json'), true);

        return array_merge($base, [
            'result_id' => (string) Str::uuid(),
            'probe_id' => $sonda['probe'],
            'assignment_id' => $sonda['assignment'],
        ], $sobrepor);
    }

    /** @param  list<array<string, mixed>>  $resultados */
    public static function lote(array $resultados, array $sobrepor = []): array
    {
        return array_merge([
            'batch_id' => (string) Str::uuid(), 'mode' => 'live', 'replay_session_id' => null, 'results' => $resultados,
        ], $sobrepor);
    }
}
