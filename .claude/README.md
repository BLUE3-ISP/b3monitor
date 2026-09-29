# Perfil do Claude Code — b3monitor

> **Status:** `ACTIVE`

A `.claude/` deste repositório segue o padrão da frota samirhvbr/Blue3: uma
**postura de permissões**, versionada. **Nada aqui escolhe o modelo**: nenhum
`model`, nenhum `fallbackModel`, nenhum `ANTHROPIC_MODEL`,
`ANTHROPIC_DEFAULT_*_MODEL` ou `CLAUDE_CODE_SUBAGENT_MODEL` no `env`. O modelo é
escolha do usuário, feita com `/model`, e o subagente herda (ADR-027 do
repodocs). Um agente próprio em `agents/` diz `model: inherit`, ou nada.

As regras de conduta do agente estão no [CLAUDE.md](../CLAUDE.md).

## Arquivos

| Arquivo | Papel |
|------|------|
| `settings.json` | O perfil **ativo**, versionado. Hoje: **nada que escolha o modelo**, `effortLevel: xhigh` (um padrão — `/effort` muda para a sessão), `defaultMode: plan`, deny-list de 8 regras, ask-list de 11 e allow-list de 32 entradas (a concessão de build de 29/09/2026 está na tabela abaixo). |
| `settings.local.json` | Override local da máquina, **no `.gitignore`** — pode guardar caminhos e tokens que pertencem a uma máquina só. Nunca commite. |
| `skills/loop-work/` | A skill `/loop-work` do [skill-LOOP](https://github.com/samirhvbr/skill-loop), **vendorizada** — um retrato de `skill/loop/` de lá, com a versão e o commit de origem no `VERSION.md` dela. Não edite aqui: mude no upstream e rode de novo o `vendor.sh` de lá, que substitui a pasta inteira. |
| `prompts/` | `continuacao.md` e `reabastecimento.md`, os modelos que o `hooks/loop-stop.py` procura três níveis acima de si. A cópia da skill fica quebrada sem eles, e em silêncio. Vêm do mesmo `vendor.sh`. |

**A skill loop não registra hook neste repositório.** O hook `Stop` que devolve o
agente à fila é global, instalado pelo `install.sh` do skill-LOOP no config dir do
Claude Code, e aponta para o repositório skill-LOOP, não para esta cópia. Ele é
inerte enquanto não existir `.loop/STATE.json`: a `.loop/` só nasce quando alguém
arma o loop (`loop_ctl.py armar`), e armar é o opt-in explícito. A cópia daqui é o
que o agente **lê** (`SKILL.md`, `loop_ctl.py`); depois de um bump no upstream ela
é a metade velha até o `vendor.sh` rodar de novo. **Nenhuma permissão foi
concedida para ela:** rodar `python3 .claude/skills/loop-work/loop_ctl.py` pede
confirmação, como qualquer comando fora do allow-list.

## O deny-list vence o allow-list — sempre

Nenhuma entrada do allow-list reabre uma regra negada — esse mecanismo não
mudou. O que é negado mudou em 24/09/2026, por decisão do dono (ADR-028 do
repodocs):

- **Negado:** leitura de chave por caminho — `*.pem`, `*.key`, `*.p8`, `*.p12`,
  `*.pfx`.
- **Pede confirmação:** `sudo`, `rm -rf`, `curl`/`wget` em pipe para um shell,
  `gh repo edit`, `gh repo delete`.
- **Em lista nenhuma:** `git push --force` / `-f`, `git reset --hard`,
  `git clean -fd` e a leitura do `.env`. Um force-push e uma leitura do `.env`
  casam com `Bash(git push:*)` e `Read` no allow-list e rodam sem perguntar;
  `reset --hard` e `clean -fd` caem na confirmação padrão. Não reescrever
  histórico publicado é regra de conduta aqui, não bloqueio.

## Conceder uma permissão nova é ato do dono

O skeleton concede **leitura, escrita e git, e mais nada**. Até 28/09/2026 nada da
stack estava concedido, de propósito: o b3monitor não tinha código nem stack. Em
29/09/2026 o dono decidiu a stack (D-01…D-08 do 2.0) e concedeu **só o build**,
na tabela abaixo. Continua sem concessão tudo o que não está nela — dependência
nova (`composer require`, `go get`), `sudo`, o PostgreSQL do sistema, deploy.

Quando uma permissão precisar existir, ela é **escrita no `settings.json` junto
com o motivo e com o jeito de reverter** — nunca aplicada em silêncio, e nunca
deixada como promessa em prosa.

Essa última cláusula não é estilo. Num repositório irmão, o guia do agente dizia
que `git pull` estava "pré-autorizado (allow)" sem que regra nenhuma existisse
em arquivo de settings algum; a norma e o artefato discordaram por semanas.
Norma que não bate com o artefato é defeito.

<!-- Registre cada concessão aqui quando fizer: o quê, quando, por quê, como
     reverter. -->

| Concedido | Quando | Por quê | Reverte com |
|---|---|---|---|
| `php artisan`, `php vendor/bin/pest`, `composer install\|validate\|test` | 29/09/2026 | A central é Laravel (D-01/D-06); a F0-01 precisa de migrações e de suíte Pest sem um prompt por comando | Apagar as cinco linhas do `allow` |
| `go build\|test\|vet\|fmt\|mod tidy` | 29/09/2026 | A sonda e o vigilante são Go (D-07); mesmo motivo | Apagar as cinco linhas do `allow` |
| `make` | 29/09/2026 | O `Makefile` (`test`, `verify`, `lab-up`, `demo`, `acceptance-lab`) é o ponto de entrada dos comandos entregáveis. **Amplo por natureza:** roda o que o Makefile do repositório disser, e ele é versionado e revisado como qualquer arquivo | Apagar a linha do `allow`; o agente passa a chamar `go test`/`php vendor/bin/pest` direto |
| `initdb` e `pg_ctl` do PostgreSQL 17, e `psql -h 127.0.0.1 -p 54329` | 29/09/2026 | Cluster de desenvolvimento **em espaço de usuário**, na porta 54329, sem sudo e sem tocar o PostgreSQL do sistema (5432). O `psql` só alcança essa porta | Apagar as três linhas do `allow` |
| `ArtifactData` | 29/09/2026 | Único caminho para o loop atualizar o artefato de status da produção sem republicar a página. Escreve só no `db` daquele artefato, que não guarda segredo, IP nem hostname | Apagar a linha do `allow`; o loop registra em `.loop/ASSUMPTIONS.md` que não atualizou |
| **`ask`:** `composer require\|update`, `go get` | 29/09/2026 | Dependência nova é decisão de cadeia de suprimento: o agente pede, o dono decide | Apagar as três linhas do `ask` |
| **`deny`:** `php artisan migrate:fresh\|migrate:reset\|db:wipe` | 29/09/2026 | Espelha a raiz do BLUE3: apagar banco não se faz por comando de agente | Apagar as três linhas do `deny` |
