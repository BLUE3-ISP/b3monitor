# b3monitor — Instruções para o Claude Code

<!--
  O conteúdo abaixo do H1 é duplicado entre o CLAUDE.md (lido pelo Claude Code)
  e o AGENTS.md (lido por outras ferramentas, padrão agents.md) — mantenha os
  dois byte a byte idênticos abaixo do H1. Editou um, edite o outro.
-->

> **Leia nesta ordem:** [.continue/README.md](.continue/README.md) (a fila —
> onde paramos, **sempre primeiro**) · [docs/versioning.md](docs/versioning.md)
> (como se escreve uma versão e um commit) ·
> [docs/decisions.md](docs/decisions.md) (ADRs — não rediscuta direção decidida,
> linke o ADR) · [docs/security.md](docs/security.md) (normativo; vence qualquer
> conflito).
>
> **A norma de documentação da frota não está neste repositório.** Ela vive num
> lugar só, em
> [samirhvbr/repodocs `docs/conventions.md`](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md).
> Leia lá; não copie aqui.
>
> **O [docs/repodocs.md](docs/repodocs.md) é o mapa do que veio desse
> padrão** — quais arquivos daqui chegaram do skeleton, quais blocos abaixo são
> espelhos regenerados em vez de editados, e quais perguntas são respondidas lá
> em cima. Leia antes de criar, mover ou apagar um arquivo de documentação.

---

## 🔄 Antes de começar: `git pull`

**SEMPRE** confira se há atualização remota antes de escrever ou mudar qualquer
coisa neste repositório:

```bash
git pull
```

Trabalhar sobre base velha cria conflito. Pull primeiro, sempre. Para inspecionar
sem fazer merge: `git fetch && git status`.

**Clone novo — habilite os hooks UMA vez:**

```bash
git config core.hooksPath tools/git-hooks
```

Aí dois hooks passam a rodar, e cada um existe porque o outro não alcança o seu
momento:

| Hook | O que confere | Por que ali |
|---|---|---|
| `commit-msg` | O subject é `X.Y.Z - descrição`, e o `X.Y.Z` é o do `version.md` deste commit; sem prefixo de Conventional Commits, sem comentário vago | É o único momento em que a mensagem existe e o commit ainda não |
| `pre-push` | O `version.md` **do commit sendo enviado** contra o branch padrão remoto — duplicidade e monotonicidade, e só num push que atualiza esse branch | É o único hook que enxerga o **remoto**, e o `commit-msg` comprovadamente não roda durante um rebase, que é como nascem versões duplicadas |

⚠️ **O `pre-push` não roda suíte, e isso é escolha:** um push que espera quatro
minutos vira `--no-verify` na semana seguinte, e aí o controle morreu. **Sem
remoto alcançável ele degrada com aviso, nunca com recusa.** A válvula de escape
é declarada nos dois, pelo `HOOK_ESCAPE_VAR` no topo de cada hook — aqui,
`B3MONITOR_NO_HOOK=1`.

---

## O que é este projeto

**b3monitor** — Blue3 Monitor. **Ainda não há código:** em 28/09/2026 o
repositório guarda só a estrutura de documentação. O material de definição do
projeto vai para a [`.continue/`](.continue/README.md) e, quando o que ele
descreve existir, vira documento em `docs/`.

<!-- Padrão do skeleton a substituir pelo que um agente precisa de verdade: a
     stack, onde ficam os pontos de entrada, o que roda localmente e o que nunca
     deve ser tocado. -->

- **Stack:** _a preencher._
- **Roda localmente com:** _a preencher._
- **Testes rodam com:** _a preencher — um comando que qualquer pessoa repete._
- **Nunca toque:** _a preencher._

---

## Regras de ouro

1. **A `.continue/` é a fila · `docs/` é o registro · o `CHANGELOG.md` é o
   histórico.** Um documento sai da fila quando — e **somente** quando — a coisa
   que ele descreve **existe**. Tamanho, idioma e desalinho não são condição de
   saída. *Produzir* um item da fila é fazer a coisa existir, não editar,
   traduzir ou promover o documento; apagá-lo é o último passo do commit que
   carrega o trabalho. **Nunca esvazie essa pasta como arrumação.**
2. **Se um item da fila precisa de meia página para descrever algo que já
   existe, ele está no lugar errado.** Escreva em `docs/` e deixe uma linha e um
   ponteiro. Isso não diz nada sobre o tamanho de uma especificação de trabalho
   ainda não feito: um brief de 1.300 linhas sobre algo não construído está no
   único lugar onde pode estar.
3. **Numa contradição entre a fila e um documento, o documento vence.**
4. **Todo documento prescritivo declara o seu status** nas primeiras linhas:
   `ACTIVE` · `HISTORICAL` · `PROPOSED` · `DEPRECATED` · `NOT ADOPTED`. Um sem
   declaração é lido como `ACTIVE`, que é exatamente o modo de falha.
5. **Um documento que uma mudança deixou velho é corrigido na mesma passada.**
   Documento que envelhece em silêncio é pior que documento que falta, porque
   tem a autoridade de estar escrito.
6. **`CLAUDE.md` e `AGENTS.md` são byte a byte idênticos abaixo do H1.** Editou
   um, edite o outro.
7. **Tudo é versionado; a única exceção é segredo.** `.claude/` e `.continue/`
   são versionadas de propósito. Uma exceção nova no `.gitignore` além de
   segredo exige um ADR, nunca uma linha silenciosa.
8. **Conceder uma permissão ao agente é ato do dono** — escrita no
   `.claude/settings.json` com o motivo e com o jeito de reverter, nunca
   aplicada em silêncio e nunca deixada como promessa em prosa.
9. **Uma decisão nova vira ADR** em `docs/decisions.md`, na mesma passada.
10. **Você commita, e nada está terminado antes disso.** O commit é o último
    passo da tarefa, não um acompanhamento — nunca relate trabalho como feito
    enquanto ele estiver sem commit. Um assunto por commit; entrega grande é
    dividida em blocos.

---

<!-- QUEUE-RULE:repodocs -->

## A fila esvazia por produção, e por mais nada

> Eco marcado. A fonte única é **[samirhvbr/repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md#1-continue-is-the-queue--docs-is-what-has-been-produced)**
> — mude lá, não aqui. Este bloco é regenerado.

**A `.continue/` guarda trabalho que ainda não existe.** Um documento só sai dela
quando — e **somente quando** — a coisa que ele descreve **existe**. Tamanho não
é condição de saída. Idade, idioma, desalinho, fim de sessão e agente que teria
escrito diferente também não.

> `tela.md` diz *"uma tela preta com uma bola amarela no meio"*. Ele sai da fila
> quando existir uma tela preta com uma bola amarela. Até lá ele fica, do
> tamanho que for, na forma em que estiver — porque até lá ele é o único lugar
> onde essa tela existe.

**"Produzir" um item da fila é fazer a coisa existir.** Não é editar o
documento, não é traduzir, não é promover para `docs/`. O documento é a
especificação; a entrega é a coisa. Apagar o documento é o **último passo do
commit que carrega o trabalho** — nunca um passo sozinho.

**Nunca esvazie essa pasta como arrumação.** Um item apagado sem o trabalho
feito destrói o único artefato que um projeto tem antes de ter código — e o que
costuma ficar no lugar é pior que a perda: uma página em `docs/` descrevendo uma
tela que ninguém construiu, indistinguível de uma que descreve algo que existe.
Se um plano precisa aparecer em `docs/` antes de ser construído, ele é
`PROPOSED`, nunca `ACTIVE`.

**A regra da meia página é sobre registro que foi parar na fila**, e sobre nada
mais. Ela não tem opinião sobre o tamanho de uma especificação de coisa não
feita: um brief de 1.300 linhas sobre algo que não existe está no único lugar
onde pode estar. Item longo é projeto com muita coisa por construir.

<!-- /QUEUE-RULE -->

<!-- LANGUAGE-RULE:repodocs -->

## Idioma — este repositório é escrito em português

> Eco marcado. A fonte única é **[samirhvbr/repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md#the-blue3-internal-repositories-are-written-in-portuguese)**
> — mude lá, não aqui. Este bloco é regenerado.

**Tudo que vive neste repositório, ou na interface do GitHub em volta dele, é
escrito em português**: documentos, **mensagens de commit**, títulos e corpos de
pull request, issues, comentários de código, entradas de changelog, notas de
release.

Este é um dos dois repositórios-exceção da regra de inglês da frota, e a
fronteira é o **dono no GitHub**: `BLUE3-ISP/*`, `samirhvbr/blue3-intranet` e
`samirhvbr/blue3-ai-login`. O motivo é o leitor: ninguém de fora da empresa abre
estes repositórios, e boa parte do que se escreve neles é raciocínio operacional
— um incidente, uma fila, um runbook — onde a precisão da frase é o valor.
Traduzir custa mais do que rende.

**Só o idioma muda.** Formato do commit: `X.Y.Z - descrição curta em português`.
A versão vem do `version.md` e é bumpada no mesmo commit. Prefixos de
Conventional Commits (`feat:`, `fix:`, `chore:`) e mensagens vagas de uma
palavra continuam proibidos, um assunto por commit continua valendo, e a entrada
do `CHANGELOG.md` continua sendo escrita antes — o cabeçalho dela **é** o
subject.

**Identificadores de código são em inglês**, aqui como em qualquer repositório
da frota.

O histórico não se reescreve: mensagem em inglês que já está no log fica como
está.

**A `.continue/` é fila, não registro.** Um documento só sai dela quando a coisa
que ele descreve **existe** — tamanho, idioma e desalinho não são condição de
saída. *Produzir* um item da fila é fazer a coisa existir, não editar, traduzir
ou promover o documento; apagá-lo é o último passo do commit que carrega o
trabalho. **Nunca esvazie essa pasta como arrumação.**

**Num repositório que não é nosso, a convenção do upstream vence** — o idioma e
o formato do commit. Abrir um pull request ou uma issue num repositório de
terceiro faz de nós convidados, e convidado escreve na língua da casa. Nosso
`X.Y.Z - descrição` não significa nada lá: eles não têm um `version.md` nosso, e
não há versão nossa para bumpar. Quando não der para saber, escreva **inglês
(US)** — é a regra da casa da frota.

<!-- /LANGUAGE-RULE -->

---

## Branch

**`master`, nunca `main`.** O branch padrão de todo repositório desta frota é
`master` — convenção da casa, para que todo script, hook e runbook possa dizer
`origin/master` e acertar. Um repositório criado como `main` é renomeado pelo
rename do GitHub (ele mantém os PRs abertos e redireciona os links antigos); todo
clone existente depois precisa de `git branch -m main master`, `git fetch origin`,
`git branch -u origin/master master` e — o passo que as pessoas pulam —
`git remote set-head origin -a`.

Um branch diferente só vale **como decisão escrita**, registrada em
`docs/decisions.md`.

Norma: [samirhvbr/repodocs `docs/conventions.md`](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md).

---

<!-- COMMIT-RULE:repodocs -->

## Commits — you commit, and nothing is delivered until you have

> Marked echo. The single source is **[samirhvbr/repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/versioning.md#who-commits-and-when)**
> — change it there, not here. This block is regenerated.

**Committing is your job.** Not "leave the tree ready and something downstream
packages it" — you run `git commit`, and `git push`, as the last step of the work
you were asked to do. The COMMITTER skill that used to commit on an agent's
behalf is `enabled: false` in every repository of this fleet since 03/09/2026;
what is left of it is a kill-switch, not a scheduler. **If you do not commit,
nobody does.**

**Do not report a task as finished before the commit exists.** "Done",
"delivered", "concluded" mean the work is in `git log` — never that it is sitting
uncommitted where only this session can see it. The commit is the last step *of
the task*, not a follow-up for someone else. If you are about to write
"finished", commit first, then write it.

**Push is part of the delivery, and a refused push is the one place a human enters.**
Commit *and* push, every delivery — a clean push needs nobody's permission and is never
held back for review. When the push is **refused** (conflict, non-fast-forward, protected
branch), stop there and say so: never force, never rewrite history to get past it, never
invent a merge resolution you have not verified. The gate is the refused push, not the
commit.

**Every commit obeys the versioning rules**, with no exception:

- Subject `X.Y.Z - short description in English (US)`, the version taken from
  `version.md` and **bumped in the same commit**.
- The `CHANGELOG.md` entry is written first — its `## X.Y.Z - description`
  heading *is* the subject.
- No Conventional Commits prefix (`feat:`, `fix:`, `chore:`) and no vague
  subject ("update", "ajuste", "wip", "changes", "several improvements").

**The bump is the one clause a repository may override — in writing.** If this
repository's own documentation says the version is stamped some other way, and says
why, follow that. Otherwise the line above applies to you. An override nobody wrote
down is not an exception. Nothing else in this block bends: the changelog entry, the
subject, the language, one subject per commit, and committing before you report done
all hold regardless.

**An override moves *when* the version is decided, never *whether* every commit
carries it.** A delivery split into blocks — the default — must come out with the
version on **every** subject, not on the last one. A placeholder left in a subject
that reaches the default branch is a defect and is permanent, because the default
branch is not rewritten. Measured: 26 of them in the one repository that stamps at
merge, before its mechanism was fixed.

**All of this governs the repositories we own.** In a repository that is not
ours, the host's commit convention governs instead — their subject line, in
their language. `X.Y.Z` is meaningless where there is no `version.md` of ours,
and there is no version there for us to bump. Our versioning rules govern our
remotes, not every remote we can push to.

**One subject per commit.** The subject has to describe the whole commit
honestly. The moment your description needs an "and" to be true, it is two
commits.

**Split a large delivery into blocks.** A complex task is committed as a series
of commits grouped by subject, each small enough to be described in one line and
read on its own. They may share a version — bump `version.md` in the first and
repeat the number in the rest; two commits carrying one version is expected, not
a mistake. **Splitting is the default** for anything non-trivial, because the
history is the documentation of *how* the work was done, and one commit touching
six unrelated subjects documents none of them.

**The standard you are keeping:** someone reading `git log` alone — a year from
now, without the conversation that produced the work — can say what happened,
when, why, and at which version. If your commit would fail that test, it is too
big or its subject is too vague, and both are fixed the same way.

<!-- /COMMIT-RULE -->

<!-- RELEASES-RULE:repodocs -->

## Releases — the `version.md` on GitHub is what the Releases show

> Marked echo. The single source is **[samirhvbr/repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/versioning.md)**
> — change it there, not here. This block is regenerated.

**The `version.md` of the default branch, on GitHub, is what the GitHub Releases
must show.** The local checkout does not enter the calculation: it can be behind,
ahead or mid-work, and none of that is published — GitHub cannot tag a commit it
does not have.

**The bump and the Release are one act.** A commit that bumps `version.md` is not
finished until that version has a tag, a published Release, and the **`Latest`
badge on it** — the same push, not "later". A badge sitting on an older release
tells whoever looks that the project is at a version it is not.

- `.github/workflows/release.yml` does it on any push that touches `version.md`.
- `./tools/release.sh` does it by hand. It is **idempotent and self-healing**:
  it publishes whatever is missing and moves a drifted badge back. Running it is
  always safe, so it is both the check and the fix.

A PR publishes nothing while it is a PR. The moment it merges, the push moves
`version.md` on the default branch and the Release becomes that version.

Tag and Release title are the **bare version — no `v` prefix**.

<!-- /RELEASES-RULE -->

---

## Versão e commits (obrigatório)

Formato: `versão - descrição curta em português`. A versão vem do
[version.md](version.md), **bumpada no mesmo commit**:

<!-- Padrão do skeleton a substituir. Os slots X/Y/Z e a disciplina são
     convenção da frota; o que conta como Z é de cada projeto — troque estes
     exemplos pelos gatilhos reais do b3monitor quando houver stack. -->

- **Z** — _a preencher — ex.: uma tela nova, uma tabela nova, uma mudança de layout, uma correção de texto._
- **Y** — _a preencher — ex.: um serviço principal novo, um redesenho de API, uma quebra de compatibilidade._
- **X** — reservado; uma versão estável, à mão.

Proibido: prefixos `feat:` / `fix:` / `chore:` e mensagens vagas ("ajuste",
"update", "wip"). Vários commits podem dividir uma versão — agrupe por assunto,
bumpe no primeiro, repita o número nos outros.

**Escreva a entrada do `CHANGELOG.md` primeiro: o cabeçalho
`## X.Y.Z - descrição` dela *é* o subject do commit.** Os corpos são narrativa —
o que mudou, por quê e o que foi medido — não lista de tópicos.

**A versão é o PRIMEIRO semver do `version.md`** — uma string pura e um
documento markdown satisfazem isso igualmente.

**Toda versão ganha uma tag com exatamente o nome dela — sem prefixo `v` — e
uma GitHub Release publicada.** O `.github/workflows/release.yml` faz isso a
cada push que toca o `version.md`, chamando o `tools/release.sh`; rode esse
script à mão para um backfill. Os dois pulam o que já existe.

**O `version.md` no GitHub é igual às Releases no GitHub.** Seu checkout local
não entra na conta. Um PR não publica nada; no momento em que ele faz merge, a
Release vira aquela versão.

**O bump e a Release são um ato só.** Um commit que bumpa o `version.md` não está
terminado até aquela versão ter uma Release e o selo `Latest` estar nela — no
mesmo push, não "depois". O `./tools/release.sh` é idempotente e também conserta
um selo fora do lugar.

Regras completas: [docs/versioning.md](docs/versioning.md).

---

## Antes de fechar uma versão

- [ ] O que mudou está no `CHANGELOG.md`, com o **porquê**, não só o quê.
- [ ] Um documento novo ou alterado está em `docs/` — não na fila, não no corpo
      do commit.
- [ ] Um item terminado **saiu** da `.continue/` — *terminado* querendo dizer
      que a coisa que ele descrevia agora existe, não que o documento ficou bem
      escrito.
- [ ] Um documento que esta mudança deixou velho foi corrigido na mesma passada.
- [ ] Uma mudança de comportamento traz o teste que falha quando ela quebra, e
      os testes pertinentes **rodaram** — ou a entrada do changelog diz por que
      não, e o que foi conferido no lugar
      ([conventions.md §9](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md#9-a-change-in-behavior-ships-with-the-test-that-fails-when-the-behavior-breaks)).
- [ ] `version.md` bumpado, neste commit.
- [ ] **O trabalho está commitado** — e dividido num commit por assunto se
      cobriu mais de um. Nada é relatado como terminado enquanto estiver sem
      commit.
- [ ] A **tag e a GitHub Release** existem para esta versão. Normalmente o
      workflow faz isso no push; `./tools/release.sh` se precisar agora.
- [ ] Os gêmeos continuam iguais: `diff <(tail -n +2 CLAUDE.md) <(tail -n +2 AGENTS.md)`.
