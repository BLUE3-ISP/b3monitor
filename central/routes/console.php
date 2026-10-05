<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Lease vencida volta para a fila (B4). Precisa do cron `* * * * * php artisan schedule:run` (deploy/systemd/README.md).
Schedule::command('b3mon:work-reconcile')->everyMinute()->withoutOverlapping();

// Partições diárias de `results` (06 §9): sem elas, a ingestão passa a responder `retryable` quando o último dia acaba.
Schedule::command('b3mon:partitions')->hourly()->withoutOverlapping();
