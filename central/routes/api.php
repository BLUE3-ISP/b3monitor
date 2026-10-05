<?php

declare(strict_types=1);

use App\Ingestion\ProbeResultsController;
use App\Ops\HealthController;
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
