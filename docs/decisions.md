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
