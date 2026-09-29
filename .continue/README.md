# `.continue/` — a fila

> **Status:** `ACTIVE` · Revisado em 29/09/2026, repositório na 0.1.3

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

**Regra de leitura dos dois pacotes (decisão do dono, 29/09/2026): o 2.0
complementa o 1.0, e no que conflitar vale o 2.0.** O 1.0 continua a base e não é
reescrito; o que o 2.0 muda está na `REVISAO_1.0_PARA_2.0.md` (D-01…D-08,
C-01…C-23, L-01…L-08). Dentro do mesmo pacote vale a ordem do README 2.0: limites
de segurança (07) › estados (03) › prova de caminho (05) › contratos (06) › fases
(09), e o glossário 14 define os enums.

| Item | Estado | Quem destrava |
|---|---|---|
| [`b3monitor/`](b3monitor/README.md) — pacote **1.0** da plataforma de monitoramento distribuído (docs 00–13; o `README.md` dele é o consolidado inteiro, idêntico byte a byte a `blue3_monitoramento_documentacao_consolidada_v1.0.md`) | Nada implementado. É a base da produção; começa por `00_PROMPT_PARA_O_AGENTE.md` | Samir — quando a F0-01 existir, o que ela descreve migra para `docs/` |
| [`b3monitor_2.0/`](b3monitor_2.0/README.md) — revisão **2.0**: README, glossário (14), arquitetura (02, rascunho) e `REVISAO_1.0_PARA_2.0.md`; `files.zip` é o pacote original desses quatro | Vale onde conflita com o 1.0. **D-01…D-08 aceitas em 29/09/2026**; o que sobrescreve o quê vira `PRECEDENCIA.md` (item A3 da fila do loop) | Samir — só as perguntas abertas da §3 |

## 2. Para onde as coisas foram

<!-- Quando um documento sair para ../docs/, ele ganha uma linha aqui, com link
     relativo. É isso que impede "engana quem abre" de virar "ninguém acha". -->

| Estava aqui | Agora está em |
|---|---|
| _(nada ainda)_ | |

## 3. Decisões pendentes

| Decisão | De quem | Nota |
|---|---|---|
| D-01…D-08 da revisão 2.0 (bare-metal/systemd, executor Go nativo, PostgreSQL 17, sem Redis no caminho crítico, painel Blade+Livewire+CoreUI, módulo no app BLUE3, monorepo próprio) | Samir | **Aceitas em 29/09/2026** — viram ADR em F0-00 (docs/decisions.md) |
| Q-02: fabricante, modelo e versão da borda de rede | Samir / NOC | Aberta. Bloqueia só o G-UP real (prova de caminho do upstream); o Anexo A do 05 segue genérico até lá |
| Q-03: IP de saída do escritório que a central verá (`170.233.230.254` ou `170.233.231.251`) | Samir | Aberta. Só a regra de sessão longa do painel depende dela |
| Q-04: `b3mon` como slug de trabalho | — | Assumida: `b3mon` segue até haver nome final (também aberta como decisão de produto) |
| ADRs em `docs/decisions.md` (norma da frota) e não em `docs/adr/` (14 §5 do 2.0) | Samir | Assumido pelo agente, reversível: é conflito entre o 2.0 e a norma do repo, eixo diferente da regra 2.0 › 1.0. Confirmar ou trocar |
