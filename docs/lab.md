# O laboratório

> **Status:** `ACTIVE` · `make lab-up`, `make demo`, `make smoke` e `make lab-down` (item B8 da fila). Especificação: 02 §6 e §10 do 2.0 e L-02.
> **É laboratório e se identifica como tal em toda parte.** O que passa aqui prova o **caminho**; não homologa nada real (G-UP, diversidade de
> rede, canais reais seguem `blocked`).

## O que sobe

`make lab-up` (`lab/lab.sh up`) leva **~5 s** com tudo já instalado; a primeira vez também roda `composer install` na `central/` e compila o alvo e a
sonda. Sem Docker (ADR-005), tudo em espaço de usuário, só em `127.0.0.1`:

| Peça | Porta / onde | O que é |
|---|---|---|
| PostgreSQL 17 | `54329`, banco **`b3mon_lab`** | O de `lab/pg.sh` (o `b3mon_dev` e o `b3mon_test` não são tocados) |
| Central (web) | `18000` | `php -S` direto na `public/`, com 4 workers. **Não** `artisan serve`, que muda de porta em silêncio quando a sua está ocupada |
| Workers | `ingest`, `decide`, `notify` | Os três de `b3mon:work` |
| Painel | `http://127.0.0.1:18000/painel` | Estados, incidentes, sondas e avisos ([painel.md](painel.md)). Fechado por padrão; o laboratório o liga e o identifica numa faixa |
| Agendador | `schedule:work` | Reconciliador de lease, partições e a **expiração por tempo** (a cada 10 s) |
| Alvo `lab` | `18080` (alvo) e `18081` (controle) | `lab/target/`: responde `X-B3mon-Lab: lab` em **toda** resposta e `[lab-target]` em todo log; modos `ok`, `falha` (503) e `lento` |
| Sondas `lab-a` e `lab-b` | processos `b3mon-probe`, spool e bundle em `lab/.run/probes/<nome>/` | Domínios `lab-dom-a` e `lab-dom-b`. **Uma simulação**: duas sondas na mesma máquina não são diversidade de rede (02 §6) |

A central **não usa o `.env`**: a configuração do laboratório é exportada por `lab/lab.sh`, para o laboratório nunca depender de (nem tocar em) um `.env`
de verdade. O estado vive em `lab/.run/` (fora do git, [ADR-003](decisions.md)), os tokens das sondas em `lab/.run/probes/*/token` com modo `0600`, e os
logs em `lab/.run/logs/`. O canal de notificação é o **`dev`**, que escreve em `central/storage/logs/notifications-dev.log` com o prefixo `[SIMULADO]`.

O check do laboratório é HTTP em `127.0.0.1:18080/health`, **a cada 5 s** (o perfil crítico de produção é 30 s; a política da central acompanha a cadência
do check, e a política local da sonda do laboratório aceita no mínimo 5 s). Confirmar uma queda leva ~15 s; recuperar, ~15 s.

## O cenário (`make demo`, ~3 min)

Cada fase tem **asserções**, e o script sai com código diferente de zero se qualquer uma falhar. No fim, mostra os avisos desta execução.

| Fase | O que acontece | O que se afirma |
|---|---|---|
| 1. Sucesso | O alvo responde `200`; as duas sondas medem | Estado `up`, cobertura plena (2 domínios válidos de 2), nenhum incidente |
| 2. Falha → incidente | `lab/lab.sh mode falha`: o alvo passa a responder `503` | Incidente **aberto** (`critical`, `all_observed`) e **uma** notificação `opened`, não uma por amostra |
| 3. Recuperação | O alvo volta | Incidente **fechado** e uma notificação `recovered` |
| 4. Ausência | As **duas sondas param** (SIGTERM) por mais que o frescor (27 s) | O estado deixa de ser `up` **sozinho, sem resultado novo** (expiração por tempo), vira `unknown` com cobertura `insufficient`; **nenhum** incidente, nenhum aviso, e a transição `up → unknown` no histórico. Quando as sondas voltam, o estado volta a `up` e também não avisa nada |
| 5. Reenvio (replay) | O alvo vai a `falha` e a **central cai** por 40 s; as sondas medem e guardam no spool; a central volta | O backlog é entregue, o incidente abre **uma** vez com o início observado **no passado**, **uma** notificação `opened` para o backlog inteiro, e fecha depois |
| 6. Fecho | | Duas quedas, duas `opened`, duas `recovered`, dois incidentes fechados, nenhum item `dead`, toda linha do log com `[SIMULADO]` |

Também há `make smoke` (verificação não destrutiva: central, banco, alvo `lab`, as duas sondas com heartbeat nos últimos 30 s, nenhum item `dead`, todos os
processos de pé) e `lab/lab.sh mode ok|falha|lento`, `lab/lab.sh probes stop|start` e `lab/lab.sh central stop|start` para experimentar à mão.

## O que o laboratório achou

Subir tudo junto, pela primeira vez, mostrou três coisas que nenhum teste de unidade tinha mostrado:

1. **A expiração por tempo não existia (T-008).** O motor é puro e só reavalia quando alguém pede, e o pedido vem de cada **resultado novo**. Sem
   resultado novo (a sonda parou, a central ficou fora), ninguém pedia, e um `up` de ontem ficava `up` para sempre: o verde antigo que o produto promete nunca
   mostrar. A fase 4 do cenário nasceu para provar a ausência e **falhou** (o estado ficava `up`). `b3mon:reevaluate-expired`, a cada 10 s, enfileira a reavaliação
   do estado cuja validade passou.
2. **A central ignorava a cadência do check.** O motor exige slots separados por **exatamente** o intervalo, e o `Decider` usava sempre o perfil de 30 s: um
   check de 5 s nunca confirmaria nada. A política agora leva a cadência e a tolerância do check (`Policy::forCheck`).
3. **O aviso de recuperação dizia `severity=warning`.** A avaliação era `up`, e a gravidade saía dela; a recuperação de uma queda **crítica** parecia leve. O
   payload leva a gravidade do **incidente**.

E dois defeitos de **infraestrutura**: o `artisan serve` mudava de porta quando a anterior ainda não tinha sido liberada (a sonda ficava falando com a porta
errada, e o cenário de replay só passou depois de trocar por `php -S` direto), e o semeador **não era idempotente** (um `UPDATE` sem mudança subia a revisão do
bundle de todas as sondas; agora só sobe quando a linha de fato muda).

## Como se prova

- **O cenário**, rodando a central de verdade, os três workers, o agendador, o alvo e as duas sondas (binários compilados): `make demo` **OK em 164 s** na primeira
  execução completa. Antes disso falhou, e a falha foi a da fase de reenvio (a central não voltava).
- **Central (Pest, 372 testes):** o semeador (as duas travas `B3MON_LAB=1` e banco `*_lab`, idempotência, token `0600` e só o SHA-256 no banco, rotação quando o arquivo
  some e revogação da credencial antiga, e o bundle de cada sonda semeada), a expiração por tempo (não pede enquanto vale, pede quando passa, coalesce, não gira em laço
  com `unknown`, **não fecha incidente aberto** e não avisa), a cadência do check e o gatilho da revisão.
- **Alvo (Go, `-race`):** a marca de laboratório em toda resposta, os três modos, o controle só por `POST`, **só escuta em loopback** (recusa `0.0.0.0`, `:porta`, `localhost`,
  `[::]`), e o log com `[lab-target]`.
- **Mutação à mão:** 26 estragos na central e 13 no alvo. **Quatro escaparam na primeira rodada** (a trava de banco com o sufixo trocado, o diretório do token aberto, a
  revisão da política do check ignorada, e o alvo que respondia a qualquer caminho) e ganharam teste; na segunda, todos foram pegos.

## O que não está aqui

- **O cadastro administrativo** de checks e assignments (F0-05): o laboratório os semeia por `b3mon:lab-seed`, que **recusa rodar** fora de um banco `*_lab` com `B3MON_LAB=1`.
- **Prova de upstream, de IPv6, de iOS e de diversidade real de rede.** Duas sondas num host e um alvo de mentira não demonstram nada disso; ficam como **bloqueio**, nunca como aceite: ver [aceite.md](aceite.md).
- **O relógio das sondas é declarado**, não medido: o laboratório o declara como `lab_declared`.
- **Autenticação do painel** (F0-05): o painel do laboratório é aberto só em loopback e o laboratório o liga (`B3MON_PANEL_ENABLED=1`); ver [painel.md](painel.md).
