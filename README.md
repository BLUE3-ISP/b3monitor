# b3monitor

> **Status:** `ACTIVE`

**Blue3 Monitor.** Repositório privado da `BLUE3-ISP`, criado em 28/09/2026.
**Ainda não há código.** Hoje ele guarda só a estrutura de documentação do
padrão da frota; o escopo, a stack e onde ele roda chegam como material de
definição na [`.continue/`](.continue/README.md) e só depois viram documento
aqui.

<!-- Padrão do skeleton a substituir: quando o projeto existir, este parágrafo
     diz o que ele é e para quem, e a seção abaixo diz como rodar. O que fica do
     skeleton é a FORMA: o que é, como rodar, onde está a documentação, e a
     regra de idioma. -->

## Primeiros passos

```bash
git clone git@github.com:BLUE3-ISP/b3monitor.git
cd b3monitor
git config core.hooksPath tools/git-hooks   # uma vez por clone
# instalar e rodar — a preencher quando houver stack
```

## Documentação

| Caminho | O que é |
|---|---|
| [docs/](docs/README.md) | **O registro** — arquitetura, decisões, segurança, runbooks |
| [.continue/](.continue/README.md) | **A fila** — o que ainda está em aberto, e quem decide |
| [.claude/](.claude/README.md) | Postura de permissões dos agentes — o modelo é escolha do usuário, nunca desta pasta |
| [CHANGELOG.md](CHANGELOG.md) | **O histórico** — mais novo primeiro; cada cabeçalho é um subject de commit |
| [version.md](version.md) | **A autoridade única sobre a versão** — lida como o primeiro `X.Y.Z` do arquivo. Todo bump vira uma tag e uma Release publicada |

## Contribuindo

```bash
git pull
git config core.hooksPath tools/git-hooks   # uma vez por clone
```

Escreva a entrada do `CHANGELOG.md`, bumpe o `version.md` no mesmo commit, e
commite com o cabeçalho da entrada como subject — `0.1.1 - descrição curta`.
Regras: [docs/versioning.md](docs/versioning.md).

## Padrão

A estrutura de documentação deste repositório vem do padrão da frota, em
[samirhvbr/repodocs](https://github.com/samirhvbr/repodocs). A norma em si vive
lá e **não** é copiada aqui, de propósito, para existir um lugar só onde mudá-la.
O que veio de lá, e como, está em [docs/repodocs.md](docs/repodocs.md).

Este repositório **não tem `LICENSE`** — é código interno da Blue3, em
repositório privado. O motivo está no
[ADR-001](docs/decisions.md).

## Idioma

**Português, em tudo** — documentos, mensagens de commit, pull requests, issues,
comentários de código, changelog. O repositório é da `BLUE3-ISP`, e o conjunto
interno da Blue3 é escrito em português
([conventions.md §8](https://github.com/samirhvbr/repodocs/blob/master/docs/conventions.md#the-blue3-internal-repositories-are-written-in-portuguese),
ADR-020 do repodocs). **Identificadores de código são em inglês**, como em
qualquer repositório da frota. Nada é bilíngue.
