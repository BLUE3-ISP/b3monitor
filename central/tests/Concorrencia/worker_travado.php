<?php

declare(strict_types=1);

/*
| Um worker que TRAVA no meio do processamento, para o teste matá-lo com SIGKILL (processo de verdade, não simulação).
| Uso (só pelo teste): php worker_travado.php <arquivo-de-pronto> <owner>
|
| Faz exatamente o que o worker faz: pega o item, abre a transação, tranca a linha, grava um efeito, e então
| ESPERA, com a transação aberta e o efeito ainda não commitado. Se o teste o mata aqui, o PostgreSQL desfaz tudo.
*/

use App\Work\Fila;
use App\Work\Handler;
use App\Work\WorkQueue;
use App\Work\Worker;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Trava de segurança: este script grava e trava um item de fila. Só roda no banco de teste.
if (DB::connection()->getDatabaseName() !== 'b3mon_test') {
    fwrite(STDERR, "recusado: este script só roda no b3mon_test\n");
    exit(2);
}

[, $pronto, $owner] = $argv;

$handler = new class($pronto) implements Handler
{
    public function __construct(private readonly string $pronto) {}

    public function handle(stdClass $item, DateTimeImmutable $now): void
    {
        DB::insert('insert into efeitos_teste (item_id, quem) values (?, ?)', [$item->id, 'travado']);
        file_put_contents($this->pronto, 'pronto');
        sleep(120);
    }
};

(new Worker(WorkQueue::fromConfig(), leaseS: 30))->step(Fila::Ingest, $handler, $owner, CarbonImmutable::now('UTC'));
