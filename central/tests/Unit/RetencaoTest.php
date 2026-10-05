<?php

declare(strict_types=1);

use App\Ops\Retention;

// C-02 do 2.0 (06 §3 e §9): as janelas de retenção só valem se mantiverem a relação entre si.

it('aceita a base: aceitação 7 d, bruto 14 d e ledger 21 d', function () {
    $r = new Retention(7, 14, 21);

    expect([$r->acceptanceDays, $r->rawDays, $r->ledgerDays])->toBe([7, 14, 21]);
});

it('C-02: recusa retenção bruta igual à aceitação, que era o defeito do 1.0', function () {
    expect(fn () => new Retention(7, 7, 21))->toThrow(InvalidArgumentException::class, 'retenção bruta');
});

it('C-02: o bruto precisa de pelo menos 7 dias além da aceitação, e o ledger de 7 além do bruto', function () {
    expect(fn () => new Retention(7, 13, 21))->toThrow(InvalidArgumentException::class)      // 13 < 7 + 7
        ->and(fn () => new Retention(7, 14, 20))->toThrow(InvalidArgumentException::class, 'ledger');   // 20 < 14 + 7

    // No limite exato vale.
    expect((new Retention(7, 14, 21))->ledgerDays)->toBe(21);
});

it('recusa aceitação menor que um dia', function () {
    expect(fn () => new Retention(0, 14, 21))->toThrow(InvalidArgumentException::class);
});

it('lê a configuração da aplicação, e a configuração padrão é coerente', function () {
    $r = Retention::fromConfig();

    expect($r->rawDays)->toBeGreaterThanOrEqual($r->acceptanceDays + Retention::MARGEM_DIAS)
        ->and($r->ledgerDays)->toBeGreaterThanOrEqual($r->rawDays + Retention::MARGEM_DIAS);
});

it('uma configuração incoerente derruba quem a lê, em vez de deixar o expurgo apagar o que ainda é aceitável', function () {
    config(['b3mon.retention.raw_days' => 7]);

    expect(fn () => Retention::fromConfig())->toThrow(InvalidArgumentException::class);
});
