<?php

declare(strict_types=1);

use App\Ingestion\ProbeResultsController;
use App\Ops\HealthController;
use App\Probes\ProbeConfigController;
use App\Probes\ProbeHeartbeatController;
use Illuminate\Support\Facades\Route;

/*
| API versionada da central: tudo aqui vive sob /api/v1 (bootstrap/app.php).
| Cada rota nasce com o seu item da fila: esta é só a de saúde; a ingestão (POST /probe/results) é o B3.
*/
Route::get('/health', HealthController::class)->name('health');

/*
| Ingestão de resultados pela sonda (06 §6 e §7). Só a credencial de SONDA entra aqui: ela não acessa APIs humanas.
*/
Route::post('/probe/results', ProbeResultsController::class)->middleware('probe.auth')->name('probe.results');

/*
| A configuração desta sonda e o autodiagnóstico dela (04 §8; 06 §4). Mesma credencial da ingestão, e a identidade vem SEMPRE dela.
*/
Route::get('/probe/config', ProbeConfigController::class)->middleware('probe.auth')->name('probe.config');
Route::post('/probe/heartbeat', ProbeHeartbeatController::class)->middleware('probe.auth')->name('probe.heartbeat');
