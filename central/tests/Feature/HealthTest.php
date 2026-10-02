<?php

declare(strict_types=1);

it('responde 200 em /api/v1/health quando o banco atende', function () {
    $resposta = $this->getJson('/api/v1/health');

    $resposta->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('service', 'b3mon-central')
        ->assertJsonPath('checks.database', 'ok')
        ->assertJsonStructure(['status', 'service', 'as_of', 'checks' => ['database']]);

    // as_of é RFC 3339 em UTC (14 §6).
    expect($resposta->json('as_of'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\+00:00|Z)$/');
});

it('serve a API só sob o prefixo versionado /api/v1', function () {
    $this->getJson('/api/health')->assertNotFound();
    $this->getJson('/health')->assertNotFound();
});
