# O painel mínimo

> **Status:** `ACTIVE` · O que `central/` mostra **hoje** em `/painel` (item B9 da fila). Especificação: [08 §5](../.continue/b3monitor/08_APP_PAINEL_E_ALERTAS.md)
> com D-06 e C-12 do 2.0. Blade + Livewire 4 + CoreUI 5, uma tela só. **Não decide nada**: lê o que o motor já gravou.

## O que está na tela

| Bloco | O que mostra |
|---|---|
| **Estados atuais** | Por verificação e família de IP: **saúde**, **abrangência**, **cobertura** (`plena`/`reduzida`/`insuficiente` e quantos domínios de observação válidos entre os previstos), **integridade** da medição, `as_of` (avaliado em), `valid_until` (válido até) e a saúde de cada **perspectiva** (sonda e domínio) |
| **Incidentes** | Abertos primeiro. Gravidade, abrangência, **início observado** (a primeira falha), **confirmado em** (a detecção) e **fim confirmado**: são três instantes diferentes (03 §7). Duração e quantos avisos nasceram |
| **Sondas** | Idade do heartbeat (calada passado de 90 s, ou "nunca falou"), revisão do bundle **aplicada contra a da central** (atrasada é dito), relógio e desvio, linhas no spool e descartes |
| **Avisos (outbox)** | Os dez últimos, com o canal e a situação. O canal `dev` leva a marca **SIMULADO** |

Horas em **Brasília (UTC−3)**, com o fuso dito na tela (14 §6: persiste em UTC, apresenta em Brasília). A tela se atualiza sozinha a cada 5 s (`wire:poll`).
É legível em **1366×768 sem rolagem horizontal** (capturas em [evidence/f0-01/](../evidence/f0-01/)).

## A regra de leitura: nunca verde antigo

O motor só reavalia quando alguém pede, e o pedido vem de um resultado novo ou do reavaliador por tempo (`b3mon:reevaluate-expired`, a cada 10 s). Entre a
validade vencer e o reavaliador rodar existe uma janela em que o banco ainda diz `up`. **O painel não confia nisso**: um estado cuja `valid_until` já passou
(ou que nunca teve evidência elegível) aparece como **desconhecido**, com a abrangência também desconhecida, e uma nota diz que o último estado confirmado
(`em pé`) é **histórico, não o presente**. A fronteira é exata: no último segundo da validade ainda vale; no seguinte, não.

**Saúde, cobertura e integridade são colunas separadas (C-12).** "Em pé" com cobertura **reduzida** (1 de 3 domínios) não é o mesmo que "em pé" com cobertura
plena, e "desconhecido" por falta de cobertura não é falha do alvo. A integridade diz se a **medição** é confiável (uma perspectiva descartada por integridade
mostra `comprometida`, e um relógio não confiável é dito), sem virar "o alvo caiu".

## Acesso: fechado por padrão

O painel **não tem login** (contas, 2FA e a sessão longa são do F0-05), então não pode estar aberto a quem alcança a central:

- `B3MON_PANEL_ENABLED` desligado (**o padrão**): `404`, como se a rota não existisse.
- Ligado, só atende **loopback** (`127.0.0.1`, `::1`): `403` para o resto. O que vale é o `REMOTE_ADDR`; `X-Forwarded-For` **não** transforma um cliente remoto em local.
- A trava é um **middleware persistente do Livewire**: o componente se atualiza por `POST /livewire-*/update`, que não passaria pelo middleware da rota, e sem a
  persistência só a primeira carga estaria protegida.

O laboratório liga o painel e o identifica numa faixa (`B3MON_ENVIRONMENT_LABEL`, "LABORATÓRIO — dados sintéticos"). Quem expuser o painel antes do F0-05 expôs o estado
operacional da Blue3 sem login.

## O CSS

`npm run build` em `central/` compila `resources/css/painel.css` (CoreUI 5 mais o que é nosso) para `public/build/`, **que não é versionado**. O `make lab-up` o compila
sozinho na primeira subida (`npm ci` e `npm run build`, com a rede), e o log fica em `lab/.run/logs/npm.log`.

## Como se prova

25 testes Pest/Livewire em `tests/Feature/Painel/`: o acesso (404, 403, `X-Forwarded-For`, v4 e v6, o middleware persistente), o estado vazio, o estado válido (com a conversão
para Brasília), **o estado vencido que o banco ainda chama de `up`** (e a fronteira exata), o estado sem validade, a cobertura reduzida ao lado do verde, a integridade e o
relógio, um estado derivado do **motor de verdade** (três falhas viram `down` com o incidente aberto), o incidente aberto antes do fechado, a duração em curso, a sonda calada,
a revisão atrasada, o descarte do spool e o aviso SIMULADO.

**Mutação à mão, 25 estragos: 24 pegos e 1 equivalente** (retirar a guarda `validade === null`, porque em PHP `DateTimeImmutable > null` já é verdadeiro; fica como defesa em
profundidade). Dois escaparam na primeira rodada e viraram teste: a abrangência de um estado vencido (o teste só olhava a saúde) e o nome da sonda dentro da perspectiva
(a tabela de sondas, na mesma página, o mostrava e escondia o defeito).

## O que não está aqui

- **Login, 2FA, papéis e sessão longa** (F0-05). **Reconhecer, atribuir e comentar incidente** (F1).
- **Gráficos e histórico** (a tela é o estado atual) e **o painel de upstream e de caminhos** (F0-06).
- **Prova de que um humano entende a tela sob estresse.** Só se mediu a legibilidade em 1366×768 e a ausência de rolagem horizontal.
