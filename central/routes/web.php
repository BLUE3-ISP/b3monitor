<?php

use App\Livewire\Painel;
use Illuminate\Support\Facades\Route;

/*
| O painel mínimo (B9). Sem autenticação ainda (F0-05): fechado por padrão e só loopback quando ligado (`App\Panel\PanelAccess`).
*/
Route::get('/', fn () => redirect()->route('painel'));
Route::get('/painel', Painel::class)->middleware('painel')->name('painel');
