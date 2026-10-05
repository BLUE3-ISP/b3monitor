# Runbook — b3monitor

> **Status:** `ACTIVE` · Da máquina limpa ao ambiente rodando, e as checklists
> que seguram uma release.

<!-- As seções 1–3 descrevem o que existe em 01/10/2026 (PostgreSQL de dev e a
     central com a suíte Pest). A seção 4, deploy, continua a preencher: nenhum
     deploy existe, e `make deploy-central` é recusado de propósito. As seções
     5–7 vêm do padrão da frota e já estão corretas — mantenha-as. -->

## 1. Requisitos

Debian 13 (Trixie), sem Docker ([ADR-005](decisions.md)). Versões fixadas no
[ADR-013](decisions.md); a inspeção que as sustenta está em
[status/SESSOES.md](status/SESSOES.md).

| Peça | Versão | Para quê |
|---|---|---|
| PHP | 8.4, com `pdo_pgsql`, `pgsql`, `intl`, `mbstring`, `openssl`, `curl`, `zip` e `bcmath` | A central |
| Composer | 2.x | Dependências da central |
| PostgreSQL | 17, com os binários em `/usr/lib/postgresql/17/bin` | O banco de desenvolvimento (`make pg-up`) |
| Go | toolchain `go1.26.8`, baixada pelo próprio Go a partir do `go.mod` | A sonda e o vigilante. **O primeiro comando `go` baixa a toolchain pela rede** (medido: cerca de 10 s e 235 MB em disco) |
| Node | 24 | Só para compilar os assets do painel (item B9) |
| `make`, `git` | os do sistema | Os comandos de [02 §10](../.continue/b3monitor_2.0/02_ARQUITETURA_E_STACK.md) |

Acesso: o repositório `BLUE3-ISP/b3monitor` no GitHub. Nenhuma conta de provedor, credencial de
produção ou token de canal é necessária para desenvolver.

## 2. Da máquina limpa até rodando

Um caminho só: **desenvolver com a cadeia inteira**, sem contêiner.

```bash
git clone git@github.com:BLUE3-ISP/b3monitor.git
cd b3monitor
git config core.hooksPath tools/git-hooks   # uma vez por clone — ver §5

make pg-up                                  # PostgreSQL 17 de dev, porta 54329
cd central
composer install
# crie o .env como descrito na §3, e depois:
php artisan key:generate
cd ..
make test                                   # a suíte da central, no banco b3mon_test
```

Quando algo não sobe:

| Sintoma | Causa e saída |
|---|---|
| `make pg-up` diz "a porta 54329 já está em uso" | Outro processo segura a porta e **nada foi iniciado**. Descubra qual (`ss -ltnp`) antes de continuar; não troque a porta sem decidir |
| `make pg-status` sai com erro 3 | O cluster está parado. `make pg-up` o sobe, e os dados continuam em `lab/.pgdata` |
| O Pest recusa: "a suíte só roda em pgsql / b3mon_test / porta 54329" | A conexão de teste aponta para outro banco. É a trava do `tests/TestCase.php`: ela existe para o `RefreshDatabase` nunca apagar o `b3mon_dev`. Não a remova; corrija o `phpunit.xml` |
| Erro `SQLSTATE[08006]` ao rodar o Pest | O PostgreSQL de dev está parado: `make pg-up` |

O laboratório (`make lab-up`, `make demo`, `make smoke`, `make lab-down`) está em [lab.md](lab.md). O que **não** existe ainda: `make verify`
falha de propósito até o A8b, `make acceptance-lab` e `make probe-doctor` falham com `não implementado: <item>`, e o `make deploy-central` é recusado.

## 3. Configuração

A central lê o `.env` de `central/`, **fora do git**. O `.env.example` que o esqueleto do Laravel
gera foi removido e precisa ser recriado (item 🔒 da fila: a sessão que o apagou não podia
escrever em `.env*`). Até lá, estas são as variáveis, e o que cada uma é:

| Variável | Valor de desenvolvimento | O que é |
|---|---|---|
| `APP_NAME` | `b3monitor` | Nome da aplicação |
| `APP_ENV`, `APP_DEBUG` | `local`, `true` | Em produção: `production`, `false`. **`APP_DEBUG=true` fora do desenvolvimento vaza código e segredos** |
| `APP_KEY` | gerada por `php artisan key:generate` | **Segredo.** Cifra sessões e dados. Nunca no git, nunca no mesmo backup do banco ([11 §6](../.continue/b3monitor/11_IMPLANTACAO_OPERACAO_E_RUNBOOKS.md), C-16 do 2.0) |
| `APP_LOCALE`, `APP_FAKER_LOCALE` | `pt_BR` | Apresentação em português; persistência e contratos seguem em UTC |
| `DB_CONNECTION` | `pgsql` | A central só fala PostgreSQL ([ADR-007](decisions.md)) |
| `DB_HOST`, `DB_PORT` | `127.0.0.1`, `54329` | O cluster de dev de `make pg-up` |
| `DB_DATABASE`, `DB_USERNAME` | `b3mon_dev` | O banco de desenvolvimento. A suíte usa **outro**, `b3mon_test`, fixado no `phpunit.xml` |
| `DB_PASSWORD` | vazio | O cluster de dev é `trust` em loopback. **Nunca uma credencial de produção aqui** |
| `SESSION_DRIVER`, `QUEUE_CONNECTION`, `CACHE_STORE` | `database` | Sem Redis no caminho crítico ([ADR-008](decisions.md)) |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL` | `stack`, `single`, `debug` | Registro em `storage/logs` |
| `BROADCAST_CONNECTION`, `FILESYSTEM_DISK`, `MAIL_MAILER` | `log`, `local`, `log` | Nada sai da máquina em desenvolvimento |

**Segredos que nunca são commitados:** `APP_KEY`, qualquer `DB_PASSWORD` real, tokens de Telegram,
credenciais FCM e APNs, e o que vier em `.env.production`. O `.gitignore` cobre `.env` e
`.env.*`, menos o `.env.example`, que só leva placeholders
([security.md](security.md), seção de segredos e configuração).

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
      que não deva ser público — o endereço do servidor `cicd` (§8) inclusive.
- [ ] **Nenhum runner do `cicd` registrado neste repositório** (§8). Num
      repositório público, qualquer pull request de fork roda código numa
      máquina da rede do escritório. O runner sai **antes** de a visibilidade
      mudar.
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

## 8. CI — o servidor `cicd`

**Quando o b3monitor precisar de CI, ela roda no servidor `cicd`, e não nos
runners hospedados do GitHub** ([ADR-002](decisions.md)).

| | |
|---|---|
| Máquina | `cicd` — VM Debian 13 no Proxmox, 8 vCPU, 16 GB de RAM |
| Endereço | `100.64.100.240` |
| Acesso | `ssh samir@100.64.100.240` — usuário `samir`, com `sudo` |
| O que faz | Roda os jobs do GitHub Actions dos repositórios **privados** em runners self-hosted, que não gastam os minutos hospedados da conta |
| Painel | http://100.64.100.240:8080/ — só na rede do escritório, sem login |
| Runbook da máquina | [samirhvbr/shvia-web `deploy/servidor-cicd.md`](https://github.com/samirhvbr/shvia-web/blob/master/deploy/servidor-cicd.md) — **a fonte**: o que está instalado, o layout dos discos, os runners, o painel. Não copie aqui |

**Em 28/09/2026 não há runner para este repositório, e nada para ele rodar:**
não há código nem suíte. Nada foi registrado no servidor. Esta seção existe para
que, no dia em que houver o que testar, o caminho já esteja escrito.

**Quando precisar**, siga o *Adding a repository* do runbook da máquina, com o
que muda por este repositório ser de **organização**, e não de conta pessoal:

- **O token de registro.** Para um runner só deste repositório:
  `gh api -X POST repos/BLUE3-ISP/b3monitor/actions/runners/registration-token -q .token`,
  e o `--url` do `config.sh` é `https://github.com/BLUE3-ISP/b3monitor`. Na
  organização existe uma opção que a conta pessoal não tem: um runner **da
  organização** (`orgs/BLUE3-ISP/actions/runners/registration-token`, `--url`
  `https://github.com/BLUE3-ISP`), que pode servir a todos os repositórios da
  `BLUE3-ISP` de uma vez. Qual dos dois usar é decisão desse dia, e vira ADR.
- **O painel ainda não lê execuções de organização.** Um runner da `BLUE3-ISP`
  aparece lá com aviso, e as execuções dele ficam de fora dos números.
- **Os jobs apontam para o servidor pela variável do repositório**, como nos
  outros: `runs-on: ${{ vars.CI_RUNNER || 'ubuntu-latest' }}` no workflow, e
  `gh variable set CI_RUNNER --body <rótulo-do-runner> -R BLUE3-ISP/b3monitor`
  liga; `gh variable delete CI_RUNNER -R BLUE3-ISP/b3monitor` devolve os jobs
  ao GitHub.

**Três regras da máquina que valem aqui do mesmo jeito:**

- 🔴 **Nunca registre esse runner num repositório público.** Qualquer pull
  request de fork roda código nele, e a máquina fica na rede do escritório. Por
  isso o checklist da §6 manda retirar o runner antes de abrir este repositório.
- **O `.env` de todo runner novo fixa `LANG=C.UTF-8` e `LC_ALL=C.UTF-8`**, e
  apaga a linha `LANG=pt_BR.UTF-8` que o `config.sh` copia da VM, instalada em
  português. Sem isso, número com vírgula decimal quebra teste que espera ponto
  — já quebrou cinco no primeiro run do shvia-web.
- **O token de registro dura uma hora e passa pelo stdin, nunca por arquivo.**

**A máquina não faz deploy.** CD precisaria de chave SSH para produção, e isso
não existe; o deploy continua sendo comando do dono.

**Até existir um runner, o único job de Actions deste repositório — o
`.github/workflows/release.yml` — não roda:** nos runners hospedados ele é
recusado por cobrança antes de começar. A Release é publicada à mão, com
`./tools/release.sh` depois de todo push que bumpa o `version.md`
([versioning.md](versioning.md)).
