@php
    use App\Panel\PanelData as D;

    $rotuloSaude = ['up' => 'em pé', 'degraded' => 'degradado', 'down' => 'fora', 'unknown' => 'desconhecido'];
    $corSaude = ['up' => 'success', 'degraded' => 'warning', 'down' => 'danger', 'unknown' => 'secondary'];
    $rotuloCobertura = ['full' => 'plena', 'reduced' => 'reduzida', 'insufficient' => 'insuficiente'];
    $corCobertura = ['full' => 'success', 'reduced' => 'warning', 'insufficient' => 'secondary'];
    $rotuloAbrangencia = ['none' => 'nenhuma', 'partial' => 'parcial', 'all_observed' => 'todas as observadas', 'unknown' => 'desconhecida'];
    $corGravidade = ['critical' => 'danger', 'warning' => 'warning', 'info' => 'info'];
    $rotuloGravidade = ['critical' => 'crítica', 'warning' => 'aviso', 'info' => 'informativa'];
@endphp
<div wire:poll.5s>
    @if ($rotulo !== '')
        <div class="b3-faixa-lab" data-teste="faixa-ambiente">{{ $rotulo }}</div>
    @endif

    <header class="bg-body border-bottom px-3 py-2 d-flex align-items-center justify-content-between">
        <div>
            <strong>b3monitor</strong> <span class="b3-pequeno">estado atual da plataforma</span>
        </div>
        <div class="b3-pequeno" data-teste="relogio">
            atualizado às <strong>{{ D::hora($agora) }}</strong> (Brasília, UTC−3) · a tela se atualiza sozinha a cada 5 s
        </div>
    </header>

    <main class="container-fluid px-3 py-3">

        {{-- ESTADOS ATUAIS --}}
        <div class="card mb-3" data-teste="estados">
            <div class="card-header b3-card-titulo">Estados atuais</div>
            <div class="card-body p-0">
                @if (count($estados) === 0)
                    <p class="m-3 text-body-secondary" data-teste="estados-vazio">Nenhuma verificação avaliada ainda. Sem estado não há verde: não há o que mostrar.</p>
                @else
                    <table class="table table-sm mb-0 b3-tabela">
                        <thead>
                            <tr>
                                <th>Verificação</th>
                                <th>Saúde</th>
                                <th>Abrangência</th>
                                <th>Cobertura</th>
                                <th>Integridade</th>
                                <th>Avaliado em</th>
                                <th>Válido até</th>
                                <th>Perspectivas (sondas)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($estados as $e)
                                <tr class="{{ $e['expirado'] ? 'b3-expirado' : '' }}" data-teste="estado" data-saude="{{ $e['saude'] }}">
                                    <td><strong>{{ $e['verificacao'] }}</strong> <span class="b3-pequeno">{{ $e['familia'] }}</span></td>
                                    <td>
                                        <span class="badge text-bg-{{ $corSaude[$e['saude']] }}" data-teste="saude">{{ $rotuloSaude[$e['saude']] }}</span>
                                        @if ($e['expirado'])
                                            <div class="b3-pequeno" data-teste="expirado">
                                                evidência <strong>expirada</strong>: o último estado confirmado
                                                (<span class="b3-mono">{{ $rotuloSaude[$e['saude_registrada']] }}</span>) é histórico, não o presente
                                            </div>
                                        @endif
                                    </td>
                                    <td data-teste="abrangencia">{{ $rotuloAbrangencia[$e['abrangencia']] ?? $e['abrangencia'] }}</td>
                                    <td>
                                        <span class="badge text-bg-{{ $corCobertura[$e['cobertura']] }}" data-teste="cobertura">{{ $rotuloCobertura[$e['cobertura']] }}</span>
                                        <span class="b3-pequeno" title="domínios de observação com evidência válida / previstos">{{ $e['dominios'] }} domínios</span>
                                    </td>
                                    <td>
                                        <span data-teste="integridade">{{ $e['integridade'] }}</span>
                                        @if ($e['relogio'] !== 'ok')
                                            <div class="b3-pequeno text-danger">relógio {{ $e['relogio'] }}</div>
                                        @endif
                                    </td>
                                    <td class="b3-mono" data-teste="as-of">{{ D::hora($e['as_of']) }}<div class="b3-pequeno">há {{ D::idade($e['idade_s']) }}</div></td>
                                    <td class="b3-mono" data-teste="valid-until">{{ D::hora($e['valid_until']) }}</td>
                                    <td>
                                        @foreach ($e['perspectivas'] as $p)
                                            <div class="b3-pequeno" data-teste="perspectiva">
                                                <strong>{{ $p['sonda'] }}</strong> ({{ $p['dominio'] }}):
                                                {{ $rotuloSaude[$p['saude']] ?? $p['saude'] }}@if ($p['motivo']) · {{ $p['motivo'] }}@endif
                                            </div>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        {{-- INCIDENTES --}}
        <div class="card mb-3" data-teste="incidentes">
            <div class="card-header b3-card-titulo">Incidentes <span class="b3-pequeno text-body-secondary">(abertos primeiro; início observado é diferente de quando foi confirmado)</span></div>
            <div class="card-body p-0">
                @if (count($incidentes) === 0)
                    <p class="m-3 text-body-secondary" data-teste="incidentes-vazio">Nenhum incidente registrado.</p>
                @else
                    <table class="table table-sm mb-0 b3-tabela">
                        <thead>
                            <tr><th>Situação</th><th>Verificação</th><th>Gravidade</th><th>Abrangência</th><th>Início observado</th><th>Confirmado em</th><th>Fim confirmado</th><th>Duração</th><th>Avisos</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($incidentes as $i)
                                <tr data-teste="incidente" data-aberto="{{ $i['aberto'] ? '1' : '0' }}">
                                    <td>
                                        @if ($i['aberto'])
                                            <span class="badge text-bg-danger">aberto</span>
                                        @else
                                            <span class="badge text-bg-secondary">fechado</span>
                                        @endif
                                        <span class="b3-mono b3-pequeno">{{ $i['curto'] }}</span>
                                    </td>
                                    <td>{{ $i['verificacao'] }} <span class="b3-pequeno">{{ $i['familia'] }}</span></td>
                                    <td><span class="badge text-bg-{{ $corGravidade[$i['gravidade']] ?? 'secondary' }}">{{ $rotuloGravidade[$i['gravidade']] ?? $i['gravidade'] }}</span></td>
                                    <td>{{ $rotuloAbrangencia[$i['abrangencia']] ?? $i['abrangencia'] }}</td>
                                    <td class="b3-mono">{{ D::hora($i['inicio_observado'], true) }}</td>
                                    <td class="b3-mono">{{ D::hora($i['confirmado_em'], true) }}</td>
                                    <td class="b3-mono">{{ D::hora($i['fim_confirmado'], true) }}</td>
                                    <td>{{ D::idade($i['duracao_s']) }}{{ $i['aberto'] ? ' (em curso)' : '' }}</td>
                                    <td>{{ $i['avisos'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <div class="row g-3">
            {{-- SONDAS --}}
            <div class="col-xl-7">
                <div class="card h-100" data-teste="sondas">
                    <div class="card-header b3-card-titulo">Sondas</div>
                    <div class="card-body p-0">
                        @if (count($sondas) === 0)
                            <p class="m-3 text-body-secondary">Nenhuma sonda cadastrada.</p>
                        @else
                            <table class="table table-sm mb-0 b3-tabela">
                                <thead><tr><th>Sonda</th><th>Heartbeat</th><th>Configuração</th><th>Relógio</th><th>Spool</th></tr></thead>
                                <tbody>
                                    @foreach ($sondas as $s)
                                        <tr data-teste="sonda" data-calada="{{ $s['calada'] ? '1' : '0' }}">
                                            <td><strong>{{ $s['nome'] }}</strong><div class="b3-pequeno">{{ $s['dominio'] }}@if ($s['versao']) · {{ $s['versao'] }}@endif @if ($s['status'] !== 'active')· <span class="text-danger">{{ $s['status'] }}</span>@endif</div></td>
                                            <td>
                                                @if ($s['calada'])
                                                    <span class="badge text-bg-warning" data-teste="sonda-calada">{{ $s['heartbeat_idade_s'] === null ? 'nunca falou' : 'calada há '.D::idade($s['heartbeat_idade_s']) }}</span>
                                                @else
                                                    <span class="badge text-bg-success">há {{ D::idade($s['heartbeat_idade_s']) }}</span>
                                                @endif
                                            </td>
                                            <td class="b3-pequeno">
                                                revisão aplicada <strong>{{ $s['revisao_aplicada'] ?? '—' }}</strong> de {{ $s['revisao_central'] }}
                                                @if ($s['revisao_aplicada'] !== null && $s['revisao_aplicada'] < $s['revisao_central'])<span class="text-warning">(atrasada)</span>@endif
                                            </td>
                                            <td class="b3-pequeno">{{ $s['relogio'] ?? '—' }}@if ($s['relogio_referencia']) ({{ $s['relogio_referencia'] }})@endif @if ($s['desvio_relogio_ms'] !== null)<div>desvio {{ $s['desvio_relogio_ms'] }} ms</div>@endif</td>
                                            <td class="b3-pequeno">{{ $s['spool_linhas'] ?? '—' }} linhas @if ($s['spool_idade_s'] !== null)· mais velha {{ D::idade($s['spool_idade_s']) }}@endif @if ($s['descartados'])<div class="text-danger">{{ $s['descartados'] }} descartadas</div>@endif</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            </div>

            {{-- AVISOS --}}
            <div class="col-xl-5">
                <div class="card h-100" data-teste="avisos">
                    <div class="card-header b3-card-titulo">Avisos (outbox)</div>
                    <div class="card-body p-0">
                        @if (count($avisos) === 0)
                            <p class="m-3 text-body-secondary">Nenhum aviso gerado.</p>
                        @else
                            <table class="table table-sm mb-0 b3-tabela">
                                <thead><tr><th>Incidente</th><th>Motivo</th><th>Canal</th><th>Situação</th></tr></thead>
                                <tbody>
                                    @foreach ($avisos as $a)
                                        <tr data-teste="aviso">
                                            <td class="b3-mono">{{ $a['incidente'] }} <span class="b3-pequeno">v{{ $a['versao'] }}</span></td>
                                            <td>{{ $a['motivo'] }}</td>
                                            <td>{{ $a['canal'] }}/{{ $a['destinatario'] }} @if ($a['simulado'])<span class="badge text-bg-dark" data-teste="simulado">SIMULADO</span>@endif</td>
                                            <td>{{ $a['status'] }} <span class="b3-pequeno">{{ $a['tentativas'] }} tent.</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
