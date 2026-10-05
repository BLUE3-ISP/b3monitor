<?php

namespace App\Providers;

use App\Ops\Retention;
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
    }
}
