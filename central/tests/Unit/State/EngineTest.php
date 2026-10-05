<?php

declare(strict_types=1);

use App\State\Coverage;
use App\State\Engine;
use App\State\Health;
use App\State\Ineligibility;
use App\State\Pending;
use App\State\Policy;
use App\State\Scope;
use Tests\Support\Cenario;

/*
| O motor de estado, sem banco, rede nem relógio real (B1). Cada teste cita o caso T-xxx do 10 e a invariante
| que cobre. O instante de avaliação é sempre passado de fora.
*/

function motor(): Engine
{
    return new Engine;
}

function avaliar(array $plano, array $observacoes, ?DateTimeImmutable $em = null, ?Policy $politica = null)
{
    return motor()->evaluate($politica ?? Policy::critical(), $plano, $observacoes, $em ?? Cenario::depoisDoSlot(2));
}

function estadoDe($avaliacao, string $perspectiva)
{
    foreach ($avaliacao->perspectives as $p) {
        if ($p->perspectiveId === $perspectiva) {
            return $p;
        }
    }
    throw new RuntimeException("perspectiva $perspectiva não encontrada");
}

// ---------------------------------------------------------------------------------------------------------------
// Os casos do 10 §10 e da tabela 5.1, vindos do arquivo de fixtures.
// ---------------------------------------------------------------------------------------------------------------

dataset('casos do motor', function () {
    $arquivo = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/motor/casos.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($arquivo['casos'] as $caso) {
        yield $caso['case_id'].' — '.$caso['origem'] => [$caso];
    }
});

it('chega à conclusão esperada em cada caso do 10', function (array $caso) {
    $avaliacao = avaliar(Cenario::plano(array_keys($caso['inputs'])), Cenario::observacoes($caso['inputs']));

    expect($avaliacao->health->value)->toBe($caso['expected']['health'])
        ->and($avaliacao->scope->value)->toBe($caso['expected']['scope'])
        ->and($avaliacao->coverage->value)->toBe($caso['expected']['coverage']);
})->with('casos do motor');

// ---------------------------------------------------------------------------------------------------------------
// Confirmação por perspectiva (03 §4)
// ---------------------------------------------------------------------------------------------------------------

it('T-002: uma falha antes de completar a sequência não confirma a queda e deixa a pendência visível', function () {
    // A estava `up` (três sucessos) e tem UMA falha: continua `up`, com falha em confirmação.
    $avaliacao = avaliar(
        Cenario::plano(['A']),
        Cenario::observacoes(['A' => ['pass', 'pass', 'pass', 'fail']]),
        Cenario::depoisDoSlot(3),
    );

    expect(estadoDe($avaliacao, 'A')->health)->toBe(Health::Up)
        ->and(estadoDe($avaliacao, 'A')->pending)->toBe(Pending::PendingFailure);
});

it('inicialização: sem sequência inicial confirmada o estado é unknown, mas a pendência aparece', function () {
    $avaliacao = avaliar(Cenario::plano(['A']), Cenario::observacoes(['A' => ['fail']]), Cenario::depoisDoSlot(0));

    expect(estadoDe($avaliacao, 'A')->health)->toBe(Health::Unknown)
        ->and(estadoDe($avaliacao, 'A')->pending)->toBe(Pending::PendingFailure);
});

it('recuperação em confirmação: em `down`, duas recuperações ainda não viram `up`', function () {
    $avaliacao = avaliar(
        Cenario::plano(['A']),
        Cenario::observacoes(['A' => ['fail', 'fail', 'fail', 'pass', 'pass']]),
        Cenario::depoisDoSlot(4),
    );

    expect(estadoDe($avaliacao, 'A')->health)->toBe(Health::Down)
        ->and(estadoDe($avaliacao, 'A')->pending)->toBe(Pending::PendingRecovery);
});

it('registra as transições confirmadas no slot da amostra que as confirmou', function () {
    $avaliacao = avaliar(
        Cenario::plano(['A']),
        Cenario::observacoes(['A' => ['fail', 'fail', 'fail', 'pass', 'pass', 'pass']]),
        Cenario::depoisDoSlot(5),
    );

    $transicoes = $avaliacao->transitions();
    expect($transicoes)->toHaveCount(2)
        ->and([$transicoes[0]->from, $transicoes[0]->to])->toBe([Health::Unknown, Health::Down])
        ->and($transicoes[0]->at)->toEqual(Cenario::slot(2))
        ->and([$transicoes[1]->from, $transicoes[1]->to])->toBe([Health::Down, Health::Up])
        ->and($transicoes[1]->at)->toEqual(Cenario::slot(5));
});

it('T-012: falha, lacuna, falha, falha não confirma queda (INV-02)', function () {
    // Slots 0, 2, 3: o slot 1 faltou. A lacuna zera a sequência, então são 2 consecutivas, não 3.
    $avaliacao = avaliar(
        Cenario::plano(['A']),
        Cenario::observacoes(['A' => ['fail', 'missing', 'fail', 'fail']]),
        Cenario::depoisDoSlot(3),
    );

    expect(estadoDe($avaliacao, 'A')->health)->toBe(Health::Unknown)
        ->and(estadoDe($avaliacao, 'A')->pending)->toBe(Pending::PendingFailure)
        ->and($avaliacao->transitions())->toBe([]);
});

it('T-012: a lacuna também impede que três falhas espaçadas derrubem um estado que estava up', function () {
    $avaliacao = avaliar(
        Cenario::plano(['A']),
        Cenario::observacoes(['A' => ['pass', 'pass', 'pass', 'fail', 'missing', 'fail', 'fail']]),
        Cenario::depoisDoSlot(6),
    );

    expect(estadoDe($avaliacao, 'A')->health)->toBe(Health::Up)
        ->and(estadoDe($avaliacao, 'A')->pending)->toBe(Pending::PendingFailure);
});

it('T-013: uma troca de geração no meio da sequência não mistura slots de configurações incompatíveis', function () {
    $g1 = Cenario::observacoes(['A' => ['fail', 'fail']], geracao: 1);
    $g2 = [Cenario::observacao('A', 'fail', 2, geracao: 2)];

    // Com a geração 2 vigente, só UMA falha vale: não confirma.
    $atual = avaliar(Cenario::plano(['A'], geracao: 2), [...$g1, ...$g2]);
    expect(estadoDe($atual, 'A')->health)->toBe(Health::Unknown)
        ->and($atual->transitions())->toBe([]);

    // Com a geração 1 vigente, a observação da geração 2 não conta: duas falhas, ainda sem confirmação.
    $antiga = avaliar(Cenario::plano(['A'], geracao: 1), [...$g1, ...$g2], Cenario::depoisDoSlot(2));
    expect(estadoDe($antiga, 'A')->health)->toBe(Health::Unknown)
        ->and($antiga->transitions())->toBe([]);
});

// ---------------------------------------------------------------------------------------------------------------
// Frescor e expiração por tempo (03 §3; C-01)
// ---------------------------------------------------------------------------------------------------------------

it('C-01: o frescor é função da política, e sempre maior que o tempo para confirmar uma queda', function () {
    $politica = Policy::critical();

    // (3 + 1) × 30 + 6 + 6 = 132 s.
    expect($politica->freshnessS())->toBe(132);

    // A invariante que o C-01 exige: com jitter, tolerância e transporte, a terceira amostra não pode chegar
    // quando a primeira já expirou. O intervalo entre a 1ª e a 3ª amostra mais a tolerância e a margem cabe.
    $paraConfirmar = ($politica->failureCount - 1) * $politica->intervalS + $politica->startToleranceS + $politica->transportMarginS;
    expect($politica->freshnessS())->toBeGreaterThan($paraConfirmar);
});

it('T-008: sem nenhuma amostra nova, o verde expira só pelo tempo', function () {
    $plano = Cenario::plano(['A', 'B', 'C']);
    $obs = Cenario::observacoes(['A' => ['pass', 'pass', 'pass'], 'B' => ['pass', 'pass', 'pass'], 'C' => ['pass', 'pass', 'pass']]);
    $freshness = Policy::critical()->freshnessS();

    // Exatamente no limite do frescor, ainda vale: a idade é `<=` ao frescor.
    $noLimite = avaliar($plano, $obs, Cenario::slot(2)->modify("+{$freshness} seconds"));
    expect($noLimite->health)->toBe(Health::Up);

    // Um segundo depois, expirou: nenhum verde eterno.
    $depois = avaliar($plano, $obs, Cenario::slot(2)->modify('+'.($freshness + 1).' seconds'));
    expect($depois->health)->toBe(Health::Unknown)
        ->and($depois->scope)->toBe(Scope::Unknown)
        ->and($depois->coverage)->toBe(Coverage::Insufficient)
        ->and(estadoDe($depois, 'A')->reason)->toBe(Ineligibility::Stale)
        ->and(estadoDe($depois, 'A')->lastConfirmed)->toBe(Health::Up);   // o histórico fica, e não é o presente
});

it('T-009: um incidente aberto que perde toda a observação não se resolve por ausência', function () {
    $plano = Cenario::plano(['A', 'B', 'C']);
    $obs = Cenario::observacoes(['A' => ['fail', 'fail', 'fail'], 'B' => ['fail', 'fail', 'fail'], 'C' => ['fail', 'fail', 'fail']]);

    expect(avaliar($plano, $obs)->health)->toBe(Health::Down);

    $muitoDepois = avaliar($plano, $obs, Cenario::depoisDoSlot(2, 3600));
    expect($muitoDepois->health)->toBe(Health::Unknown)       // nunca `up`: ausência não é recuperação
        ->and(estadoDe($muitoDepois, 'A')->lastConfirmed)->toBe(Health::Down);
});

it('T-010: falhas antigas de A e atuais de B, sem sobreposição válida, não compõem um falso quórum', function () {
    $antigas = Cenario::observacoes(['A' => ['fail', 'fail', 'fail']], deslocamento: -40);   // há mais de 20 min
    $atuais = Cenario::observacoes(['B' => ['fail', 'fail', 'fail']]);

    $avaliacao = avaliar(Cenario::plano(['A', 'B', 'C']), [...$antigas, ...$atuais]);

    expect($avaliacao->health)->toBe(Health::Unknown)       // não é `down`: só B vale
        ->and($avaliacao->coverage)->toBe(Coverage::Insufficient)
        ->and(estadoDe($avaliacao, 'A')->reason)->toBe(Ineligibility::Stale);
});

it('descarta um horário agendado no futuro além da tolerância de início', function () {
    $obs = [...Cenario::observacoes(['A' => ['pass', 'pass', 'pass']]), Cenario::observacao('A', 'fail', 50)];

    $avaliacao = avaliar(Cenario::plano(['A']), $obs, Cenario::depoisDoSlot(2));

    expect(estadoDe($avaliacao, 'A')->health)->toBe(Health::Up)
        ->and(estadoDe($avaliacao, 'A')->pending)->toBe(Pending::None);
});

// ---------------------------------------------------------------------------------------------------------------
// Elegibilidade: o que não mede o alvo não é falha do alvo (03 §2 e §3)
// ---------------------------------------------------------------------------------------------------------------

it('T-024: integridade inválida torna o presente unknown, e o último verde fica só como histórico', function () {
    $avaliacao = avaliar(
        Cenario::plano(['A']),
        Cenario::observacoes(['A' => ['pass', 'pass', 'pass', 'pass:invalid']]),
        Cenario::depoisDoSlot(3),
    );

    expect(estadoDe($avaliacao, 'A')->health)->toBe(Health::Unknown)
        ->and(estadoDe($avaliacao, 'A')->reason)->toBe(Ineligibility::IntegrityNotValid)
        ->and(estadoDe($avaliacao, 'A')->lastConfirmed)->toBe(Health::Up);
});

it('T-026: relógio não sincronizado ou desconhecido nega o voto temporal e nunca vira zero (INV-02)', function (string $modo) {
    $avaliacao = avaliar(
        Cenario::plano(['A']),
        Cenario::observacoes(['A' => ['pass', 'pass', 'pass', "pass:$modo"]]),
        Cenario::depoisDoSlot(3),
    );

    expect(estadoDe($avaliacao, 'A')->health)->toBe(Health::Unknown)
        ->and(estadoDe($avaliacao, 'A')->reason)->toBe(Ineligibility::ClockUntrusted);
})->with(['clock_unknown', 'clock_unsynchronized']);

it('T-017: capacidade indisponível e erro do executor nunca são falha do alvo (INV-02)', function (string $resultado) {
    $avaliacao = avaliar(
        Cenario::plano(['A']),
        Cenario::observacoes(['A' => [$resultado, $resultado, $resultado, $resultado]]),
        Cenario::depoisDoSlot(3),
    );

    expect(estadoDe($avaliacao, 'A')->health)->not->toBe(Health::Down)
        ->and(estadoDe($avaliacao, 'A')->health)->toBe(Health::Unknown)
        ->and(estadoDe($avaliacao, 'A')->reason)->toBe(Ineligibility::NotMeasurable)
        ->and($avaliacao->transitions())->toBe([]);
})->with(['capability_unavailable', 'executor_error', 'config_error', 'policy_denied']);

it('um slot pulado é ausência e não derruba um estado que ainda está fresco', function () {
    $avaliacao = avaliar(
        Cenario::plano(['A']),
        Cenario::observacoes(['A' => ['pass', 'pass', 'pass', 'skipped']]),
        Cenario::depoisDoSlot(3),
    );

    expect(estadoDe($avaliacao, 'A')->health)->toBe(Health::Up);
});

// ---------------------------------------------------------------------------------------------------------------
// Domínios, quórum e conflito (03 §5)
// ---------------------------------------------------------------------------------------------------------------

it('T-011: duas sondas do mesmo domínio que discordam não dão voto e não completam o quórum', function () {
    $plano = Cenario::plano(['A1' => 'dom-a', 'A2' => 'dom-a', 'B' => 'dom-b']);
    $obs = Cenario::observacoes([
        'A1' => ['pass', 'pass', 'pass'],
        'A2' => ['fail', 'fail', 'fail'],
        'B' => ['pass', 'pass', 'pass'],
    ]);

    $avaliacao = avaliar($plano, $obs);

    expect($avaliacao->conflictedDomains)->toBe(['dom-a'])
        ->and($avaliacao->validDomains)->toBe(1)                 // só dom-b vota
        ->and($avaliacao->health)->toBe(Health::Unknown)         // 1 domínio < mínimo de 2
        ->and($avaliacao->coverage)->toBe(Coverage::Insufficient)
        ->and(estadoDe($avaliacao, 'A1')->health)->toBe(Health::Up)      // as duas observações ficam visíveis
        ->and(estadoDe($avaliacao, 'A2')->health)->toBe(Health::Down);
});

it('T-011: duas sondas do mesmo domínio que concordam dão UM voto, e não dois', function () {
    $plano = Cenario::plano(['A1' => 'dom-a', 'A2' => 'dom-a', 'B' => 'dom-b']);
    $obs = Cenario::observacoes([
        'A1' => ['fail', 'fail', 'fail'],
        'A2' => ['fail', 'fail', 'fail'],
        'B' => ['pass', 'pass', 'pass'],
    ]);

    $avaliacao = avaliar($plano, $obs);

    expect($avaliacao->validDomains)->toBe(2)
        ->and($avaliacao->plannedDomains)->toBe(2)
        ->and($avaliacao->health)->toBe(Health::Degraded)        // 1 domínio cai, 1 sobe: parcial
        ->and($avaliacao->scope)->toBe(Scope::Partial);
});

it('o mínimo de domínios vem da política e o motor não o reduz sozinho quando sondas somem', function () {
    $umDominio = new Policy('interno-v1', 30, 3, 3, 6, 6, minDomains: 1);

    $avaliacao = avaliar(
        Cenario::plano(['A']),
        Cenario::observacoes(['A' => ['fail', 'fail', 'fail']]),
        politica: $umDominio,
    );
    expect($avaliacao->health)->toBe(Health::Down)->and($avaliacao->coverage)->toBe(Coverage::Full);

    // Com o mínimo de 2 (perfil externo), a mesma evidência de uma sonda só não conclui.
    $externo = avaliar(Cenario::plano(['A', 'B']), Cenario::observacoes(['A' => ['fail', 'fail', 'fail']]));
    expect($externo->health)->toBe(Health::Unknown)->and($externo->coverage)->toBe(Coverage::Insufficient);
});

it('T-016: IPv4 e IPv6 são avaliados separadamente, e nunca em um semáforo só (INV-03)', function () {
    $ipv4 = avaliar(Cenario::plano(['A', 'B']), Cenario::observacoes(['A' => ['pass', 'pass', 'pass'], 'B' => ['pass', 'pass', 'pass']]));
    $ipv6 = avaliar(Cenario::plano(['A', 'B']), Cenario::observacoes(['A' => ['fail', 'fail', 'fail'], 'B' => ['fail', 'fail', 'fail']]));

    expect($ipv4->health)->toBe(Health::Up)->and($ipv6->health)->toBe(Health::Down);
});

// ---------------------------------------------------------------------------------------------------------------
// Determinismo: ordem de chegada e duplicatas (10 §10)
// ---------------------------------------------------------------------------------------------------------------

it('dá o mesmo resultado em qualquer ordem de chegada das observações', function () {
    $plano = Cenario::plano(['A', 'B', 'C']);
    $obs = Cenario::observacoes([
        'A' => ['pass', 'pass', 'fail', 'fail', 'fail'],
        'B' => ['fail', 'fail', 'fail', 'pass', 'pass', 'pass'],
        'C' => ['pass', 'missing', 'pass', 'pass', 'pass:invalid'],
    ]);
    $em = Cenario::depoisDoSlot(5);

    $referencia = avaliar($plano, $obs, $em);

    // Permutações determinísticas: inversa, rotações e um embaralhamento de semente fixa.
    $permutacoes = [array_reverse($obs)];
    for ($giro = 1; $giro < count($obs); $giro++) {
        $permutacoes[] = [...array_slice($obs, $giro), ...array_slice($obs, 0, $giro)];
    }
    mt_srand(20261005);
    for ($i = 0; $i < 25; $i++) {
        $copia = $obs;
        shuffle($copia);
        $permutacoes[] = $copia;
    }

    foreach ($permutacoes as $permutacao) {
        expect(avaliar($plano, $permutacao, $em))->toEqual($referencia);
    }
    expect(count($permutacoes))->toBeGreaterThan(30);
});

it('trata uma observação reenviada como a mesma, sem contar duas vezes', function () {
    $uma = Cenario::observacoes(['A' => ['fail', 'fail']]);
    $duplicada = [...$uma, ...$uma, ...$uma];

    $avaliacao = avaliar(Cenario::plano(['A']), $duplicada, Cenario::depoisDoSlot(1));

    // Duas falhas distintas, cada uma contada UMA vez: ainda sem confirmação (precisa de 3).
    expect(estadoDe($avaliacao, 'A')->health)->toBe(Health::Unknown)
        ->and($avaliacao->transitions())->toBe([]);

    $comTerceira = avaliar(Cenario::plano(['A']), [...$duplicada, Cenario::observacao('A', 'fail', 2)], Cenario::depoisDoSlot(2));
    expect(estadoDe($comTerceira, 'A')->health)->toBe(Health::Down);
});

it('um conflito no mesmo slot (conteúdos diferentes) não escolhe por ordem de chegada', function () {
    $pass = Cenario::observacao('A', 'pass', 3);
    $fail = Cenario::observacao('A', 'fail', 3);
    $base = Cenario::observacoes(['A' => ['pass', 'pass', 'pass']]);

    $ab = avaliar(Cenario::plano(['A']), [...$base, $pass, $fail], Cenario::depoisDoSlot(3));
    $ba = avaliar(Cenario::plano(['A']), [...$base, $fail, $pass], Cenario::depoisDoSlot(3));

    expect($ab)->toEqual($ba)
        ->and(estadoDe($ab, 'A')->health)->toBe(Health::Unknown)
        ->and(estadoDe($ab, 'A')->reason)->toBe(Ineligibility::SlotConflict);
});

// ---------------------------------------------------------------------------------------------------------------
// Pureza (02 §3): o motor não lê relógio, banco nem rede
// ---------------------------------------------------------------------------------------------------------------

it('não tem como ler o relógio, o banco ou a rede: o instante é sempre passado de fora', function () {
    $proibidos = ['DB::', 'Http::', 'Cache::', 'Log::', 'Queue::', 'Mail::', 'Notification::', 'Event::', 'Carbon',
        'now(', 'today(', 'time()', 'date(', 'microtime', 'hrtime', 'file_get_contents', 'curl_'];

    foreach (glob(dirname(__DIR__, 3).'/app/State/*.php') as $arquivo) {
        $fonte = file_get_contents($arquivo);
        // Comentários podem citar o proibido, então só o código conta.
        $codigo = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $fonte);

        foreach ($proibidos as $termo) {
            expect($codigo)->not->toContain($termo, basename($arquivo)." usa $termo");
        }
        // `new DateTimeImmutable()` sem argumento é "agora".
        expect(preg_match('~new\s+DateTime(Immutable)?\(\s*\)~', $codigo))->toBe(0);
    }
});

it('rejeita uma política com parâmetros que não fazem sentido', function () {
    expect(fn () => new Policy('x', 0, 3, 3, 6, 6, 2))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Policy('x', 30, 0, 3, 6, 6, 2))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Policy('x', 30, 3, 3, -1, 6, 2))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Policy('x', 30, 3, 3, 6, 6, 0))->toThrow(InvalidArgumentException::class);
});
