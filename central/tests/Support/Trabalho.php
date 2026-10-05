<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Ingestion\IngestResults;
use App\Work\Fila;
use App\Work\Handler;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/** Apoio dos testes de worker: itens pendentes em cada fila e um relógio de mentira. */
final class Trabalho
{
    public const T0 = '2026-09-28T15:00:10Z';

    public static function t(string $mais = '+0 seconds'): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::T0))->modify($mais);
    }

    /**
     * Um item pendente de `$fila`, devolvido como linha. `ingest` nasce pela ingestão de verdade (recibo + resultado
     * + trabalho, na mesma transação); as outras são inseridas.
     */
    public static function pendente(Fila $fila, ?string $check = null): stdClass
    {
        $id = match ($fila) {
            Fila::Ingest => self::ingerir(),
            Fila::Decide => self::pedirDecisao($check ?? (string) Str::uuid()),
            Fila::Notify => self::notificar(),
        };

        // O `available_at` padrão é o relógio REAL do banco; os testes vivem no relógio de mentira, então o item
        // nasce disponível desde antes do T0.
        DB::table($fila->table())->where('id', $id)->update(['available_at' => '2026-09-28T15:00:00Z']);

        return DB::table($fila->table())->where('id', $id)->first();
    }

    private static function ingerir(): int
    {
        $sonda = Ingestao::sonda('sonda-'.Str::random(6), 'dom-'.Str::random(4));
        $r = Ingestao::resultado($sonda);
        $resp = app(IngestResults::class)->handle([$r], self::t());
        expect($resp[0]['status'])->toBe('accepted');

        return (int) DB::table('ingestion_work')->where('result_id', $r['result_id'])->value('id');
    }

    private static function pedirDecisao(string $check): int
    {
        return (int) DB::table('decision_work')->insertGetId(['check_id' => $check, 'ip_family' => 'ipv4']);
    }

    private static function notificar(): int
    {
        $incidente = (string) Str::uuid();
        DB::table('incidents')->insert([
            'id' => $incidente, 'check_id' => Banco::CHECK, 'ip_family' => 'ipv4', 'incident_class' => 'service',
            'severity' => 'critical', 'scope' => 'all_observed', 'correlation_key' => 'k-'.$incidente,
            'observed_start_at' => '2026-09-28T15:00:00Z', 'confirmed_at' => '2026-09-28T15:01:30Z',
        ]);

        return (int) DB::table('notification_outbox')->insertGetId([
            'incident_id' => $incidente, 'decision_version' => 1, 'reason' => 'opened', 'channel' => 'dev',
            'recipient' => 'lab', 'payload' => '{}',
        ]);
    }

    /** Um handler que conta as chamadas e, se quiser, falha ou age. */
    public static function handler(?callable $acao = null): Handler
    {
        return new class($acao) implements Handler
        {
            /** @var list<int> */
            public array $itens = [];

            public function __construct(private readonly mixed $acao) {}

            public function handle(stdClass $item): void
            {
                $this->itens[] = (int) $item->id;
                if ($this->acao !== null) {
                    ($this->acao)($item);
                }
            }
        };
    }
}
