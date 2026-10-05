<?php

declare(strict_types=1);

namespace App\Ingestion;

use DateTimeImmutable;

/**
 * As primitivas que todo validador de contrato desta central usa (`BatchValidator`, `HeartbeatValidator`): objeto exato
 * (`additionalProperties: false`), UUID, instante RFC 3339 em UTC, inteiro com faixa, texto curto e enum do glossário.
 *
 * As mensagens dizem o CAMINHO e a REGRA, nunca o valor recebido: uma sonda não ganha o seu próprio dado de volta.
 */
trait ValidaContrato
{
    private const MAX_ERROS = 20;

    private const UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    private const TIMESTAMP = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d{1,6})?Z$/';

    /** @var list<string> */
    private array $erros = [];

    // -----------------------------------------------------------------------------------------------------------
    // Primitivas
    // -----------------------------------------------------------------------------------------------------------

    /** Objeto JSON: um array associativo. `{}` chega como `[]`, então o vazio também vale. */
    private function objeto(mixed $v): bool
    {
        return is_array($v) && ($v === [] || ! array_is_list($v));
    }

    /**
     * `additionalProperties: false` com os campos obrigatórios e os opcionais.
     *
     * @param  array<string, mixed>  $objeto
     * @param  list<string>  $obrigatorios
     * @param  list<string>  $opcionais
     */
    private function exato(array $objeto, array $obrigatorios, array $opcionais, string $p): void
    {
        foreach ($obrigatorios as $campo) {
            if (! array_key_exists($campo, $objeto)) {
                $this->erro("$p.$campo", 'campo obrigatório ausente');
            }
        }
        $permitidos = [...$obrigatorios, ...$opcionais];
        foreach (array_keys($objeto) as $campo) {
            if (! in_array((string) $campo, $permitidos, true)) {
                $this->erro("$p.$campo", 'campo não permitido');
            }
        }
    }

    private function uuid(mixed $v, string $p): void
    {
        if (! is_string($v) || preg_match(self::UUID, $v) !== 1) {
            $this->erro($p, 'deve ser um UUID');
        }
    }

    /** RFC 3339 em UTC, sempre com Z (14 §6): fuso local nunca atravessa o contrato. */
    private function timestamp(mixed $v, string $p): void
    {
        if (! is_string($v) || preg_match(self::TIMESTAMP, $v, $m) !== 1) {
            $this->erro($p, 'deve ser um instante RFC 3339 em UTC, terminado em Z');

            return;
        }
        $base = substr($v, 0, 19);
        $lido = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $base);
        if ($lido === false || $lido->format('Y-m-d\TH:i:s') !== $base) {
            $this->erro($p, 'não é uma data e hora existente');
        }
    }

    private function inteiro(mixed $v, ?int $min, ?int $max, string $p): void
    {
        if (! is_int($v) || ($min !== null && $v < $min) || ($max !== null && $v > $max)) {
            $faixa = $min !== null && $max !== null ? "entre $min e $max" : ($min !== null ? "maior ou igual a $min" : 'um inteiro');
            $this->erro($p, "deve ser um inteiro $faixa");
        }
    }

    private function texto(mixed $v, string $p): void
    {
        if (! is_string($v) || mb_strlen($v) < 1 || mb_strlen($v) > 128) {
            $this->erro($p, 'deve ser um texto de 1 a 128 caracteres');
        }
    }

    /** @param  list<string>  $permitidos */
    private function enum(mixed $v, array $permitidos, string $p): void
    {
        if (! is_string($v) || ! in_array($v, $permitidos, true)) {
            $this->erro($p, 'valor fora do glossário');
        }
    }

    private function erro(string $caminho, string $regra): void
    {
        if (! $this->cheio()) {
            $this->erros[] = "$caminho: $regra";
        }
    }

    private function cheio(): bool
    {
        return count($this->erros) >= self::MAX_ERROS;
    }
}
