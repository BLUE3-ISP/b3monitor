# Versionamento e commits — b3monitor

> **Status:** `ACTIVE` · **Fonte única** de como se define uma versão e de como
> se escreve um commit neste repositório.
>
> A regra da frota que isto implementa é
> [samirhvbr/repodocs `docs/conventions.md`](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md).
> O que é próprio deste projeto são os **gatilhos de bump** — §[Critérios de bump](#critérios-de-bump).

## `version.md` é a autoridade

A versão mora no **`version.md` da raiz do repositório**. A regra que importa não
é o formato do arquivo, e sim como ele é lido:

> **A versão é o PRIMEIRO semver (`X.Y.Z`) do arquivo.**

Uma string pura é o jeito mais simples de cumprir isso, e é o que um repositório
novo deve usar:

```
0.1.0
```

Um documento markdown cujo primeiro número é a versão cumpre igualmente — é o
formato que vários repositórios desta frota já usam, e é o motivo de a regra
estar escrita como "primeiro semver" e não como "o arquivo inteiro".

Ele é versionado no git para que o bump **viaje dentro do commit** e o deploy o
propague sozinho. É exatamente por isso que é um arquivo e não uma variável de
ambiente: o `.env` fica fora do rsync de deploy em todos os repositórios daqui,
então uma versão guardada lá precisa ser editada à mão em cada servidor, e
diverge em silêncio.

**Onde a aplicação expõe a versão** (um rodapé, uma rota `/version`, uma página
de health), ela lê o `version.md`. Uma variável de ambiente pode existir como
*fallback* para o caso de o arquivo não estar lá. Ela nunca é a autoridade, e
**nenhum documento pode dizer o contrário** — o `README.md` de um repositório
irmão afirmava que o `version.md` tinha sido "removido em favor de
`APP_VERSION`" enquanto o arquivo continuava lá e o próprio `CLAUDE.md` o
chamava de fonte da verdade. Dois documentos discordando sobre um mesmo número é
como um deploy errado é aprovado.

## Critérios de bump

Bumpe o `version.md` **no mesmo commit da mudança que ele descreve.** Nunca num
commit só dele.

As três posições e a disciplina são a convenção da frota; o que conta como Z é
próprio de cada projeto. Os gatilhos abaixo são os do b3monitor (decididos em
01/10/2026, item A4 da fila do loop), concretos o bastante para que duas pessoas
classifiquem a mesma mudança do mesmo jeito.

| Parte | Quando se move | Quem move |
|---|---|---|
| **Z** | Uma migração, um endpoint, um teste ou fixture, um ADR, um documento, uma tela do painel, um alvo do `Makefile`, uma correção de texto ou de código | Toda mudança. O caso normal. |
| **Y** | Um componente principal novo (sonda, vigilante, worker, painel); uma mudança em `contracts/` que quebra a compatibilidade; ou uma **fatia do backlog fechada** com evidência (F0-01, F0-02…). **Fechar a F0-01 é a `0.2.0`** | Quando entra |
| **X** | Reservado — uma versão estável: o piloto real liberado (Gate 0B) | Manual, decisão do dono |

**Um Y zera o Z.** A `0.1.x` atual é a fase de preparação (documentação, fila e
bootstrap): nenhum componente do produto existe ainda.

## Quem commita, e quando

**O agente commita.** Não é "deixar a árvore pronta e alguma coisa depois
empacota" — o agente roda `git commit`, e `git push`, como último passo do
trabalho que pediram a ele. Não existe automação de commit nesta frota; se o
agente não commitar, ninguém commita.

- **Uma entrega não está entregue até ser commitada.** Não relate uma tarefa
  como concluída antes de o commit existir. "Pronto" significa que o trabalho
  está no `git log`, não que está parado sem commit onde só a sessão atual
  enxerga.
- **Um assunto por commit.** O subject tem que descrever o commit inteiro com
  honestidade — no momento em que ele precisa de um "e" para ser verdade, são
  dois commits.
- **Uma entrega grande é dividida em blocos**, agrupados por assunto, cada um
  pequeno o bastante para ser descrito numa linha e lido sozinho. Eles podem
  compartilhar uma versão: bumpe o `version.md` no primeiro e repita o número
  nos demais. Dividir é o padrão para qualquer coisa não trivial, porque o
  histórico é a documentação de *como* o trabalho foi feito.

O teste a fazer antes de commitar: alguém lendo só o `git log`, daqui a um ano,
consegue dizer o que aconteceu, quando, por quê, e em qual versão?

Texto completo e o raciocínio:
[samirhvbr/repodocs `docs/versioning.md`](https://github.com/samirhvbr/repodocs/blob/master/docs/versioning.md#who-commits-and-when).

## Mensagem de commit

**Formato obrigatório:**

```
versão - descrição curta em português
```

Por exemplo: `0.1.0 - estrutura inicial de documentação`.

- **Português**, como todo o resto deste repositório: o b3monitor é da
  `BLUE3-ISP`, e o conjunto interno da Blue3 é escrito em português de ponta a
  ponta
  ([conventions.md §8](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md#the-blue3-internal-repositories-are-written-in-portuguese),
  ADR-020 do repodocs). **Só o idioma muda** — a mecânica é a mesma da frota.
  Num repositório que **não** é nosso, vale a convenção de commit da casa — o
  subject deles, no idioma deles
  ([conventions.md §8](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md#when-the-repository-is-not-ours-the-upstreams-conventions-win)).
- Descritiva o bastante para ser encontrada depois com `git log --grep`.
  Explique o **porquê**, não só o quê.
- **Proibido:** prefixos de Conventional Commits — `feat:`, `fix:`, `chore:`,
  `docs:`, `refactor:` — e mensagens vagas: "ajuste", "update", "fix", "wip". É
  a versão, e não um prefixo de tipo, que torna este histórico pesquisável.

**Vários commits podem compartilhar uma versão.** Uma mesma entrega pode ser
dividida em commits por assunto que carregam a **mesma** versão — agrupe os
arquivos por tema, bumpe o `version.md` em **um** deles (o primeiro) e deixe os
outros repetirem o mesmo número no subject. Dois commits com a mesma versão no
`git log` são esperados, não um erro.

## O cabeçalho do CHANGELOG *é* a mensagem de commit

O `CHANGELOG.md` vai do mais novo para o mais antigo, e o H2 de cada entrada é
literalmente o subject que chega ao git:

```markdown
## 0.1.0 - estrutura inicial de documentação

<corpo narrativo: o que mudou, por quê, e o que foi medido>
```

Isso não é enfeite. É o que permite a uma automação de commit montar a mensagem
sem inventar uma, e significa que a entrada do changelog — não o commit — é o
artefato de passagem entre um agente e quem commita.

Os corpos são narrativos, não listas de tópicos: os changelogs da frota se leem
como post-mortems de engenharia ("como se prova", "de onde veio", "medido", "o
que falta"), e é por isso que uma entrada de dois anos atrás ainda vale a
leitura.

## Tags e Releases

**Toda versão ganha uma tag git com o nome dela, e uma GitHub Release publicada
em cima dessa tag.**

O GitHub nunca deduz uma versão a partir do subject de um commit: sem tag, um
repositório cheio de commits `X.Y.Z - …` continua mostrando *"No releases
published"*, e `git diff <antiga>..<nova>` falha porque nenhum dos dois nomes
existe como ref. A tag é o que prende um número de versão ao código exato que
ele nomeou — e é para onde um deploy ruim volta.

> **O `version.md` no GitHub é igual às Releases no GitHub.** O checkout local
> não entra na conta — o `tools/release.sh` lê a versão do branch padrão remoto
> e taggeia esse commit. Um PR não publica nada; no momento em que ele é
> mergeado, o workflow dispara e a Release passa a ser essa versão.

**O bump e a Release são um ato só.** Um commit que bumpa o `version.md` não
está terminado até essa versão ter uma Release **e o selo `Latest` estar nela**
— no mesmo push, não "depois". O selo pertence à versão que o `version.md`
nomeia, nunca à "mais nova por data". O `./tools/release.sh` é idempotente e
conserta um selo desalinhado a cada execução.

| Coisa | Valor |
|---|---|
| Tag | A versão **exatamente como o `version.md` a guarda — sem prefixo `v`** |
| Título da Release | A mesma string |
| Notas da Release | A seção do `CHANGELOG.md` daquela versão; na falta dela, os subjects dos commits que a carregaram; na falta desses, as notas geradas pelo GitHub |

### Quem cria

Dois responsáveis, os dois conferindo *"essa tag já existe?"*, então o que rodar
primeiro vence e o outro não faz nada:

- **`.github/workflows/release.yml`** — todo push que mexe no `version.md`,
  venha de quem vier.
- **`tools/release.sh`** — rodado à mão, para fechar agora em vez de esperar o
  Actions, ou para um backfill.

Nenhum dos dois jamais *decide* uma versão: os dois copiam o número que o agente
já escreveu no `version.md`.

**Neste repositório, hoje, só o segundo funciona.** Os runners hospedados do
GitHub estão bloqueados por cobrança na `BLUE3-ISP`, e o job é recusado antes de
começar. Também não há runner self-hosted para o b3monitor no servidor `cicd`
([ADR-002](decisions.md)). Por isso **quem bumpa o `version.md` roda
`./tools/release.sh` depois do push**, na mesma entrega, e confere com
`gh release list` que a versão tem Release e selo `Latest`. Quando houver runner
no `cicd` ([runbook.md](runbook.md), §8), o workflow volta a ser o primeiro
responsável.

### `tools/release.sh`

A única implementação que os dois responsáveis chamam — o workflow roda este
script em vez de reimplementar as regras.

```bash
./tools/release.sh --dry-run              # imprime a tabela, não cria nada
./tools/release.sh                        # tag + Release da versão atual
./tools/release.sh --backfill --dry-run   # o histórico inteiro, como tabela
./tools/release.sh --backfill             # o histórico inteiro, de verdade
```

Idempotente e retomável — o que já foi publicado é pulado. No `--backfill`, a
tag de cada versão aponta para o **último** commit que a carregou: o estado
terminado daquela versão, não o primeiro commit dela.

---

## Os hooks

Dois hooks moram em [`../tools/git-hooks/`](../tools/git-hooks/). Eles **não
ficam ativos no clone** — ative uma vez, por clone:

```bash
git config core.hooksPath tools/git-hooks
```

| Hook | O que confere | Por que ali |
|---|---|---|
| `commit-msg` | O subject casa com `X.Y.Z - <comentário>`, **e esse `X.Y.Z` é a versão que o commit carrega no `version.md`**; recusa prefixos de Conventional Commits, comentários vagos e curtos demais; isenta commit de merge | É o único momento em que a mensagem existe e o commit ainda não |
| `pre-push` | O `version.md` **do commit sendo enviado** (não o da árvore de trabalho) contra o branch padrão remoto: recusa duplicata, recusa regressão — e **só quando o push de fato atualiza esse branch**, então uma remoção, uma tag e um branch de tópico passam direto | É o único hook que enxerga o **remoto** — e o `commit-msg` comprovadamente não roda durante um rebase, que é onde nascem as versões duplicadas |

Duas escolhas de desenho deliberadas, e as duas sustentam o resto:

- **O `pre-push` não roda suíte de testes.** Um push que espera quatro minutos
  vira `--no-verify` na semana seguinte, e aí o controle morreu. Ele faz duas
  perguntas sobre uma linha de um arquivo.
- **Sem remoto alcançável ele degrada com um aviso, nunca com uma recusa.** Uma
  guarda que bloqueia o trabalho quando não consegue medir é uma guarda que
  acaba desligada. O `commit-msg` responde do mesmo jeito a um `version.md`
  ausente ou sem semver: um aviso, e a versão do subject fica sem verificação.

### A versão do subject é conferida contra o arquivo, não só o formato

Um subject bem formado ainda pode estar errado, e o teste de formato não
enxerga isso: um número digitado de memória, a versão de um repositório irmão,
ou um bump que nunca entrou no commit. As duas falhas estavam vivas na frota no
dia em que esta verificação foi escrita — um repositório quatro commits adentro
numa versão que o `version.md` dele nunca tinha alcançado, com o bump parado sem
commit na árvore de trabalho, e outro cujo subject carregava o número do
repositório vizinho.

O `commit-msg` lê o `version.md` **do índice**, porque o índice é o que o commit
vai conter. É isso que torna a verificação compatível com as regras acima, em
vez de uma segunda opinião sobre elas:

| Caso | Resultado |
|---|---|
| O bump está no stage deste commit (o caso normal, e o primeiro commit de um bloco) | Passa |
| Um commit posterior do mesmo bloco repete a versão | Passa — o arquivo já guarda esse número e o índice continua mostrando |
| O subject carrega uma versão que o arquivo não tem | **Recusado**, citando os dois números |
| O bump está na árvore de trabalho mas não no stage | **Recusado** — o commit não o carrega, e esse é justamente o ponto |
| Sem `version.md`, ou sem semver nele | Aviso, não recusa |

**Subject escrito pelo próprio git é isento** — `Merge …`, `Revert …`,
`fixup! …`, `squash! …`, `amend! …`, e um merge detectado por `MERGE_HEAD`.
Nenhum deles carrega versão própria: as versões de um merge estão nos commits
mergeados, a de um revert está no commit que ele desfaz, e uma linha de fixup é
consumida pelo rebase que a aplica. Recusá-los só ensinaria `--no-verify`, que
desliga todas as guardas de uma vez. **Esta lista é a do EOP**, que a roda no
próprio `commit-msg` desde 04/09/2026 — incluindo a mesma verificação contra o
`version.md`, a que chegou de forma independente. Duas implementações de uma
regra é como uma regra acaba com duas versões, então esta pega a lista testada
em vez de inventar uma mais curta.

**Um repositório que carimba a versão de outro jeito não instala o
`commit-msg`.** Se este repositório preencher a versão no merge, ou a partir de
um build, os subjects não carregam um `X.Y.Z` quando o hook roda, e este hook
recusa esse formato por desenho. Isso é permitido — a norma deixa um repositório
sobrescrever a cláusula do bump **por escrito**, na própria documentação, com o
motivo. O que ela não permite é uma sobrescrita que ninguém escreveu. O
`pre-push` continua útil de qualquer jeito.

Válvula de escape, declarada nos dois: a `HOOK_ESCAPE_VAR` no topo de cada hook,
que neste repositório se chama **`B3MONITOR_NO_HOOK`** —
`B3MONITOR_NO_HOOK=1 git commit …` pula o `commit-msg`, e o mesmo prefixo no
`git push` pula o `pre-push`.

Uma válvula de escape não é uma brecha — é o que mantém os hooks instalados.
Uma guarda sem desvio declarado é desviada com `--no-verify`, que desvia *todas*
as guardas de uma vez.
