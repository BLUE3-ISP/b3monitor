<?php

declare(strict_types=1);

namespace App\Ingestion;

/**
 * O hash de conteúdo de um resultado (06 §3).
 *
 * O resultado é imutável, então o hash cobre o resultado inteiro, em forma canônica: chaves ordenadas, para que
 * a ordem em que a sonda serializou os campos não mude o hash. É ele que separa um REENVIO (mesmo `result_id`,
 * mesmo hash: `duplicate`) de um CONFLITO (mesmo `result_id` ou mesma chave natural com outro conteúdo).
 */
final class ResultHasher
{
    /** @param array<string, mixed> $resultado */
    public function hash(array $resultado): string
    {
        return hash('sha256', $this->canonico($resultado));
    }

    /** @param array<string, mixed> $resultado */
    public function canonico(array $resultado): string
    {
        return json_encode(
            $this->ordenar($resultado),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private function ordenar(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }
        $filhos = array_map(fn ($v) => $this->ordenar($v), $valor);
        if (array_is_list($filhos)) {
            return $filhos;   // a ordem de uma lista é conteúdo
        }
        ksort($filhos, SORT_STRING);

        return $filhos;
    }
}
