# Runbook — b3monitor

> **Status:** `ACTIVE` · Da máquina limpa ao ambiente rodando, e as checklists
> que seguram uma release.

<!-- As seções 1–4 são deste projeto e ficam a preencher: o b3monitor ainda não
     tem código nem stack (28/09/2026), e o material do projeto vai primeiro para
     a .continue/. As seções 5–7 vêm do padrão da frota e já estão corretas —
     mantenha-as. -->

## 1. Requisitos

<!-- Padrão do skeleton, a substituir quando a stack existir. -->

_A preencher: runtimes e versões, pacotes de sistema, contas e acessos
necessários._

## 2. Da máquina limpa até rodando

```bash
git clone git@github.com:BLUE3-ISP/b3monitor.git
cd b3monitor
git config core.hooksPath tools/git-hooks   # uma vez por clone — ver §5

# instalar, configurar, rodar — a preencher
cp .env.example .env
```

<!-- Padrão do skeleton, a substituir quando a stack existir. O .env.example
     ainda não existe; o cp acima só vale depois que ele for criado. -->

_A preencher: dizer qual dos caminhos é este — rodar com Docker ou desenvolver
com a cadeia inteira. Se houver dois, dizer o que cada um exige e o que fazer
quando ele não sobe._

## 3. Configuração

<!-- Padrão do skeleton, a substituir quando a stack existir. -->

_A preencher: o que significa cada variável do `.env.example`, quais são
obrigatórias e quais são segredos que nunca são commitados
([security.md](security.md), seção de segredos e configuração)._

## 4. Deploy

<!-- Padrão do skeleton, a substituir quando a stack existir. -->

_A preencher: como uma mudança chega à produção, quem pode fazer, o que ela
reinicia e o que fazer quando falha no meio. Seja específico sobre as
armadilhas — um `config:cache` mais novo que o `.env` significa que o `.env` não
vale, e um worker que carregou a configuração ao subir não a relê._

## 5. Os hooks de git

Uma vez por clone — o seu e o de cada colaborador:

```bash
git config core.hooksPath tools/git-hooks
```

**Confirme os dois sentidos antes de confiar neles:**

```bash
git commit --allow-empty -m "feat: teste"                      # tem que ser REJEITADO
git commit --allow-empty -m "0.1.0 - primeiro commit do repo"  # tem que ser aceito
```

A `HOOK_ESCAPE_VAR` no topo de cada hook se chama `B3MONITOR_NO_HOOK`.
Regras: [versioning.md](versioning.md).

## 6. Checklist antes de tornar o repositório público

Um repositório privado acumula conteúdo que contava com a privacidade. Antes de
virar a visibilidade:

- [ ] `git log -p | grep -iE 'password|secret|token|api[_-]?key'` — varra o
      **histórico**, não só a árvore de trabalho. Um segredo removido do HEAD
      continua em todo clone.
- [ ] Todo segredo que já foi commitado foi **rotacionado**, não só apagado.
- [ ] Nenhum hostname interno, faixa de IP privada ou caminho de infraestrutura
      que não deva ser público.
- [ ] **ADR-001 revisto antes de abrir** — repositório público sem licença é
      juridicamente fechado; decidir a licença, e o `NOTICE` que concorda com
      ela, é o primeiro passo ([decisions.md](decisions.md)).
- [ ] O `SECURITY.md` aponta um endereço de reporte que alguém de fato lê.
- [ ] Todo documento prescritivo declara seu status; nada se lê como atual
      enquanto descreve algo abandonado.
- [ ] A `.continue/` não guarda item concluído nem detalhe de meia página.
- [ ] O `README.md` descreve o que o projeto **é**, não o que ele ia ser. O
      idioma continua português: o conjunto `BLUE3-ISP` é delimitado pelo dono
      no GitHub, não pela visibilidade.
- [ ] Nenhum placeholder sobrando do skeleton:
      `grep -rn '<[A-Z_]\+>' . --exclude-dir=.git`

## 7. Verificando se o repositório continua conforme

```bash
# os gêmeos são idênticos abaixo do H1
diff <(tail -n +2 CLAUDE.md) <(tail -n +2 AGENTS.md)

# o version.md é um X.Y.Z puro
grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$' version.md && echo ok

# o settings.json é JSON válido
python3 -m json.tool .claude/settings.json > /dev/null && echo ok

# toda versão do histórico tem tag e Release (imprime o que falta)
./tools/release.sh --backfill --dry-run
```

**Se o `diff` dos gêmeos acusar qualquer coisa além do comentário de
espelhamento, uma edição foi aplicada num arquivo e não no outro.**
