<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Configuração da suíte
|--------------------------------------------------------------------------
| Todo teste de Feature estende o TestCase do Laravel e recria o banco a cada teste. ISSO
| APAGA DADOS: a trava que impede de apagar o banco errado está no TestCase (refreshApplication),
| porque é lá que a configuração já foi carregada e o RefreshDatabase ainda não rodou.
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');
