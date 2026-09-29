# Changelog

Entradas no formato da mensagem de commit (`versão - descrição curta em
português`, veja [docs/versioning.md](docs/versioning.md)), mais novas primeiro.
**Cada cabeçalho `##` é literalmente o subject do commit** — a entrada é escrita
*antes* do commit, para que a frase que cai no `git log` seja pesada, e não
improvisada na hora do `git commit`.

Os corpos são narrativa: o que mudou, por quê e o que foi medido. Este arquivo
nunca é reescrito.

## 0.1.7 - a fila, o escopo e as premissas do loop entram no repositório, sem armar

`.loop/QUEUE.md` guarda os vinte itens que levam o repositório até *F0-01 FECHADA*:
oito de bootstrap (F0-00) e onze mais o fechamento da fatia vertical (F0-01 do
prompt 00), cada um numa linha que se executa sozinha e termina num critério de
pronto que dá para conferir. Sete pendências que dependem do dono ficam como `- 🔒`
e fora da fila de execução: a borda de rede (Q-02), o IP de saída do escritório
(Q-03), o nome do produto, as sondas e o vigilante em provedores distintos, os
canais de alerta, deploy e outros repositórios, e o runner de CI.

O hook só entrega o primeiro `- [ ]` a cada parada, então o cabeçalho do
`QUEUE.md` carrega as seis regras de cada fechamento — a regra de leitura (o 2.0
vence o 1.0), o português, commit com Release à mão, atualização do quadro com o
`if_version`, nenhum segredo e nenhum relato no chat —, porque o agente abre o
arquivo toda vez que marca um item. O `SCOPE.md` diz o que pode entrar e o que
para e pergunta, e o `ASSUMPTIONS.md` já nasce com as três premissas desta
preparação: ADRs em `docs/decisions.md` e não em `docs/adr/`, `b3mon` como slug e a
regra do repositório como autorização de push. A numeração dos ADRs da fila foi
conferida: precedência, D-01…D-08 e versões fixadas somam dez, do ADR-004 ao
ADR-013.

**O loop não foi armado**, de propósito: armar prende a rodada ao id de uma sessão,
e a sessão certa é uma aberta dentro do repositório, onde o hook acha o `.loop/`.
Conferido com o `loop_ctl.py`: a fila lê 20 pendentes, 7 travados e o A1 como
próximo, e `porque` mostra o hook barrado só no portão `.loop/`, sem `STATE.json`,
que é o esperado.

## 0.1.6 - o CLAUDE.md passa a apontar o material do produto, a regra de leitura e o quadro

A sessão que vai rodar o loop lê o `CLAUDE.md` sozinha, e o hook só injeta em cada
parada o **primeiro** item pendente da fila. O que tem de valer em todo turno,
portanto, precisa morar aqui, e não na cabeça de quem armou. A seção *O que é este
projeto* deixa de dizer que o material "vai para a `.continue/`": ele já está lá, e
ela agora diz o que é o produto, onde ficam os dois pacotes, qual é a regra de
leitura do dono — **o 2.0 complementa o 1.0 e vale no conflito** —, qual é a
primeira entrega e onde está o quadro de acompanhamento.

O quadro é o artefato *Produção b3monitor* (privado, com o `db` do próprio
artefato, semeado com 76 documentos a partir dos documentos 09 e 13 e da REVISAO).
O texto registra a única armadilha que a ferramenta impõe: escrever num documento
que já existe exige o `if_version`, então o agente lista a coleção antes de
atualizar. O `PRECEDENCIA.md` citado ainda **não existe**; o texto diz que ele nasce
no item A3, em vez de fingir que já está lá. Os dois gêmeos foram regenerados e
conferidos com `diff`.

## 0.1.5 - o estado gerado e o estado local de execução ficam fora do git, por ADR

O `.gitignore` do skeleton diz que tudo é versionado e que a única exceção é
segredo, e que qualquer outra exceção exige um ADR. Com o build chegando, isso
deixou de bastar, e a evidência apareceu antes do código: rodar uma vez o
`loop_ctl.py` da skill vendorizada criou `__pycache__/` na árvore, e o primeiro
`composer install` traria `vendor/` com dezenas de milhares de arquivos. Nenhum
dos dois é fonte; ambos se refazem por um comando.

O [ADR-003](docs/decisions.md) lista o que sai, e só isso: `vendor/`,
`node_modules/`, `__pycache__/`, `dist/`, `agents/**/bin/`, o cluster PostgreSQL de
desenvolvimento em `lab/.pgdata/` e `lab/.pgrun/`, e o estado de **máquina** do loop
(`STATE.json`, `entries/`, `INDEX.md`, `STOP`). O que continua versionado é a
**memória** do loop — `QUEUE.md`, `SCOPE.md`, `ASSUMPTIONS.md`, `SEM-ESCOPO`,
`STATUS.md` e `loop.sh` —, porque a própria skill diz que ler o `ASSUMPTIONS.md` não é
opcional, e uma fila que um `git clean` apaga destrói o que a norma protege.

Conferido com `git check-ignore` nos dois sentidos: os onze caminhos de estado
gerado saem, e os nove de memória, mais `composer.lock` e `go.sum`, ficam. O
`fleet.sh check` do repodocs não é afetado, porque só reclama de ignorar os
arquivos do padrão, e nenhum está na tabela.

## 0.1.4 - o build passa a ter permissão: toolchain mínima e um PostgreSQL de dev isolado

A produção do b3monitor começa pela fatia F0-01, e a stack já está decidida
(D-01…D-08 do 2.0, aceitas em 29/09/2026): Laravel na central, Go na sonda,
PostgreSQL 17. Sem permissão, o primeiro `go test` do loop pararia num prompt, e um
loop de horas sem ninguém no teclado é justamente o caso em que isso não pode
acontecer. O dono concedeu **só o build**, e cada linha está no `_comment` do
`settings.json` e na tabela do `.claude/README.md`, com o motivo e o jeito de
reverter, como a norma da frota manda.

Entram no `allow` `php artisan`, `php vendor/bin/pest`, `composer
install|validate|test`, `go build|test|vet|fmt|mod tidy`, `make`, `initdb` e `pg_ctl`
do PostgreSQL 17, `psql` **só na porta 54329** e a ferramenta `ArtifactData`. O
banco de desenvolvimento roda em espaço de usuário, sem sudo, e nunca toca o
PostgreSQL do sistema, na 5432. O `make` é o mais amplo dos grupos, porque roda o
que o Makefile disser; o Makefile é do repositório e é revisado como qualquer
arquivo, e o motivo diz isso. Ficam em `ask` `composer require|update` e `go get`,
porque dependência nova é decisão de cadeia de suprimento, e entram no `deny`
`migrate:fresh`, `migrate:reset` e `db:wipe`, como na raiz do BLUE3.

A linha *Testes rodam com* do `CLAUDE.md` e do `AGENTS.md` recebeu `make test`
**antes** da permissão existir, que é a ordem que o repodocs pede. **O `Makefile` ainda
não existe**: ele nasce no item A4 da fila. Nada foi executado neste commit — é só
configuração —, e o JSON foi validado com `json.tool`.

## 0.1.3 - o material de definição do produto entra na fila, versionado

Em 28/09/2026 o Samir colocou em `.continue/` o material da plataforma de
monitoramento distribuído da Blue3: `b3monitor/`, o pacote 1.0 (documentos 00–13
e o consolidado de 161 KB, que o `README.md` da pasta repete byte a byte), e
`b3monitor_2.0/`, a revisão 2.0 (README, glossário 14, arquitetura 02 em rascunho
e a `REVISAO_1.0_PARA_2.0.md`, mais o `files.zip` original). Nada disso tinha
commit, e uma fila que não está no git é uma fila que um apagamento não devolve —
o `fleet.sh check` do repodocs acusava exatamente isso.

Os arquivos entram **sem alteração de conteúdo**: fila não se arruma, nem o
consolidado duplicado nem o zip saem. O que muda é o `.continue/README.md`, que
agora registra a regra de leitura que o dono deu em 29/09/2026 — **o 2.0
complementa o 1.0 e, no que conflitar, vale o 2.0** —, as decisões D-01…D-08
aceitas no mesmo dia e as perguntas ainda abertas (Q-02, Q-03). Uma delas o
agente assumiu e deixou marcada como reversível: os ADRs ficam em
`docs/decisions.md`, a norma da frota, e não em `docs/adr/`, como o 14 §5 do 2.0
escreve. É conflito entre o 2.0 e a norma do repositório, que é outro eixo da
regra que o dono definiu.

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
