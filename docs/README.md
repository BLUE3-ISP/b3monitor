# Documentação — b3monitor

> **Status:** `ACTIVE`

Índice da documentação **estável** do projeto. Trabalho em andamento vive na
[`../.continue/`](../.continue/) e migra para cá quando amadurece.

Este índice é curado, não exaustivo. Mantenha assim: um sumário que lista tudo
deixa de ser lido.

## Páginas

| Documento | O que responde |
|---|---|
| [versioning.md](versioning.md) | Como se define uma versão e se escreve um commit. O `version.md` é a autoridade única, e a versão é o **primeiro semver dele**; os critérios de `X`/`Y`/`Z` **deste projeto**; `X.Y.Z - descrição em português` — e a convenção da casa, num repositório que não é nosso; **[tags e Releases](versioning.md#tags-e-releases)**; o que os dois hooks de git conferem. |
| [decisions.md](decisions.md) | **ADRs** — o registro cronológico do que foi decidido aqui e por quê, para não ser rediscutido. |
| [security.md](security.md) | O documento normativo de segurança. Num conflito com qualquer outro documento, ele vence. |
| [runbook.md](runbook.md) | Da máquina limpa até o ambiente rodando; deploy; o checklist antes de tornar o repositório público. |
| [repodocs.md](repodocs.md) | **O que neste repositório veio do padrão da frota, e onde cada peça vive.** O mapa da relação: o que sai do repodocs por cópia, o que sai por carimbo e o que só é linkado; o manifesto dos arquivos e o que se perde quando falta um; como uma regra da frota chega a este repositório. |

<!-- Acrescente as páginas do próprio projeto conforme forem escritas. As que
     se repetem na frota são architecture.md, glossary.md e um playbook. -->

## A norma

A convenção de documentação que este repositório segue — fila × registro ×
histórico, nome de arquivo que carrega estado, o vocabulário de status, a regra
de idioma — vive num lugar só, no padrão da frota:
[samirhvbr/repodocs `docs/conventions.md`](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md).
Ela **não** é copiada aqui, de propósito.

O [repodocs.md](repodocs.md) é o mapa dessa relação visto daqui: quais arquivos
vieram do padrão, quais deles são espelhos regenerados, e quais perguntas são
respondidas lá em cima em vez de aqui.

## Para onde vai um documento novo

| Ele descreve… | Ele vai para |
|---|---|
| algo que já existe — uma medição, um contrato, um runbook, um ADR | `docs/` |
| algo ainda por fazer — **de qualquer tamanho**, na forma que estiver | `.continue/` |
| algo que aconteceu, com data e porquê | `CHANGELOG.md` |

**Um documento sai da `.continue/` quando, e somente quando, a coisa que ele
descreve existe.** Tamanho não é condição de saída, nem idioma, idade ou
desalinho. Um brief de 1.300 linhas sobre algo não construído está no único
lugar onde pode estar — ele *é* a especificação, e até a coisa existir é o único
lugar onde ela existe. *Produzir* um item da fila é fazer a coisa existir, não
editar, traduzir ou promover o documento. **Nunca esvazie aquela pasta como
arrumação.**

Se um item da fila precisa de meia página **para descrever algo que já existe**,
é um registro que foi parar na fila: escreva aqui e deixe lá uma linha e um
ponteiro. Esse é todo o alcance da regra.
