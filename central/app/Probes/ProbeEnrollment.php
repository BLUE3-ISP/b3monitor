<?php

declare(strict_types=1);

namespace App\Probes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cadastro mínimo de uma sonda e da sua credencial (B3; o cadastro administrativo completo é do F0-05).
 *
 * O token é aleatório (256 bits), devolvido UMA vez, e o banco guarda só o SHA-256 dele: quem lê o banco não
 * consegue se passar por uma sonda. Um clone de disco deve receber identidade nova, não reaproveitar esta (04 §2).
 */
final class ProbeEnrollment
{
    public const PREFIXO = 'b3m_p_';

    /** @return array{0: string, 1: string} [id da sonda, token em texto, a ser mostrado uma única vez] */
    public function enroll(string $name, string $observationDomain): array
    {
        $id = (string) Str::uuid();
        $token = self::PREFIXO.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        DB::transaction(function () use ($id, $name, $observationDomain, $token): void {
            DB::table('probes')->insert(['id' => $id, 'name' => $name, 'observation_domain' => $observationDomain]);
            DB::table('probe_credentials')->insert([
                'id' => (string) Str::uuid(),
                'probe_id' => $id,
                'token_hash' => self::hash($token),
            ]);
        });

        return [$id, $token];
    }

    /**
     * Emite uma credencial NOVA para a sonda e REVOGA as anteriores (uma por vez vale). O token novo é devolvido uma única vez.
     */
    public function rotate(string $probeId): string
    {
        $token = self::PREFIXO.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        DB::transaction(function () use ($probeId, $token): void {
            DB::table('probe_credentials')->where('probe_id', $probeId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            DB::table('probe_credentials')->insert([
                'id' => (string) Str::uuid(),
                'probe_id' => $probeId,
                'token_hash' => self::hash($token),
            ]);
        });

        return $token;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
