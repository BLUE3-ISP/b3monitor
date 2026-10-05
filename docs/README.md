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
| [motor-de-estado.md](motor-de-estado.md) | **O que o motor de estado faz hoje**: confirmação por perspectiva, elegibilidade, frescor e quórum por domínio; as decisões que o código tomou (e a divergência da C-01); o que ainda não está; e como foi provado, inclusive por mutação. |
| [modelo-de-dados.md](modelo-de-dados.md) | **O que o banco da central garante hoje**: as tabelas de resultados, ledger, estado, incidentes, notificação e auditoria; o que cada uma recusa por si; por que `results` é particionada e o que isso custa; a retenção validada; e o que ainda não existe. |
| [trabalho-assincrono.md](trabalho-assincrono.md) | **Como a central processa o que aceitou**: as três filas e suas tabelas, o ciclo de vida de um item, a lease e o reconciliador, o que acontece quando o worker morre, o item que mata o worker, o que `decide` e `notify` ainda não fazem, e como foi provado, inclusive por mutação. |
| [incidentes-e-notificacao.md](incidentes-e-notificacao.md) | **Como a central decide e avisa**: a decisão sobre o banco, o estado atual e o histórico, quando um incidente abre, atualiza e fecha, a outbox transacional, o canal `dev` simulado, por que replay não gera tempestade, o que o B5 NÃO faz (reabertura, instabilidade, cobertura perdida, canais reais) e como foi provado, inclusive por mutação. |
| [ingestao.md](ingestao.md) | **O que `POST /api/v1/probe/results` faz hoje**: a promessa (`accepted` só depois do commit durável), cada resposta e cada status, como a idempotência e os limites de transporte são garantidos, por que a validação é código próprio, o que ainda não está, e como foi provado, inclusive por mutação. |
| [status/SESSOES.md](status/SESSOES.md) | **O que o agente fez de fato, sessão por sessão** (append-only): o que mudou, os comandos realmente executados, o que não pôde rodar e a próxima fatia exata. Inclui a tabela de versões instalada × suportada × escolhida da toolchain. |
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
