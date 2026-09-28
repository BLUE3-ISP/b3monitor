# repodocs — o padrão que este repositório segue

> **Status:** `ACTIVE` · O que neste repositório veio do padrão da frota, onde
> cada peça mora, como trazer um repositório que já existe, e como consultar o
> padrão sem nunca copiá-lo.

**[samirhvbr/repodocs](https://github.com/samirhvbr/repodocs) é fonte de
consulta, não dependência.** Nunca é submódulo, nunca é pacote, nunca é
vendorizado. Nada aqui o instala e nada aqui quebra quando ele muda de lugar. O
que ele faz é mais estreito e mais durável: entrega uma estrutura inicial a um
repositório novo, uma única vez; mantém um conjunto pequeno de regras carimbado
nos arquivos que o agente de fato lê; e guarda a norma em exatamente um lugar,
para que a frota tenha uma versão dela e não dezesseis.

Esta página é o mapa dessa relação, visto do lado do repositório que a segue. Os
passos mecânicos de um bootstrap **não** se repetem aqui — eles moram no
[`docs/runbook.md` do repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/runbook.md),
e uma segunda cópia deles seria exatamente a falha que este padrão existe para
evitar.

## O mapa

```
repodocs = FONTE DE CONSULTA (nunca uma dependência)
│
├── 0. O QUE VIAJA, E O QUE NUNCA VIAJA
│   ├── viaja por cópia ....... templates/skeleton/      → uma vez, no bootstrap
│   ├── viaja por carimbo ..... templates/echo-blocks/   → 4 blocos, regenerados no lugar
│   └── nunca viaja ........... docs/conventions.md      → só por link (ADR-001 do repodocs)
│                               uma regra copiada em 16 repositórios é uma regra
│                               com 16 versões, 15 delas velhas
│
├── 1. QUE ARQUIVOS ESTE REPOSITÓRIO RECEBEU, E ONDE MORAM
│   ├── raiz/        README.md · CLAUDE.md + AGENTS.md (gêmeos) · version.md
│   │                CHANGELOG.md · SECURITY.md · .gitignore
│   │                ✂ sem LICENSE e sem NOTICE — corte deliberado, ADR-001 daqui
│   ├── docs/        README.md · versioning.md · decisions.md · security.md
│   │                runbook.md · repodocs.md (esta página)
│   │                ⛔ sem conventions.md — o docs/README.md aponta para o upstream
│   ├── .continue/   README.md      (a fila; versionada no git de propósito)
│   ├── .claude/     settings.json · README.md   (a postura de permissões)
│   ├── .github/     CODEOWNERS · dependabot.yml · workflows/release.yml
│   └── tools/       git-hooks/{commit-msg,pre-push} · release.sh
│                    cópias byte a byte do padrão — por isso seguem em inglês
│
├── 2. COMO OS ARQUIVOS CHEGAM AQUI   (duas rotas, um comando)
│   ├── A) existe clone local ... cp -r /path/to/repodocs/templates/skeleton/. .
│   │                             o /. no final é o que traz os dotfiles
│   │                             ← a rota deste repositório, em 28/09/2026
│   └── B) sem clone ............ gh repo clone samirhvbr/repodocs /tmp/repodocs
│                                 e depois a mesma cópia
│
├── 3. O QUE MUDA DEPOIS DA CÓPIA   (aponta, nunca repete)
│   ├── placeholders ........... §2 do runbook do repodocs: a tabela + a varredura
│   ├── decisões por repo ...... §3 do runbook do repodocs: concessões no settings.json
│   │                            · gatilhos de bump · security.md §2 e §4
│   ├── blocos de eco .......... 4 blocos, literais, no CLAUDE.md E no AGENTS.md
│   │                            QUEUE-RULE-PT · LANGUAGE-RULE-PT (português)
│   │                            COMMIT-RULE · RELEASES-RULE (inglês — não há -PT)
│   └── hooks .................. git config core.hooksPath tools/git-hooks  (1× por clone)
│
├── 4. CONSULTAR DEPOIS   ← a parte permanente, o motivo de esta página ficar
│   ├── "qual é a norma?" ........... conventions.md   (leia lá, nunca copie)
│   ├── "como versiono?" ............ versioning.md
│   ├── "isso já foi decidido?" ..... decisions.md     (não relitigue; linke o ADR)
│   ├── "isso é seguro?" ............ security.md      (normativo; vence qualquer conflito)
│   └── "a regra mudou lá" .......... o bloco de eco é regenerado aqui,
│                                     entre os marcadores
│
├── 5. REPOSITÓRIO QUE JÁ EXISTE ≠ REPOSITÓRIO VAZIO
│   ├── nunca sobrescreva um README/LICENSE que já existe — mescle, e escreva a divergência
│   ├── ordem: .claude/ → CLAUDE.md+AGENTS.md → version.md+CHANGELOG → docs/ → tools/
│   └── não se aplica a este repositório: ele nasceu vazio, pela rota A
│
└── 6. VERIFIQUE ANTES DE FECHAR
    ├── diff <(tail -n +2 CLAUDE.md) <(tail -n +2 AGENTS.md)   → vazio
    ├── grep -rn '<[A-Z_]\+>' . --exclude-dir=.git             → vazio
    ├── python3 -m json.tool .claude/settings.json             → ok
    ├── um commit "feat: x" REJECTED / um commit "0.1.0 - …" aceito
    └── ./tools/release.sh --backfill --dry-run
```

## 1. O que sai do repodocs, e o que nunca sai

Três coisas saem daquele repositório, e cada uma sai por um mecanismo diferente.
Saber qual é qual é quase tudo o que é preciso para trabalhar com o padrão.

| O quê | Mecanismo | Quando se move | O que isso significa aqui |
|---|---|---|---|
| [`templates/skeleton/`](https://github.com/samirhvbr/repodocs/tree/master/templates/skeleton) | **Copiado**, uma vez | Só no bootstrap | Tudo o que está na §2 abaixo. Depois da cópia o arquivo é *deste repositório* — edite à vontade, não é espelho |
| [`templates/echo-blocks/`](https://github.com/samirhvbr/repodocs/tree/master/templates/echo-blocks) | **Carimbado**, e regenerado no lugar | Sempre que a regra muda no upstream | Os blocos delimitados dentro do `CLAUDE.md` e do `AGENTS.md`. Esses *são* espelhos — veja a §5 |
| [`docs/conventions.md`](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md) | **Nunca copiado.** Linkado | — | A norma tem uma casa só. Uma cópia aqui é uma segunda fonte da verdade, que no mês seguinte já é uma fonte velha da verdade |

A assimetria é de propósito. O skeleton é *ponto de partida*, então deixa de ser
assunto do repodocs no momento em que chega. Os blocos de eco são *regras*, então
continuam sendo assunto do repodocs para sempre, e são substituídos em vez de
editados. A norma não é nem uma coisa nem outra — é o que todo repositório
precisa concordar, então é lida onde está escrita.

## 2. O manifesto — o que este repositório tem por causa do repodocs

Tudo abaixo chegou do skeleton. A terceira coluna é a que vale ler: ela diz o
que de fato se perde quando um arquivo falta, que é o único argumento que
sobrevive a alguém com pressa.

### Raiz

| Arquivo | O que é | Faltar custa |
|---|---|---|
| `CLAUDE.md` + `AGENTS.md` | As instruções do agente. **Gêmeos — idênticos byte a byte abaixo do H1** | O agente trabalha sem as regras da casa, e os blocos de eco não têm onde pousar |
| `version.md` | A autoridade única sobre a versão. Um semver puro, ou um documento cujo **primeiro** semver é a versão | Nada pode ser commitado dentro da convenção, nem tagueado, nem publicado — os hooks e o workflow de release leem esse arquivo |
| `CHANGELOG.md` | O histórico. Cada cabeçalho `##` *é* um subject de commit | O *porquê* de uma mudança só sobrevive na cabeça de quem a fez |
| `README.md` | O que o projeto é, para uma pessoa que chega sem contexto | — |
| `LICENSE` · `NOTICE` | **Ausentes aqui, por decisão do dono** — [ADR-001](decisions.md). É código interno da Blue3 num repositório privado: o MIT do skeleton, sob copyright pessoal, daria a qualquer pessoa com uma cópia o direito de redistribuir. Os outros 13 repositórios da `BLUE3-ISP` também não têm nenhum dos dois. A norma permite o corte com motivo escrito ([conventions.md §7](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md#7-the-repository-file-standard)), e **esta linha fica** porque é por ela que o leitor fica sabendo que a ausência é uma decisão, não um esquecimento | No padrão da frota: o GitHub não mostra selo de licença, e um repositório público sem licença é fechado juridicamente. Aqui, se um dia o repositório for aberto, o ADR-001 é revisto antes |
| `SECURITY.md` | Um endereço de reporte que alguém de fato lê | Um reporte de vulnerabilidade não encontra porta e vira issue pública |
| `.gitignore` | Tudo é versionado; a única exceção é segredo | — |

### Diretórios

| Caminho | O que é | Faltar custa |
|---|---|---|
| `docs/` | O registro — `README.md`, `versioning.md`, `decisions.md`, `security.md`, `runbook.md` e esta página | Decisões são relitigadas, porque nada registrou que já tinham sido tomadas |
| `.continue/README.md` | A fila. **Versionada no git de propósito** | A próxima sessão começa do zero, ou refaz trabalho já terminado |
| `.claude/` | `settings.json` (a postura de permissões) e `README.md` (por que cada concessão existe) | O repositório nasce sem deny-list. É o arquivo que mais se perde num `cp -r` sem o `/.` no final |
| `.github/` | `CODEOWNERS`, `dependabot.yml`, `workflows/release.yml` | Nenhuma Release automática no bump de versão; o selo `Latest` escorrega para uma versão antiga |
| `tools/git-hooks/` | `commit-msg` e `pre-push` | O formato do commit vira sugestão, e versões duplicadas nascem durante rebases |
| `tools/release.sh` | Tag + GitHub Release para o `version.md`. Idempotente e autocorretivo | Não há como reparar à mão uma Release que faltou ou um selo que escorregou |

**`tools/git-hooks/*` e `tools/release.sh` são cópias byte a byte do padrão** —
nos hooks, com exceção da linha `HOOK_ESCAPE_VAR`, que aqui é
`B3MONITOR_NO_HOOK`. **É por isso que os comentários e as mensagens deles ficam
em inglês**, mesmo num repositório escrito em português: o `tools/fleet.sh
check` do repodocs compara esses arquivos com o padrão, e uma cópia traduzida é
uma cópia que deixa de ser regenerada.

**`docs/conventions.md` está ausente de propósito**, e a ausência é o ponto.
O [`docs/README.md`](README.md) aponta para ele no upstream.

## 3. Começando um repositório do zero

O bootstrap inteiro é uma cópia mais uma substituição:

```bash
# de dentro do repositório novo, vazio
cp -r /path/to/repodocs/templates/skeleton/. .
```

**O `/.` no final importa.** É ele que traz `.claude/`, `.continue/`,
`.github/` e `.gitignore`. Sem ele vêm só os arquivos visíveis — um repositório
que parece conforme e não tem postura de permissões.

Sem um clone local de onde copiar:

```bash
gh repo clone samirhvbr/repodocs /tmp/repodocs
cp -r /tmp/repodocs/templates/skeleton/. .
```

**Este repositório nasceu assim, em 28/09/2026**: vazio, pela cópia com clone
local, e depois reescrito em português, porque o dono no GitHub é a
`BLUE3-ISP` ([ADR-020 do repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/decisions.md#adr-020--the-blue3-internal-repositories-are-written-in-portuguese)).

Depois é substituir os placeholders, tomar as três decisões por repositório,
ligar os hooks e fazer o primeiro commit — cada um desses passos, com a tabela de
placeholders e o motivo de cada decisão por repositório ficar em aberto, está no
[`docs/runbook.md` do repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/runbook.md).
Ele é a fonte única desse procedimento; esta página não o repete.

A única checagem a rodar antes de acreditar que a substituição funcionou:

```bash
grep -rn '<[A-Z_]\+>' . --exclude-dir=.git   # tem que voltar vazio
```

## 4. Trazendo um repositório que já existe

**Esta seção não se aplica a este repositório**, que nasceu vazio pela §3. Ela
fica porque é o mapa que este padrão entrega a quem o segue, e porque é a
resposta certa para quem abrir esta página vindo de outro repositório.

Um repositório com histórico não é um repositório vazio, e `cp -r` dentro dele é
destrutivo: sobrescreve um `README.md` que alguém escreveu e um `LICENSE` que
talvez já estivesse certo. Adote arquivo por arquivo, nesta ordem — cada passo
deixa o repositório mais conforme do que estava, e nenhum depende de um
posterior.

| # | O quê | Por que aqui |
|---|---|---|
| 1 | `.claude/settings.json` + `.claude/README.md` | A postura vem primeiro, antes de um agente começar a trabalhar no resto da adoção |
| 2 | `CLAUDE.md` + `AGENTS.md`, com os quatro blocos de eco | O agente não segue regra que não recebeu |
| 3 | `version.md` + `CHANGELOG.md` | Nada depois deste ponto pode ser commitado dentro da convenção sem eles |
| 4 | `tools/git-hooks/` + `git config core.hooksPath tools/git-hooks` | Fiscalizar só faz sentido depois que o formato fiscalizado existe |
| 5 | `docs/` — `README.md`, `versioning.md`, `decisions.md`, `security.md`, esta página | O registro. Um documento existente que já cobre um destes é mantido e linkado, não substituído |
| 6 | `.continue/README.md` | Mesmo vazia, ela precisa do README dizendo para onde os documentos foram |
| 7 | `.github/workflows/release.yml` + `tools/release.sh` | Por último, porque publica: rode `./tools/release.sh --backfill --dry-run` antes da primeira execução de verdade |

**Três regras de mesclagem, e elas não se negociam:**

- **Nunca sobrescreva um arquivo que o repositório já tinha** porque o skeleton
  tem um com o mesmo nome. Mescle, e se o resultado diverge do skeleton de
  propósito, escreva *onde* e *por quê* — uma divergência não documentada parece
  erro para a próxima pessoa e é "corrigida" de volta.
- **Um documento existente que já responde o que um documento do skeleton
  responde é mantido.** Aponte para ele a partir do `docs/README.md`. Dois
  documentos respondendo a mesma pergunta é o modo de falha; a correção não é um
  terceiro.
- **A versão não recomeça.** Um repositório que já está em `2.4.0` fica lá. O
  `0.1.0` do skeleton é para repositório que ainda não entregou nada — o caso
  deste.

## 5. Quando uma regra da frota muda no upstream

As regras que todo repositório precisa seguir moram dentro do `CLAUDE.md` e do
`AGENTS.md` como blocos delimitados:

```
<!-- QUEUE-RULE:repodocs -->     …   <!-- /QUEUE-RULE -->
<!-- LANGUAGE-RULE:repodocs -->  …   <!-- /LANGUAGE-RULE -->
<!-- COMMIT-RULE:repodocs -->    …   <!-- /COMMIT-RULE -->
<!-- RELEASES-RULE:repodocs -->  …   <!-- /RELEASES-RULE -->
```

Cada um carrega um link de volta para a sua fonte no `docs/` do repodocs.
**Eles são regenerados, não editados:** substitua tudo o que está entre os
marcadores pelo bloco atual de
[`templates/echo-blocks/`](https://github.com/samirhvbr/repodocs/tree/master/templates/echo-blocks),
nos dois gêmeos, e commite como qualquer outra mudança — entrada do changelog
primeiro, versão bumpada no mesmo commit. O `tools/fleet.sh echo` do repodocs faz
isso sozinho.

**Neste repositório os quatro blocos não estão todos no mesmo idioma, e isso é
correto:**

| Bloco | Variante aqui | Por quê |
|---|---|---|
| `QUEUE-RULE` | `QUEUE-RULE-PT`, em português | O dono em `origin` é a `BLUE3-ISP`, e é o dono que escolhe a variante — nunca a mão |
| `LANGUAGE-RULE` | `LANGUAGE-RULE-PT`, em português | O mesmo. A variante em inglês diria *"tudo aqui é inglês"* dentro de um repositório que a norma manda escrever em português |
| `COMMIT-RULE` | literal, em inglês | Não existe variante `-PT`. O bloco é carimbado como está na fonte |
| `RELEASES-RULE` | literal, em inglês | O mesmo. Foi carimbado já no bootstrap, embora o `CLAUDE.md` do skeleton não o traga, porque o `tools/fleet.sh check` exige os quatro blocos |

Cada variante `-PT` usa o mesmo nome de marcador da versão em inglês, e isso é de
propósito: o repositório carrega exatamente uma das duas, e a regeneração
substitui a que estiver lá.

Editar o texto de um bloco *aqui* é a única coisa que quebra o mecanismo: a
próxima regeneração sobrescreve em silêncio, e até lá este repositório segue uma
regra que nenhum outro tem. **Isso vale também para traduzir `COMMIT-RULE` ou
`RELEASES-RULE`.** Se um bloco está errado, está errado para a frota — corrija na
fonte, no repodocs.

## 6. Verificando se este repositório continua conforme

As checagens moram na §7 do [`runbook.md`](runbook.md), que é onde devem ficar —
são operação, não descrição do padrão. Rode quando um gêmeo foi editado, depois
de uma regeneração de bloco de eco, e antes de tornar o repositório público.

## 7. Onde cada pergunta é respondida

| Pergunta | Onde |
|---|---|
| Qual é a norma de documentação? | [`docs/conventions.md` do repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md) — a fonte única da frota inteira |
| Como se define uma versão e se escreve um commit? | [`versioning.md`](versioning.md) aqui, e o [`docs/versioning.md` do repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/versioning.md) para o raciocínio da frota |
| Isso já foi decidido? | [`decisions.md`](decisions.md) aqui primeiro, depois o [`docs/decisions.md` do repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/decisions.md). Não relitigue uma direção decidida — linke o ADR |
| Esta mudança precisa de teste, e ele rodou? | [§9 do `docs/conventions.md` do repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md#9-a-change-in-behavior-ships-with-the-test-that-fails-when-the-behavior-breaks) — a linha *Testes rodam com* do `CLAUDE.md` diz como |
| Isso é seguro de fazer? | [`security.md`](security.md). Normativo: num conflito com qualquer outro documento, ele vence |
| Como faço o bootstrap de outro repositório? | [`docs/runbook.md` do repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/runbook.md) |
| O que ainda está aberto aqui? | [`../.continue/README.md`](../.continue/README.md) — a fila, sempre lida primeiro |
