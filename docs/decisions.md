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
