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
| `settings.json` | O perfil **ativo**, versionado. Hoje: **nada que escolha o modelo**, `effortLevel: xhigh` (um padrão — `/effort` muda para a sessão), `defaultMode: plan`, deny-list de 5 regras, ask-list de 8 e allow-list de 17 entradas. |
| `settings.local.json` | Override local da máquina, **no `.gitignore`** — pode guardar caminhos e tokens que pertencem a uma máquina só. Nunca commite. |

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

O skeleton concede **leitura, escrita e git, e mais nada**. Nada da stack está
concedido — nem build, nem teste, nem gerenciador de pacotes, nem cliente de
banco. É de propósito, e aqui ainda mais: em 28/09/2026 o b3monitor não tem
código nem stack.

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
| _(nada ainda)_ | | | |
