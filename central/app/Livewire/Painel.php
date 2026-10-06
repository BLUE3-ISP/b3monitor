<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Panel\PanelData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * O painel mínimo (B9; 08 §5). Uma tela só, que se atualiza sozinha (`wire:poll`) e LÊ: estados atuais, sondas, incidentes e avisos.
 * Quem decide é o motor; ver `App\Panel\PanelData` para a regra de leitura ("nunca verde antigo").
 */
#[Layout('layouts.painel')]
#[Title('b3monitor — painel')]
final class Painel extends Component
{
    public function render(PanelData $dados): View
    {
        return view('livewire.painel', $dados->snapshot(now()->toDateTimeImmutable()) + [
            'rotulo' => (string) config('b3mon.panel.environment_label'),
        ]);
    }
}
