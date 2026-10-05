<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Probes\ProbeEnrollment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Semeia o LABORATÓRIO: duas sondas de domínios diferentes, o check do alvo de laboratório e um assignment de cada sonda para ele
 * (B8 da fila; o cadastro administrativo de verdade é o F0-05).
 *
 * ⚠️ Só roda no laboratório, e duas travas dizem isso: a variável `B3MON_LAB=1` e um banco cujo nome termina em `_lab`. Sem as duas,
 * recusa. Um comando que cria credenciais de sonda não pode rodar por engano num banco de verdade.
 *
 * Os tokens vão para ARQUIVOS `0600` em `--out` (um diretório por sonda), e só o SHA-256 fica no banco. Idempotente: rodar de novo não
 * troca sonda nem credencial que já tem token em disco; se o arquivo do token sumiu, emite uma credencial NOVA e revoga as antigas.
 *
 * Os "domínios de observação" são `lab-dom-a` e `lab-dom-b` e SÃO UMA SIMULAÇÃO: duas sondas na mesma máquina não são diversidade de
 * rede (02 §6). Servem para exercitar o quórum de dois domínios, e nada que passe aqui homologa a diversidade real.
 */
final class LabSeedCommand extends Command
{
    protected $signature = 'b3mon:lab-seed
        {--out= : diretório onde ficam os tokens (obrigatório)}
        {--target-url=http://127.0.0.1:18080/health : o alvo de laboratório}
        {--interval=5 : intervalo do check, em segundos}';

    protected $description = 'Semeia o laboratório: duas sondas, o check do alvo e os assignments (só com B3MON_LAB=1 e banco *_lab)';

    private const CHECK = '22222222-2222-4222-8222-222222222222';

    public function handle(ProbeEnrollment $enrollment): int
    {
        $banco = (string) config('database.connections.'.config('database.default').'.database');
        if (getenv('B3MON_LAB') !== '1' || ! str_ends_with($banco, '_lab')) {
            $this->error("recusado: este comando só roda no laboratório (B3MON_LAB=1 e banco terminado em _lab; o banco é `{$banco}`).");

            return self::FAILURE;
        }
        $out = (string) $this->option('out');
        if ($out === '') {
            $this->error('--out é obrigatório');

            return self::INVALID;
        }
        $intervalo = (int) $this->option('interval');
        $this->callSilently('b3mon:partitions');

        DB::table('checks')->updateOrInsert(['id' => self::CHECK], [
            'name' => 'lab-target', 'kind' => 'http',
            'target' => json_encode(['url' => (string) $this->option('target-url'), 'method' => 'GET', 'allowed_status_codes' => [200], 'follow_redirects' => false]),
            'interval_s' => $intervalo, 'timeout_ms' => max(500, min(2000, $intervalo * 1000 - 500)), 'start_tolerance_ms' => min(1000, $intervalo * 200),
            'schedule_epoch' => '2026-10-01T00:00:00Z', 'policy_revision' => 'critical-v1',
        ]);

        foreach (['lab-a' => 'lab-dom-a', 'lab-b' => 'lab-dom-b'] as $nome => $dominio) {
            $sonda = DB::table('probes')->where('name', $nome)->first();
            $dir = rtrim($out, '/')."/{$nome}";
            $arquivo = "{$dir}/token";

            if ($sonda === null) {
                [$id, $token] = $enrollment->enroll($nome, $dominio);
                $this->gravarToken($dir, $arquivo, $token);
                $this->line("sonda {$nome}: criada ({$dominio})");
            } else {
                $id = $sonda->id;
                if (! is_file($arquivo)) {
                    $token = $enrollment->rotate($id);
                    $this->gravarToken($dir, $arquivo, $token);
                    $this->line("sonda {$nome}: o token em disco sumiu; credencial NOVA emitida e as antigas revogadas");
                } else {
                    $this->line("sonda {$nome}: já existe");
                }
            }
            file_put_contents("{$dir}/probe_id", $id."\n");

            if (! DB::table('assignments')->where('probe_id', $id)->where('check_id', self::CHECK)->where('ip_family', 'ipv4')->exists()) {
                DB::table('assignments')->insert([
                    'id' => (string) Str::uuid(), 'probe_id' => $id, 'check_id' => self::CHECK, 'ip_family' => 'ipv4', 'current_generation' => 1,
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function gravarToken(string $dir, string $arquivo, string $token): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException("não foi possível criar {$dir}");
        }
        file_put_contents($arquivo, $token."\n");
        chmod($arquivo, 0600);
    }
}
