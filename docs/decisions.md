# Decisões (registro de ADRs)

> **Status:** `ACTIVE` · O registro único e cronológico das decisões tomadas
> neste repositório. Formato: Architecture Decision Record.

Um ADR registra uma decisão **e o motivo pelo qual ela foi tomada**, para que a
próxima sessão não a rediscuta. Um how-to não argumenta direção — ele linka o
ADR.

A numeração é sequencial e nunca reaproveitada. Um ADR superado não é apagado:
o status dele muda para `SUPERSEDED` e ele nomeia o ADR que o substituiu.

Decisões da frota — idioma, formato de commit, branch, fila × registro — **não**
são repetidas aqui: elas são ADRs do
[repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/decisions.md)
e valem para este repositório do jeito que estão escritas lá. Este registro
guarda só o que foi decidido **para o b3monitor**.

---

## ADR-001 — sem `LICENSE` e sem `NOTICE`: código interno da Blue3, em repositório privado

**Status:** `ACCEPTED` · 28/09/2026

**Contexto.** O skeleton do repodocs traz um `LICENSE` MIT com
`Copyright (c) 2026 Samir Hanna Verza` e um `NOTICE` que concorda com ele
([ADR-006](https://github.com/samirhvbr/repodocs/blob/master/docs/decisions.md#adr-006--license-and-notice-without-extension-mit)
do repodocs). O b3monitor é outra coisa: nasceu em 28/09/2026 como repositório
**privado** da organização `BLUE3-ISP`, para código interno da empresa. O MIT
daria a qualquer pessoa com uma cópia deste código — um ex-colaborador, um
fornecedor, um vazamento — o direito escrito de usá-lo, modificá-lo e
redistribuí-lo. E o copyright pessoal diria que o código é do Samir, não da
Blue3. Medido no mesmo dia: nenhum dos outros 13 repositórios da `BLUE3-ISP`
nesta máquina tem `LICENSE` ou `NOTICE`.

**Decisão.** Este repositório **não** tem `LICENSE` nem `NOTICE`. A norma
permite cortar um arquivo do conjunto padrão com motivo escrito
([conventions.md §7](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md#7-the-repository-file-standard));
este ADR é o motivo. Decisão do dono, em 28/09/2026.

**Consequências.** O GitHub não mostra selo de licença, e é o certo: sem
`LICENSE`, o código fica sob todos os direitos reservados por padrão. A
verificação de conformidade do repodocs que compara o `LICENSE` com o da frota
(`diff LICENSE …/repodocs/LICENSE`, runbook §7 de lá) acusa falta aqui, e essa
falta é esperada — o [repodocs.md](repodocs.md) registra o corte no manifesto.
**Se o repositório um dia for aberto, este ADR é revisto antes**, e o primeiro
passo do checklist em [runbook.md](runbook.md) é decidir a licença, com o
`NOTICE` que concorda com ela: repositório público sem licença é juridicamente
fechado, o que quase nunca é a intenção de quem abre. Uma dependência de
terceiro cuja licença exija atribuição traz o `NOTICE` de volta no mesmo commit
que traz a dependência.

---

## ADR-002 — CI roda no servidor `cicd`, não nos runners hospedados do GitHub

**Status:** `ACCEPTED` · 28/09/2026

**Contexto.** No primeiro push deste repositório, os dois runs do workflow de
Release (`36465790740` para a 0.1.0 e `36465902322` para a 0.1.1) falharam em
4–5 s, sem executar nenhum passo. O GitHub recusou o job: *"The job was not
started because recent account payments have failed or your spending limit
needs to be increased"*. Não é deste repositório: o ONLINE, da mesma
organização, falha igual desde pelo menos 24/09/2026. A cota de minutos
hospedados da `BLUE3-ISP` está travada. As duas Releases foram publicadas à mão,
com `./tools/release.sh --backfill`.

A casa já tem a resposta para isso: o servidor `cicd` (`100.64.100.240`), uma
VM que roda os jobs do GitHub Actions dos repositórios privados em runners
self-hosted, sem gastar os minutos hospedados. Ele foi montado em 28/09/2026
para os repositórios do ShvIA, pelo mesmo motivo, e o runbook dele vive em
[samirhvbr/shvia-web `deploy/servidor-cicd.md`](https://github.com/samirhvbr/shvia-web/blob/master/deploy/servidor-cicd.md).

**Decisão.** Decisão do dono, em 28/09/2026: **este repositório não depende dos
runners hospedados do GitHub.** Quando precisar de CI, ela roda no `cicd`,
acessado como `samir` (`ssh samir@100.64.100.240`), num runner self-hosted. O
caminho está no [runbook.md](runbook.md), §8. **Nada é registrado lá antes de
haver o que rodar** — hoje não há código nem suíte.

**Consequências.** Enquanto não houver runner, o `.github/workflows/release.yml`
continua no repositório, como o padrão manda, mas **não roda**: cada push que
bumpa o `version.md` deixa um run recusado no GitHub. A Release sai à mão: **quem
bumpa o `version.md` roda `./tools/release.sh` depois do push**, na mesma
entrega, e confere a Release e o selo `Latest`. É o outro responsável que o
padrão já prevê ([versioning.md](versioning.md)), e o bump continua sendo um ato
só com a Release. Quando houver runner, o workflow volta a publicar sozinho pela
variável `CI_RUNNER`, sem mudar o script.

O endereço do servidor é interno e está escrito num repositório privado. Se o
repositório for aberto, ele sai da documentação e o runner sai do repositório
antes: um runner self-hosted num repositório público roda código de qualquer
fork dentro da rede do escritório (checklist do [runbook.md](runbook.md), §6).

---

## ADR-003 — estado gerado e estado local de execução ficam fora do git

**Status:** `ACCEPTED` · 29/09/2026

**Contexto.** O `.gitignore` deste repositório dizia uma coisa só: *tudo é
versionado, e a única exceção é segredo*, e exigia um ADR para qualquer outra
([conventions.md §7](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md#7-the-repository-file-standard)).
Isso servia enquanto o repositório só tinha documentação. A produção do produto
muda o quadro, e a evidência veio em minutos: rodar uma única vez o
`loop_ctl.py` da skill vendorizada deixou `__pycache__/` na árvore, e o primeiro
`composer install` deixa `vendor/` com dezenas de milhares de arquivos. Nenhuma
das duas coisas é fonte: ambas se refazem por um comando, e versioná-las tornaria
cada commit um ruído de binários.

**Decisão.** Ficam fora do git, e só estas, porque cada uma se **reconstrói**:

| Caminho | O que é |
|---|---|
| `vendor/`, `node_modules/` | Dependências instaladas; o `composer.lock` e o `go.sum`, esses sim versionados, dizem exatamente quais |
| `__pycache__/` | Bytecode gerado ao rodar a skill vendorizada |
| `dist/`, `agents/**/bin/` | Saída de build, inclusive o consolidado de `make docs-bundle` |
| `lab/.pgdata/`, `lab/.pgrun/` | O cluster PostgreSQL de desenvolvimento, em espaço de usuário, na porta 54329 |
| `.loop/STATE.json`, `.loop/entries/`, `.loop/INDEX.md`, `.loop/STOP` | Estado de **máquina** do loop: id de sessão, caminhos, o relato bruto de cada parada |

**O que continua versionado, e é a razão de a regra ser estreita:** tudo o que é
**memória** do loop — `.loop/QUEUE.md`, `.loop/SCOPE.md`, `.loop/ASSUMPTIONS.md`,
`.loop/SEM-ESCOPO`, `.loop/STATUS.md` e `.loop/loop.sh`. A skill diz que revisar
o `ASSUMPTIONS.md` *não é opcional*, porque é o preço de não ter sido
interrompido; uma fila e um registro de premissas que um `git clean` apaga
destroem exatamente o que a norma protege. Os `.gitignore` que o esqueleto do
Laravel traz dentro de `central/` (`storage/`, `bootstrap/cache/`, `public/build`)
são da mesma classe, gerado, e entram sem novo ADR.

**Consequências.** O `git status` deixa de acusar o que o build e a skill geram, e
o `fleet.sh check` do repodocs não é afetado: ele só reclama de ignorar
`CHANGELOG.md`, `version.md`, os gêmeos, os hooks, o `release.sh` e a
`.continue/`, e nenhum deles está na tabela. O custo é que **um segredo dentro
dessas pastas não seria visto pelo git**: a regra do `.env` e das chaves não
muda, e `lab/.pgdata/` guarda um banco de desenvolvimento **sem dado de produção**
— uma senha de produção ali seria um defeito, não uma exceção. Qualquer caminho
novo fora do git repete este ADR ou nasce de um novo.

---

## ADR-004 — o 2.0 complementa o 1.0, e no conflito vale o 2.0

**Status:** `ACCEPTED` · 29/09/2026 · Decisão do dono

**Contexto.** O material do produto chegou em dois pacotes, ambos em `.continue/`: o 1.0
(`b3monitor/`, documentos 00–13) e a revisão 2.0 (`b3monitor_2.0/`). O 2.0 trouxe uma
`REVISAO_1.0_PARA_2.0.md` que corrige 23 pontos técnicos (C-01…C-23), propõe oito decisões
(D-01…D-08) e aponta oito lacunas (L-01…L-08), mas só quatro dos dezessete arquivos que ela
planejava (README, glossário 14, rascunho do 02 e a própria revisão) foram escritos. Os "lotes
A, B e C" de regeneração dos demais ficaram condicionados às respostas.

**Decisão.** **O 2.0 complementa o 1.0; no que conflitar, vale o 2.0.** Os documentos 1.0
**não são reescritos**: continuam a base. O que o 2.0 sobrescreve vive na REVISAO e no índice
`.continue/b3monitor_2.0/PRECEDENCIA.md` (item A3 da fila). Dentro de um mesmo pacote vale a
ordem do README 2.0: limites de segurança (07) › estados (03) › prova de caminho (05) ›
contratos (06) › fases (09), e o glossário 14 define os enums.

**Consequências.** Os lotes A, B e C deixam de existir, e o custo de regenerar quatorze
documentos some. O custo que aparece é de leitura: quem abre um documento 1.0 sem o índice lê
Docker, Blackbox e Redis como se valessem. Por isso o `CLAUDE.md` manda ler o `PRECEDENCIA.md`
antes de cada documento 1.0 e o item A3 verifica por `grep` que esses termos só aparecem em
trechos marcados como sobrescritos. A regra **não** cobre conflito entre o 2.0 e a norma da
frota (o repodocs): nesse eixo a norma do repositório decide, e cada caso é registrado como
premissa em `.loop/ASSUMPTIONS.md` (hoje, só o local dos ADRs).

---

## ADR-005 — D-01: Debian 13 bare-metal com systemd, e o Compose sai do pacote

**Status:** `ACCEPTED` · 29/09/2026 · Decisão do dono (D-01 da revisão 2.0)

**Contexto.** O 1.0 dizia Docker Compose para desenvolvimento e produção. A operação da Blue3 é
Debian bare-metal, sem Docker, administrada como root, com XFS ou Btrfs e deploy por rsync. A
seção de segurança do 1.0 (07 §10) gastava metade do texto com riscos de contêiner que a
produção não terá.

**Decisão.** Central em Debian 13 (Trixie), Apache2 + PHP-FPM, PostgreSQL e workers como
units systemd. A sonda é um binário Go estático numa unit endurecida, com usuário próprio.
O Docker Compose sai do pacote.

**Consequências.** Somem o socket do Docker, `privileged`, `NET_ADMIN` e as tags `latest`; o
endurecimento passa a ser de systemd (`ProtectSystem=strict`, `NoNewPrivileges`,
`CapabilityBoundingSet`, `RestrictAddressFamilies`, `SystemCallFilter=@system-service`; C-07), e
07 §10 e 11 §1–3 encolhem. O laboratório deixa de ser Compose e passa a ser `make lab-up` com
namespaces de rede no host (C-21). O custo: não há imagem reproduzível; a reprodutibilidade vem
do `make`, das units e das versões fixadas ([ADR-013](#adr-013--versões-fixadas-da-stack-e-toolchain-de-go-pinada-pelo-gomod)).
Esta estação tem Docker instalado, mas a stack não o usa. **Se o dono discordar:** 07 §10 e 11
ficam como no 1.0, e um ADR registra o Compose.

---

## ADR-006 — D-02: executor da sonda nativo em Go, sem Blackbox Exporter

**Status:** `ACCEPTED` · 29/09/2026 · Decisão do dono (D-02)

**Contexto.** O 1.0 deixava o Blackbox Exporter "a avaliar". Examinado, ele re-resolve DNS
(o que torna o T-052 um problema estrutural), prefere IPv6 com fallback para IPv4 por padrão
(viola o RC-04, que exige famílias independentes), expõe só o hash do IP efetivo (o 06 §5 exige
o IP), abre uma porta local (T-057) e não roda um executor por namespace de caminho.

**Decisão.** Executor nativo em Go: `net/http` com `DialContext` fixado ao IP validado e
`TLSClientConfig.ServerName`, `crypto/tls`, `golang.org/x/net/icmp`, `github.com/miekg/dns` e
`net.Dialer`. O Blackbox Exporter é descartado. O executor implementa a interface `Executor` do
coordenador.

**Consequências.** `executor.name` passa a ser `native` e `executor.build` guarda a versão ou o
commit (C-05); o T-057 vira T-057′ (executor sem porta de escuta) e nasce o T-076 (executor em
namespace só enxerga as rotas do namespace). É menos código do que o adaptador mais as
mitigações. O custo é manter o executor. Um adaptador externo pode voltar por ADR, sujeito a
T-052 e T-057′. **Se o dono discordar:** adaptador do Blackbox mais teste de rebinding e T-057.

---

## ADR-007 — D-03: PostgreSQL 17 na central

**Status:** `ACCEPTED` · 29/09/2026 · Decisão do dono (D-03)

**Contexto.** O 1.0 já dizia PostgreSQL; a revisão fixa a versão e o motivo. Particionamento
declarativo, JSONB para evidências, DDL transacional (partições criadas em migração) e
`SKIP LOCKED` servem ao desenho; a Blue3 já opera PostgreSQL.

**Decisão.** **PostgreSQL 17**, do repositório do Debian 13. A estação de desenvolvimento tem a
17.11, último minor do 17, que o projeto mantém até 08/11/2029 (A1). A major atual é a 18, e ela
**não** foi escolhida: o pacote do Trixie é o que a operação mantém.

**Consequências.** As restrições do 06 §3 e §9 valem como estão: a chave única inclui a coluna
de partição, e não há chave estrangeira em tabela particionada. **Se o dono discordar:** MariaDB
11.8 por ADR, com 06 §3 e §9 reescritos; as duas restrições valem nos dois bancos.

---

## ADR-008 — D-04: sem Redis no caminho crítico

**Status:** `ACCEPTED` · 29/09/2026 · Decisão do dono (D-04)

**Contexto.** O 1.0 punha o Redis como transporte de trabalho, com um reconciliador para a
perda entre banco e fila. A carga prevista é de 41,7 resultados por segundo em lotes de 500, o
que não justifica um broker.

**Decisão.** `ingestion_work` e a outbox ficam no PostgreSQL, consumidos com
`FOR UPDATE SKIP LOCKED`; os workers são `php artisan queue:work` com o driver `database`, sob
systemd; o cache é `file` ou `database`. Redis ou Valkey só entram por ADR depois do benchmark
do F1-08.

**Consequências.** Um serviço a menos para backup, monitoramento e explicação, e o T-039 deixa
de existir. O reconciliador continua, agora sobre leases vencidos no próprio banco (B4 da fila).
A extensão `redis` do PHP está instalada nesta estação, mas a stack não a usa. **Se o dono
discordar:** Redis ou Valkey, com o reconciliador e o T-039 obrigatórios.

---

## ADR-009 — D-05: o mobile é um módulo do app BLUE3 existente

**Status:** `ACCEPTED` · 29/09/2026 · Decisão do dono (D-05)

**Contexto.** O 1.0 previa um app Flutter novo para iOS e Android. O app BLUE3 já está na App
Store e a caminho do Play, com push FCM e APNs, armazenamento seguro, Riverpod, GoRouter, Dio e
um padrão de módulos. Um segundo app dobraria assinatura, listagem, revisão e atualização forçada.

**Decisão.** Módulo `monitoramento` em `lib/features/` do app BLUE3, na área de funcionários,
com **boot e autenticação independentes do backend da intranet**: token próprio da central, cache
próprio, sem passar pelo `EnsureClientVersion` do backend mobile.

**Consequências.** F1-05 e F1-06 viram um módulo, e o módulo precisa abrir, autenticar e mostrar
incidentes com o backend da intranet fora do ar (INV-12 e T-075); sem isso, a razão de existir do
produto some. O código do módulo mora em outro repositório (`BLUE3-INTRANET-MOBILE`), então
**qualquer alteração lá é decisão do dono** e fica fora desta rodada. **Se o dono discordar:** app
separado, com 08, F1-05, F1-06 e G-MOBILE como no 1.0.

---

## ADR-010 — D-06: painel em Blade, Livewire e CoreUI 5

**Status:** `ACCEPTED` · 29/09/2026 · Decisão do dono (D-06)

**Contexto.** O 1.0 dizia apenas "server-driven do ecossistema Laravel". A intranet `sys` usa
Blade e CoreUI, e o dono já impõe aos projetos legibilidade em 1366×768 e sessão longa.

**Decisão.** Blade + Livewire + CoreUI 5, legível em 1366×768 sem rolagem horizontal; sessão
longa quando a origem é IP interno (`INTERNAL_SESSION_IPS`, o padrão do `sys`); 2FA de admin
sempre.

**Consequências.** Consistência de componentes com o `sys`. A regra de sessão longa depende de
saber o IP de saída do escritório (Q-03, aberta); até lá, só essa regra espera. **Se o dono
discordar:** Filament ou Inertia por ADR.

---

## ADR-011 — D-07: Go na sonda e no vigilante, com a mesma toolchain

**Status:** `ACCEPTED` · 29/09/2026 · Decisão do dono (D-07)

**Contexto.** A sonda e o vigilante são daemons 24×7 com ICMP, namespace de rede e SQLite, em
VMs que não devem carregar um runtime. PHP na linha de comando não atende 04 §3 e §9 nem o 07.

**Decisão.** Go nos dois, com a mesma toolchain fixada ([ADR-013](#adr-013--versões-fixadas-da-stack-e-toolchain-de-go-pinada-pelo-gomod)) e binário estático.

**Consequências.** O aprendizado de Laravel fica onde está a lógica, na central. O custo é manter
duas linguagens no monorepo, com contratos compartilhados validados nos dois lados (A8).

---

## ADR-012 — D-08: monorepo próprio, sem diretório mobile

**Status:** `ACCEPTED` · 29/09/2026 · Decisão do dono (D-08)

**Contexto.** O 1.0 previa um monorepo com `apps/mobile`. Pela D-05 o módulo Flutter vive em
outro repositório. A revisão citava a organização `blue3-internet`; a organização real, onde este
repositório foi criado, é `BLUE3-ISP`.

**Decisão.** Este repositório é o monorepo: `central/`, `agents/probe/`, `agents/watchdog/`,
`contracts/`, `deploy/`, `lab/`, `docs/` e `evidence/`, **sem** diretório mobile. O módulo
Flutter, em `BLUE3-INTRANET-MOBILE`, consome `contracts/openapi` da central, por cliente gerado
ou cópia versionada do contrato.

**Consequências.** O contrato é o ponto de acoplamento entre dois repositórios, e por isso é
validado em CI nos três lados (Go, PHP, Dart). O slug de trabalho `b3mon` segue (Q-04,
assumida) para units, diretórios e tabelas.

---

## ADR-013 — versões fixadas da stack e toolchain de Go pinada pelo `go.mod`

**Status:** `ACCEPTED` · 01/10/2026 · Decisão do agente na rodada do loop, reversível por novo ADR

**Contexto.** A inspeção do A1 ([docs/status/SESSOES.md](status/SESSOES.md)) leu as versões
instaladas e as suportadas pelos mantenedores em 01/10/2026. Dois fatos pesam: o **Go 1.24.4 desta
máquina está fora da política de suporte** (mantidas: 1.27.1 e 1.26.8), e os módulos da sonda
pedem `go 1.26.0` (`x/net`, `sqlite`) e `go 1.25.0` (`miekg/dns`); e o **Pest 5 exige PHP 8.4 ou
mais novo**.

**Decisão.** Ficam fixadas as versões abaixo. Tags flutuantes e `latest` **não** aparecem em
homologação; as versões efetivas ficam no `composer.lock`, no `go.sum` e no `package-lock.json`,
versionados.

| Peça | Fixada | Observação |
|---|---|---|
| PHP | 8.4 (piso, por causa do Pest 5) | 8.4.26 instalado; suporte ativo até 31/12/2026. Reavaliar o 8.5 no 4º trimestre |
| Laravel | `^13.0` | 13.34.0 em 29/09/2026 |
| Livewire | `^4.0` | 4.4.7 |
| Pest | `^5.0` | 5.2.1 |
| CoreUI | `^5.9` (npm `@coreui/coreui`) | 5.9.0; só no painel |
| Node | 24 | só para compilar assets; em manutenção a partir de 20/10/2026 |
| PostgreSQL | 17 | [ADR-007](#adr-007--d-03-postgresql-17-na-central) |
| Go | `go 1.26.0` mais `toolchain go1.26.8` no `go.mod` | ver abaixo |
| `golang.org/x/net` | v0.59.0 | pacote `icmp` |
| `github.com/miekg/dns` | v1.1.73 | |
| `modernc.org/sqlite` | v1.60.1 | driver SQLite em Go puro, segundo o projeto; a ausência de cgo se prova no A7 com `CGO_ENABLED=0` |

**Toolchain de Go.** Escolhida a **toolchain automática**: o `go.mod` declara
`toolchain go1.26.8`, e o Go (com `GOTOOLCHAIN=auto`, o padrão desta máquina) baixa essa versão do
proxy oficial, conferida pelo banco de checksums. É a opção que se reproduz com um arquivo
versionado e dispensa `sudo`. Descartada: instalar o Go 1.26 em espaço de usuário, que deixa a
versão fora do repositório.

**Consequências.** O primeiro `go test` baixa a toolchain pela rede (tamanho não medido), e uma
máquina sem rede, como um runner de CI isolado, precisa dela já presente. O 1.26 é a mais antiga
das duas séries mantidas, e o 1.27 entra por novo ADR. Composer, git e make não foram verificados
em fonte oficial. **Reverter:** trocar a linha `toolchain` e, se for o caso, instalar o Go à parte.

**Atualização de 02/10/2026 (item A6).** Ao criar a central, o `composer create-project` do
Laravel 13 trouxe `pestphp/pest ^4.7` e `phpunit/phpunit ^12.5` já fixados no `composer.json`
do esqueleto, e o `composer require pestphp/pest:^5.0` sozinho **falhou**: o Pest 5 exige o
**PHPUnit 13**. A central sobe os dois juntos, e o resultado instalado é Pest **5.3.0** (a
tabela diz 5.2.1, que era a última em 01/10), `pestphp/pest-plugin-laravel` 5.0.1 e PHPUnit
**13.3.6**, com Laravel 13.34.0. O que o ADR fixou (`^5.0`) continua verdadeiro; o que faltava
nele era a exigência do PHPUnit 13, que o esqueleto do Laravel ainda não acompanha.

---

<!-- Modelo para as próximas entradas:

## ADR-NNN — <a decisão, como frase>

**Status:** `ACCEPTED` · DD/MM/AAAA

**Contexto.** O que era verdade antes e o que forçou uma escolha. Fatos e
medições, não opiniões — se um número decidiu, o número entra aqui.

**Decisão.** O que foi decidido, no imperativo. Uma decisão por ADR.

**Consequências.** O que isto facilita, o que dificulta e o que precisa mudar
em outro lugar por causa disto. Diga o custo — um ADR que só lista benefícios é
uma decisão que não foi pensada.

Candidatos aos próximos ADRs deste projeto, quando o material sair da
.continue/: a stack e por quê; o regime de branch e de release; o que os slots
de versão querem dizer aqui; onde os segredos vivem; o que o agente pode e não
pode rodar. -->
