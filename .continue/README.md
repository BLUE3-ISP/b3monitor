# `.continue/` — a fila

> **Status:** `ACTIVE` · Revisado em 28/09/2026, repositório na 0.1.0

Trabalho em andamento: rascunhos, planos em discussão, notas sobre o que ainda
está sendo construído, briefings para retomar o trabalho depois.

**A norma que esta pasta obedece vive num lugar só, no padrão da frota:**
[samirhvbr/repodocs `docs/conventions.md`](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md)
— §1 (fila × registro) e §2 (este README é obrigatório). Leia lá; uma cópia
aqui seria uma segunda fonte de verdade.

## Como funciona

- **Versionada no git de propósito — deliberadamente FORA do `.gitignore`.**
  Abrir o projeto em outra máquina traz o contexto junto, e esse é o ponto
  inteiro: dá para *continuar* de onde parou.
- **Nada aqui é fonte de verdade.** No momento em que um documento descreve algo
  que já existe, ele vai para [`../docs/`](../docs/), e o registro permanente de
  *quando* é o [`../CHANGELOG.md`](../CHANGELOG.md).
- **Item terminado sai — e *terminado* quer dizer que a coisa existe.** Ele é
  apagado aqui, não riscado; o registro permanente da conclusão é o changelog.
  Uma fila com item já feito custa mais que uma fila incompleta: faz a próxima
  sessão refazer trabalho.
- **Nada sai por outro motivo.** Nem tamanho, nem idioma, nem fim de sessão, nem
  agente que teria escrito diferente. **Produzir um item é fazer a coisa
  existir**, e apagar o documento é o último passo do commit que carrega o
  trabalho — nunca um passo sozinho.
- **Se um item precisa de meia página para descrever algo que já existe**, ele
  está no lugar errado. Escreva em `../docs/` e deixe aqui uma linha e um
  ponteiro.
- **Numa contradição entre esta pasta e um documento em `../docs/`, o documento
  vence.**
- A IDE **Continue** também usa esta pasta para a própria configuração.

## 1. O que ainda está aqui

<!-- Uma linha por item. Apague a linha quando o item estiver feito — não
     risque. -->

| Item | Estado | Quem destrava |
|---|---|---|
| _(nada ainda)_ | | |

## 2. Para onde as coisas foram

<!-- Quando um documento sair para ../docs/, ele ganha uma linha aqui, com link
     relativo. É isso que impede "engana quem abre" de virar "ninguém acha". -->

| Estava aqui | Agora está em |
|---|---|
| _(nada ainda)_ | |

## 3. Decisões pendentes

| Decisão | De quem | Nota |
|---|---|---|
| O que o b3monitor é: escopo, stack, onde roda | Samir | O material de definição vem para esta pasta; até lá o README, o `CLAUDE.md` e o `docs/runbook.md` ficam marcados como _a preencher_ |
