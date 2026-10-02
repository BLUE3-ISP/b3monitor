<?php

declare(strict_types=1);

use App\Ops\HealthController;
use Illuminate\Support\Facades\Route;

/*
| API versionada da central: tudo aqui vive sob /api/v1 (bootstrap/app.php).
| Cada rota nasce com o seu item da fila: esta é só a de saúde; a ingestão (POST /results) é o B3.
*/
Route::get('/health', HealthController::class)->name('health');
