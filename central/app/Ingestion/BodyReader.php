<?php

declare(strict_types=1);

namespace App\Ingestion;

use Illuminate\Http\Request;
use Throwable;

/**
 * Lê o corpo de uma requisição de ingestão respeitando os limites de TRANSPORTE (06 §6; C-19).
 *
 * Esses limites não cabem em schema, então o servidor os impõe:
 *   - 4 MiB na requisição, medidos nos bytes que chegaram;
 *   - 16 MiB depois de descomprimir `Content-Encoding: gzip`. O tamanho comprimido NÃO previne uma bomba de
 *     descompressão (uma sequência de zeros de 17 MiB comprime para dezenas de KiB), então a expansão é contada
 *     enquanto acontece, em pedaços pequenos, e aborta ao passar do limite, sem materializar o excedente.
 */
final class BodyReader
{
    public const MAX_BODY_BYTES = 4 * 1024 * 1024;

    public const MAX_INFLATED_BYTES = 16 * 1024 * 1024;

    /** Pedaço de entrada por passo: pequeno, para que um passo nunca descomprima muito mais que o limite. */
    private const PEDACO = 4096;

    /**
     * @throws BodyTooLarge
     * @throws BadBody
     */
    public function read(Request $request): string
    {
        $bruto = $request->getContent();
        if (strlen($bruto) > self::MAX_BODY_BYTES) {
            throw new BodyTooLarge('O corpo passa de '.(self::MAX_BODY_BYTES / 1048576).' MiB.');
        }

        $codificacao = strtolower(trim((string) $request->header('Content-Encoding', '')));

        return match ($codificacao) {
            '', 'identity' => $bruto,
            'gzip' => $this->descomprimir($bruto),
            default => throw new BadBody('Content-Encoding não suportado; use gzip ou nenhum.'),
        };
    }

    private function descomprimir(string $bruto): string
    {
        $contexto = inflate_init(ZLIB_ENCODING_GZIP);
        if ($contexto === false) {
            throw new BadBody('Não foi possível iniciar a descompressão.');
        }

        $saida = '';
        $tamanho = strlen($bruto);
        $terminou = false;

        try {
            for ($i = 0; $i < $tamanho && ! $terminou; $i += self::PEDACO) {
                $saida .= inflate_add($contexto, substr($bruto, $i, self::PEDACO), ZLIB_NO_FLUSH);
                if (strlen($saida) > self::MAX_INFLATED_BYTES) {
                    throw new BodyTooLarge('O corpo expande para mais de '.(self::MAX_INFLATED_BYTES / 1048576).' MiB.');
                }
                // O fluxo pode acabar DENTRO do laço. Perguntar de novo com ZLIB_FINISH depois do fim devolve outro
                // status, e um gzip válido pareceria truncado: por isso o término é checado a cada passo.
                $terminou = inflate_get_status($contexto) === ZLIB_STREAM_END;
            }
            if (! $terminou) {
                $saida .= inflate_add($contexto, '', ZLIB_FINISH);
                $terminou = inflate_get_status($contexto) === ZLIB_STREAM_END;
            }
        } catch (BodyTooLarge $e) {
            throw $e;
        } catch (Throwable) {
            throw new BadBody('O gzip está corrompido.');
        }

        // Um fluxo gzip tem de TERMINAR: se a entrada acabou antes, ele foi truncado.
        if (! $terminou) {
            throw new BadBody('O gzip está truncado.');
        }
        // E não pode sobrar lixo depois do fim: um corpo que traz bytes extras não é o que a sonda mediu.
        if (inflate_get_read_len($contexto) < $tamanho) {
            throw new BadBody('O gzip traz bytes depois do fim do fluxo.');
        }
        if (strlen($saida) > self::MAX_INFLATED_BYTES) {
            throw new BodyTooLarge('O corpo expande para mais de '.(self::MAX_INFLATED_BYTES / 1048576).' MiB.');
        }

        return $saida;
    }
}
