<?php

declare(strict_types=1);

/*
| Configuração própria do b3monitor. Os valores são parâmetros iniciais de homologação, não SLA (03 §12).
|
| Retenção (C-02 do pacote 2.0): a retenção bruta NÃO pode ser igual à aceitação histórica, senão a borda de
| uma partição diária remenda o expurgo. A relação é VALIDADA ao subir a aplicação (App\Ops\Retention): uma
| configuração incoerente derruba o boot em vez de apagar dado ainda aceitável.
*/
return [
    'retention' => [
        // Idade máxima de um resultado que a central aceita e pode reconciliar.
        'acceptance_days' => (int) env('B3MON_ACCEPTANCE_DAYS', 7),
        // Quanto tempo a amostra bruta fica. Tem de ser pelo menos aceitação + 7 dias (base: 14).
        'raw_days' => (int) env('B3MON_RAW_DAYS', 14),
        // Quanto tempo o ledger de receipts fica. Tem de ser pelo menos bruto + 7 dias (base: 21).
        'ledger_days' => (int) env('B3MON_LEDGER_DAYS', 21),
    ],

    // Partições diárias de `results` criadas com antecedência (06 §9).
    'partitions' => [
        'days_ahead' => (int) env('B3MON_PARTITION_DAYS_AHEAD', 7),
        'days_behind' => (int) env('B3MON_PARTITION_DAYS_BEHIND', 1),
    ],
];
