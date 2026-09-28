# Changelog

Entradas no formato da mensagem de commit (`versão - descrição curta em
português`, veja [docs/versioning.md](docs/versioning.md)), mais novas primeiro.
**Cada cabeçalho `##` é literalmente o subject do commit** — a entrada é escrita
*antes* do commit, para que a frase que cai no `git log` seja pesada, e não
improvisada na hora do `git commit`.

Os corpos são narrativa: o que mudou, por quê e o que foi medido. Este arquivo
nunca é reescrito.

## 0.1.2 - a CI do repositório passa a ter endereço: o servidor cicd, não os runners do GitHub

Os dois runs do workflow de Release no primeiro push, `36465790740` e
`36465902322`, foram recusados pelo GitHub em 4–5 s, sem executar nenhum passo:
*"recent account payments have failed or your spending limit needs to be
increased"*. A cota de minutos hospedados da `BLUE3-ISP` está travada; o ONLINE
falha igual desde pelo menos 24/09/2026. O dono decidiu, no mesmo dia, que este
repositório não precisa dos runners hospedados: a casa tem o servidor `cicd`
(`100.64.100.240`, usuário `samir`), que roda jobs do GitHub Actions de
repositórios privados em runners self-hosted.

O [ADR-002](docs/decisions.md) registra a decisão. A §8 nova do
[docs/runbook.md](docs/runbook.md) diz o que é a máquina, como entrar e o que
muda para registrar o runner de um repositório de **organização** — o token vem
de `repos/BLUE3-ISP/...` ou de `orgs/BLUE3-ISP/...`, e o painel ainda não lê
execuções de organização. Também traz as três regras da máquina que valem aqui:
nunca runner em repositório público, `LANG=C.UTF-8` no `.env` e token pelo
stdin. O detalhe do servidor não foi copiado: a fonte continua sendo o
`deploy/servidor-cicd.md` do shvia-web, e a seção linka para lá. **Nenhum runner
foi registrado**, porque ainda não há código nem suíte para rodar.

Enquanto não houver runner, a Release sai à mão. O `CLAUDE.md`, o `AGENTS.md` e o
[docs/versioning.md](docs/versioning.md) dizem agora que quem bumpa o
`version.md` roda `./tools/release.sh` depois do push e confere a Release e o selo
`Latest`. O `release.yml` continua no repositório, e cada bump deixa um run
recusado no GitHub. O checklist antes de tornar o repositório público ganhou um
item: o runner sai antes de a visibilidade mudar.

## 0.1.1 - a skill loop-work entra vendorizada em .claude/skills

A skill `/loop-work` do skill-LOOP, que faz o agente trabalhar a fila por horas
sem pedir "continua", passa a viver dentro do repositório: `vendor.sh` do
skill-LOOP, versão 0.5.4 (commit `cb511e9`), copiou `skill/loop/` para
`.claude/skills/loop-work/` e os dois modelos de prompt para `.claude/prompts/`,
sem os quais o hook procura os arquivos três níveis acima e falha em silêncio.

**A cópia é versionada**, e isso diverge dos irmãos: nos repositórios da
`BLUE3-ISP` que já têm a skill, a cópia está na 0.3.4 e fora do git —
`.claude/skills/` e `.claude/prompts/` aparecem como untracked no ONLINE, por
exemplo. Aqui vale a regra da frota: tudo é versionado, e a única exceção é
segredo.

Nenhum hook foi registrado no repositório. O hook `Stop` é global, instalado pelo
`install.sh` do skill-LOOP no config dir do Claude Code, e aponta para o upstream;
ele não faz nada enquanto não houver `.loop/STATE.json`, e a `.loop/` só nasce
quando alguém arma o loop. Nenhuma permissão foi concedida para o `loop_ctl.py`.
Conferido: `loop_ctl.py --help` roda a partir da cópia, e nenhuma `.loop/` foi
criada. O `.claude/README.md` descreve as duas pastas novas.

## 0.1.0 - a estrutura de documentação do repodocs chega ao repositório, em português

O b3monitor foi criado em 28/09/2026 na `BLUE3-ISP`, privado, com um `README.md`
vazio e mais nada. Este commit traz a estrutura de documentação do padrão da
frota, [samirhvbr/repodocs](https://github.com/samirhvbr/repodocs), copiada do
`templates/skeleton/` e reescrita em português: o repositório é da `BLUE3-ISP`,
e esse conjunto é escrito em português do começo ao fim (ADR-020 do repodocs).
A norma em si continua lá e **não** é copiada aqui, para existir um lugar só
onde mudá-la; o [docs/repodocs.md](docs/repodocs.md) é o mapa do que veio, e de
como.

Ainda não há código nem stack. Tudo o que depende disso — a stack e os comandos
no `CLAUDE.md`, os critérios de bump em `docs/versioning.md`, o modelo de
ameaças em `docs/security.md`, instalação e deploy em `docs/runbook.md` — fica
marcado como _a preencher_, com um comentário dizendo que é padrão do skeleton.
O material de definição do projeto vai para a `.continue/`, e a decisão sobre o
que o b3monitor é está registrada lá como pendente.

Três coisas divergem do skeleton, e cada uma por escrito. **Não há `LICENSE`
nem `NOTICE`**: é código interno da Blue3 em repositório privado, e o MIT com
copyright pessoal daria a quem tiver uma cópia o direito de redistribuí-la;
nenhum dos outros 13 repositórios da `BLUE3-ISP` tem licença. É o
[ADR-001](docs/decisions.md), e ele é revisto antes de o repositório ser aberto.
**O `CLAUDE.md` carrega os quatro blocos de eco**, e não os três do skeleton: o
`RELEASES-RULE` entra já, porque o `fleet.sh check` do repodocs cobra os quatro.
`LANGUAGE-RULE` e `QUEUE-RULE` vêm na variante `-PT`, escolhida pelo dono no
`origin`; `COMMIT-RULE` e `RELEASES-RULE` vêm literais em inglês, porque não têm
variante `-PT` e são regenerados, nunca editados no lugar. **O `effortLevel` é
`xhigh`**, e não o `high` do skeleton: é a decisão do dono no repodocs 1.17.1,
que o skeleton ainda não acompanhou.

Os hooks de `tools/git-hooks/`, o `tools/release.sh` e o
`.github/workflows/release.yml` são cópias literais do padrão, em inglês —
os hooks só com `HOOK_ESCAPE_VAR="B3MONITOR_NO_HOOK"` —, porque o
`fleet.sh check` compara hooks e `release.sh` byte a byte com a fonte, e uma
cópia traduzida é uma cópia que deixa de ser regenerada. Os hooks foram
habilitados neste clone antes do commit, e este commit passou por eles.
