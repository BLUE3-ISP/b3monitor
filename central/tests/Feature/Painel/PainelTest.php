<?php

declare(strict_types=1);

use App\Livewire\Painel;
use App\Panel\PanelAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Banco;
use Tests\Support\Decisao;

/*
| B9: o painel LÊ e nunca decide (08 §5). O que se prova aqui é a regra de leitura que importa: nunca verde antigo; cobertura e
| integridade separadas da saúde; incidente aberto e fechado; sonda calada; aviso simulado; e que o painel é FECHADO por padrão
| (sem autenticação até o F0-05).
*/

const VALIDO_ATE = '2026-09-28T15:03:02Z';

function painelLigado(): void
{
    config(['b3mon.panel.enabled' => true]);
}

/** Um estado `up` válido até 15:03:02 (a coluna de validade que o motor grava). */
function estadoUp(array $sobrepor = []): void
{
    Banco::check();
    Banco::estado($sobrepor);
}

function agora(string $iso): void
{
    test()->travelTo(CarbonImmutable::parse($iso));
}

describe('acesso', function () {
    it('é FECHADO por padrão: 404, como se a rota não existisse', function () {
        $this->get('/painel')->assertNotFound();
        expect(config('b3mon.panel.enabled'))->toBeFalse();
    });

    it('ligado, não atende quem não vem do loopback (403), e X-Forwarded-For não o transforma em local', function () {
        painelLigado();

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->get('/painel')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->get('/painel', ['X-Forwarded-For' => '127.0.0.1'])->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/painel')->assertForbidden();
    });

    it('ligado, atende o loopback v4 e v6', function () {
        painelLigado();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/painel')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '::1'])->get('/painel')->assertOk();
    });

    it('a trava vale para a ATUALIZAÇÃO do Livewire também, não só para a primeira carga', function () {
        // O Livewire atualiza o componente por POST; um middleware que não é persistente ali deixaria o painel "fechado" aberto.
        expect(Livewire::getPersistentMiddleware())->toContain(PanelAccess::class);
    });

    it('a raiz só encaminha ao painel (e ele é quem decide)', function () {
        $this->get('/')->assertRedirect('/painel');
    });
});

describe('estados', function () {
    beforeEach(fn () => painelLigado());

    it('sem nenhum estado: diz que não há o que mostrar, em vez de um verde por omissão', function () {
        Livewire::test(Painel::class)
            ->assertSeeHtml('data-teste="estados-vazio"')
            ->assertDontSeeHtml('data-saude="up"');
    });

    it('estado válido: mostra a saúde, o `as_of`, o `valid_until` e a cobertura', function () {
        estadoUp();
        agora('2026-09-28T15:01:30Z');

        Livewire::test(Painel::class)
            ->assertSeeHtml('data-saude="up"')
            ->assertSee('em pé')
            ->assertSee('plena')
            ->assertSee('3/3 domínios')
            // Brasília é UTC−3: 15:01:00Z é 12:01:00; 15:03:02Z é 12:03:02.
            ->assertSee('12:01:00')
            ->assertSee('12:03:02')
            ->assertDontSeeHtml('data-teste="expirado"');
    });

    it('NUNCA verde antigo: passada a validade, a saúde exibida é "desconhecido", mesmo com o banco ainda dizendo `up`', function () {
        estadoUp();
        agora('2026-09-28T15:03:03Z');   // um segundo depois da validade, e o reavaliador ainda não rodou

        expect(DB::table('current_states')->value('health'))->toBe('up');

        Livewire::test(Painel::class)
            ->assertSeeHtml('data-saude="unknown"')
            ->assertDontSeeHtml('data-saude="up"')
            ->assertSeeHtml('data-teste="expirado"')
            // A abrangência também é DESCONHECIDA: o `none` ("nenhuma") do banco era a do último estado confirmado.
            ->assertSeeHtml('data-teste="abrangencia">desconhecida<')
            ->assertDontSeeHtml('data-teste="abrangencia">nenhuma<')
            ->assertSee('evidência')
            ->assertSee('histórico, não o presente');
    });

    it('no último instante da validade ainda vale; um segundo depois, não (a fronteira)', function () {
        estadoUp();

        agora(VALIDO_ATE);
        Livewire::test(Painel::class)->assertSeeHtml('data-saude="up"');

        agora('2026-09-28T15:03:03Z');
        Livewire::test(Painel::class)->assertSeeHtml('data-saude="unknown"');
    });

    it('estado sem validade e que não é unknown também não é verde: nunca houve evidência elegível', function () {
        estadoUp(['valid_until' => null, 'last_valid_observation_at' => null]);
        agora('2026-09-28T15:01:30Z');

        Livewire::test(Painel::class)->assertSeeHtml('data-saude="unknown"')->assertDontSeeHtml('data-saude="up"');
    });

    it('saúde, cobertura e integridade são COLUNAS SEPARADAS: verde com cobertura reduzida não é verde com cobertura plena', function () {
        estadoUp(['coverage' => 'reduced', 'valid_domains' => 1, 'planned_domains' => 3]);
        agora('2026-09-28T15:01:30Z');

        Livewire::test(Painel::class)
            ->assertSeeHtml('data-saude="up"')
            ->assertSeeHtml('data-teste="cobertura"')
            ->assertSee('reduzida')
            ->assertSee('1/3 domínios')
            ->assertDontSee('plena');
    });

    it('integridade comprometida de uma perspectiva aparece, e não vira "falha do alvo"', function () {
        $s = Decisao::sondas();
        Banco::check();
        Banco::estado([
            'health' => 'up',
            'evaluation' => json_encode(['perspectives' => [[
                'id' => $s['a']['assignment'], 'domain' => 'dom-a', 'health' => 'unknown', 'pending' => 'none',
                'last_eligible_slot' => null, 'reason' => 'integrity_not_valid',
            ]]]),
        ]);
        agora('2026-09-28T15:01:30Z');

        Livewire::test(Painel::class)
            ->assertSee('comprometida')
            // O nome da sonda DENTRO da perspectiva (a tabela de sondas também o mostra, por isso o HTML inteiro), não o uuid.
            ->assertSeeHtml('<strong>sonda-a</strong> (dom-a)')
            ->assertSee('integrity_not_valid');
    });

    it('relógio não confiável de uma perspectiva é dito', function () {
        $s = Decisao::sondas();
        Banco::check();
        Banco::estado(['evaluation' => json_encode(['perspectives' => [[
            'id' => $s['b']['assignment'], 'domain' => 'dom-b', 'health' => 'unknown', 'pending' => 'none',
            'last_eligible_slot' => null, 'reason' => 'clock_untrusted',
        ]]])]);
        agora('2026-09-28T15:01:30Z');

        Livewire::test(Painel::class)->assertSee('relógio não confiável');
    });

    it('um estado derivado do motor de verdade: três falhas confirmam `down`, e o painel mostra fora e o incidente', function () {
        Banco::check();
        $s = Decisao::sondas();
        Decisao::ambas($s, 0, 2, 'fail');
        Decisao::decidir(2);
        agora(Decisao::slot(2)->modify('+20 seconds')->format('c'));

        Livewire::test(Painel::class)
            ->assertSeeHtml('data-saude="down"')
            ->assertSee('fora')
            ->assertSeeHtml('data-teste="incidente"')
            ->assertSeeHtml('data-aberto="1"');
    });
});

describe('incidentes', function () {
    beforeEach(fn () => painelLigado());

    it('sem incidente: diz que não há', function () {
        Livewire::test(Painel::class)->assertSeeHtml('data-teste="incidentes-vazio"');
    });

    it('lista o aberto antes do fechado, com início observado, confirmação e fim confirmado', function () {
        Banco::check();
        Banco::incidente([
            'correlation_key' => 'service:antigo', 'observed_start_at' => '2026-09-28T10:00:00Z', 'confirmed_at' => '2026-09-28T10:01:00Z',
            'observed_end_at' => '2026-09-28T10:10:00Z', 'confirmed_end_at' => '2026-09-28T10:11:00Z',
        ]);
        Banco::incidente([
            'correlation_key' => 'service:aberto', 'observed_start_at' => '2026-09-28T14:00:00Z', 'confirmed_at' => '2026-09-28T14:01:00Z',
        ]);
        agora('2026-09-28T14:05:00Z');

        $html = Livewire::test(Painel::class)->html();

        expect(substr_count($html, 'data-teste="incidente"'))->toBe(2);
        // O aberto vem primeiro, mesmo sendo o mais novo E o fechado ter `confirmed_at` mais antigo.
        expect(strpos($html, 'data-aberto="1"'))->toBeLessThan(strpos($html, 'data-aberto="0"'));

        Livewire::test(Painel::class)
            ->assertSee('aberto')->assertSee('fechado')
            ->assertSee('11:00:00')     // início observado do aberto (14:00Z), em Brasília
            ->assertSee('(em curso)')
            ->assertSee('critical' === 'critical' ? 'crítica' : '');
    });

    it('a duração do aberto corre até agora; a do fechado para no fim confirmado', function () {
        Banco::check();
        Banco::incidente(['correlation_key' => 'service:a', 'observed_start_at' => '2026-09-28T14:00:00Z', 'confirmed_at' => '2026-09-28T14:01:00Z']);
        agora('2026-09-28T14:07:00Z');

        Livewire::test(Painel::class)->assertSee('7 min (em curso)');
    });
});

describe('sondas', function () {
    beforeEach(fn () => painelLigado());

    function heartbeat(string $probe, string $recebido, array $payload = []): void
    {
        DB::table('probe_status')->insert([
            'probe_id' => $probe, 'received_at' => $recebido, 'sent_at' => $recebido, 'clock_skew_ms' => 120, 'heartbeats' => 3,
            'bundle_revision' => 2,
            'payload' => json_encode(array_replace_recursive([
                'build' => ['version' => '0.1.24', 'commit' => 'abc'],
                'uptime_s' => 100,
                'spool' => ['rows' => 4, 'bytes' => 100, 'oldest_age_s' => 12, 'dropped' => 0],
                'clock' => ['status' => 'synchronized', 'offset_ms' => 3, 'reference_kind' => 'lab_declared'],
            ], $payload)),
        ]);
    }

    it('sonda que nunca mandou heartbeat está "calada", não "boa"', function () {
        Decisao::sondas();

        Livewire::test(Painel::class)->assertSeeHtml('data-teste="sonda-calada"')->assertSee('nunca falou');
    });

    it('heartbeat recente é saudável; velho demais fica calado, com a idade', function () {
        $s = Decisao::sondas();
        heartbeat($s['a']['probe'], '2026-09-28T15:00:00Z');
        heartbeat($s['b']['probe'], '2026-09-28T14:58:00Z');
        agora('2026-09-28T15:00:20Z');

        $html = Livewire::test(Painel::class)->html();

        expect($html)->toContain('data-calada="0"')->toContain('data-calada="1"');
        Livewire::test(Painel::class)->assertSee('calada há 2 min')->assertSee('há 20 s');
    });

    it('mostra a revisão aplicada contra a da central, e diz quando está atrasada', function () {
        $s = Decisao::sondas();
        heartbeat($s['a']['probe'], '2026-09-28T15:00:00Z');
        DB::table('probes')->where('id', $s['a']['probe'])->update(['bundle_revision' => 5]);
        agora('2026-09-28T15:00:05Z');

        Livewire::test(Painel::class)->assertSee('revisão aplicada')->assertSee('(atrasada)');
    });

    it('mostra o relógio e o descarte do spool (descarte nunca é silencioso)', function () {
        $s = Decisao::sondas();
        heartbeat($s['a']['probe'], '2026-09-28T15:00:00Z', ['spool' => ['dropped' => 7]]);
        agora('2026-09-28T15:00:05Z');

        Livewire::test(Painel::class)->assertSee('lab_declared')->assertSee('desvio 120 ms')->assertSee('7 descartadas');
    });
});

describe('avisos', function () {
    beforeEach(fn () => painelLigado());

    it('o aviso do canal dev é marcado SIMULADO', function () {
        $s = Decisao::sondas();
        Banco::check();
        Decisao::ambas($s, 0, 2, 'fail');
        Decisao::decidir(2);

        Livewire::test(Painel::class)->assertSeeHtml('data-teste="aviso"')->assertSeeHtml('data-teste="simulado"')->assertSee('SIMULADO');
    });

    it('sem aviso: diz que não há', function () {
        Livewire::test(Painel::class)->assertSee('Nenhum aviso gerado');
    });
});

describe('ambiente', function () {
    beforeEach(fn () => painelLigado());

    it('o laboratório se identifica numa faixa; sem rótulo, não há faixa', function () {
        config(['b3mon.panel.environment_label' => 'LABORATÓRIO']);
        Livewire::test(Painel::class)->assertSeeHtml('data-teste="faixa-ambiente"')->assertSee('LABORATÓRIO');

        config(['b3mon.panel.environment_label' => '']);
        Livewire::test(Painel::class)->assertDontSeeHtml('data-teste="faixa-ambiente"');
    });

    it('atualiza sozinho (wire:poll) e diz o fuso', function () {
        Livewire::test(Painel::class)->assertSeeHtml('wire:poll')->assertSee('Brasília');
    });
});
