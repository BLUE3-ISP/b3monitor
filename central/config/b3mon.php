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

    // Workers das filas ingest/decide/notify (D-04; B4). Parâmetros iniciais, não SLA.
    'work' => [
        // Quanto um worker pode ficar com um item antes de o reconciliador devolvê-lo à fila.
        'lease_s' => (int) env('B3MON_WORK_LEASE_S', 60),
        // Tentativas até o item parar como `dead` (visível, com o motivo), em vez de voltar para sempre.
        'max_attempts' => (int) env('B3MON_WORK_MAX_ATTEMPTS', 5),
        // Espera depois de uma falha: base × 2^(tentativa-1), limitada pelo teto.
        'backoff_base_s' => (int) env('B3MON_WORK_BACKOFF_BASE_S', 5),
        'backoff_cap_s' => (int) env('B3MON_WORK_BACKOFF_CAP_S', 300),
        // Pausa do worker quando a fila está vazia, e de quanto em quanto ele reconcilia a própria fila.
        'idle_s' => (int) env('B3MON_WORK_IDLE_S', 1),
        'reconcile_every_s' => (int) env('B3MON_WORK_RECONCILE_EVERY_S', 15),
    ],
];
