<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * A suíte só roda no banco b3mon_test do PostgreSQL de desenvolvimento (porta 54329).
     *
     * O RefreshDatabase recria o banco a cada teste, e apontar isso para o b3mon_dev, ou para
     * qualquer outro banco, apaga dados. A verificação roda aqui porque é o ponto em que a
     * configuração já foi carregada (refreshApplication) e os traits de teste, entre eles o
     * RefreshDatabase, ainda não rodaram.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $conexao = (string) config('database.default');
        $banco = (string) config("database.connections.{$conexao}.database");
        $porta = (string) config("database.connections.{$conexao}.port");

        if ($conexao !== 'pgsql' || $banco !== 'b3mon_test' || $porta !== '54329') {
            throw new RuntimeException(
                "Recusado: a suíte só roda em pgsql / b3mon_test / porta 54329, e a conexão é "
                ."{$conexao} / {$banco} / {$porta}. Nada foi apagado."
            );
        }
    }
}
