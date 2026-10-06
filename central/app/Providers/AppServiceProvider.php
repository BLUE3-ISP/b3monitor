<?php

namespace App\Providers;

use App\Ops\Retention;
use App\Panel\PanelAccess;
use Livewire\Livewire;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // C-02: as janelas de retenção têm de ser coerentes. Uma configuração incoerente derruba o boot em vez
        // de deixar o expurgo apagar uma amostra que ainda é aceitável.
        Retention::fromConfig();

        // O painel não tem login até o F0-05 (`PanelAccess`: fechado por padrão, só loopback). O Livewire atualiza o componente por
        // POST /livewire-*/update, que NÃO passa pelo middleware da rota original a menos que ele seja persistente: sem isto, a trava só
        // valeria para a primeira carga e o endpoint de atualização ficaria aberto.
        Livewire::addPersistentMiddleware([PanelAccess::class]);
    }
}
