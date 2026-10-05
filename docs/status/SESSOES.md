# Sessões — registro de execução

> **Status:** `ACTIVE` · Registro **append-only** do trabalho do agente neste repositório:
> o que mudou, o que foi realmente executado e o que não pôde ser. Uma sessão nova entra
> **no fim**; nada é reescrito. Modelo: `.continue/b3monitor/13_CHECKLIST_DO_PILOTO_E_STATUS.md` §9.

---

## 2026-10-01 · A1 — inspeção e versões da toolchain

| Campo | Valor |
|---|---|
| Data e sessão | 01/10/2026 · sessão do Claude Code aberta na pasta guarda-chuva `~/x/BLUE3`, loop armado nela (rodada de F0-00 e F0-01) |
| Repositório / branch / commit | `BLUE3-ISP/b3monitor` · `master` · parte de `f761ae6` (versão 0.1.7) |
| Fatia do backlog | F0-00 (inspeção e versões); item A1 da fila do loop |
| Arquivos alterados | `docs/status/SESSOES.md` (novo), `docs/README.md` (índice), `.loop/QUEUE.md`, `CHANGELOG.md`, `version.md` |
| Decisões DE/ADR | Nenhuma tomada aqui. As escolhas da tabela abaixo viram **ADR-013 no item A2** |
| Teste não executado e motivo | Nada a testar: o item só inspeciona e consulta. Nenhum pacote foi instalado |
| Diferença laboratório × real | Não se aplica |
| Bloqueio humano | Nenhum |
| Tarefas livres | A2 em diante |
| Próxima fatia exata | A2 — ADR-004 a ADR-013 em `docs/decisions.md` |
| Estado dos gates | Todos como estavam: `not_started`, e `G-UP` `blocked` (Q-02) |

### Comandos realmente executados (máquina de desenvolvimento, 01/10/2026)

`php -v`, `composer -V`, `go version`, `psql -V`, `node -v`, `npm -v`, `git --version`,
`make --version`, `apt-cache policy postgresql-17`, `php -m`. Consulta às fontes oficiais pelas
URLs da coluna *Fonte*, todas lidas em 01/10/2026.

### Versão instalada × suportada × escolhida

| Peça | Instalada | Suportada pelo mantenedor (01/10/2026) | Escolhida | Fonte |
|---|---|---|---|---|
| PHP | 8.4.26 | 8.4 em suporte ativo até 31/12/2026 e de segurança até 31/12/2028; 8.5 é a mais nova | **8.4** agora. Reavaliar 8.5 no 4º trimestre, quando o suporte ativo do 8.4 acaba | php.net/supported-versions.php |
| Laravel | — (não instalado) | **13.34.0** é a atual (29/09/2026); aceita PHP 8.3 a 8.5. A tabela de release notes da documentação lista o 13 só como "Q1 2026 / Q3 2027 / Q1 2028", sem data exata | **`^13.0`**; o rascunho 02 do 2.0 já dizia Laravel 13 | api.github.com/repos/laravel/framework/releases/latest; laravel.com/docs/master/releases |
| Livewire | — | **4.4.7** (28/09/2026); pede Laravel 10 ou mais novo e PHP 8.1 ou mais novo | **`^4.0`** | api.github.com/repos/livewire/livewire/releases/latest; livewire.laravel.com/docs/installation |
| Pest | — | **5.2.1** (17/09/2026); **exige PHP 8.4 ou mais novo** | **`^5.0`**. Fixa o piso de PHP em 8.4 | api.github.com/repos/pestphp/pest/releases/latest; pestphp.com/docs/installation |
| CoreUI | — | **5.9.0** (20/07/2026), pacote npm `@coreui/coreui` | **`^5.9`**, só para o painel (D-06) | api.github.com/repos/coreui/coreui/releases/latest; coreui.io/docs |
| Composer | 2.10.0 (28/05/2026) | — | Manter | não verificado: ferramenta do sistema, sem consulta a fonte oficial nesta passada |
| Node / npm | 24.15.0 / 12.0.2 | Node 24 é LTS e **entra em manutenção em 20/10/2026**, com fim em 30/04/2028; o 26 vira LTS em 28/10/2026 | Node 24 **só para compilar os assets** do painel; sem decisão agora | raw.githubusercontent.com/nodejs/Release/main/schedule.json |
| PostgreSQL | 17.11 (Debian 13) | 17.11 é o último minor do 17, com fim em **08/11/2029**; a major atual é a 18 | **17** (D-03), do repositório do Trixie | postgresql.org/support/versioning |
| **Go (toolchain)** | **1.24.4** | **Suportadas: 1.27.1 e 1.26.8.** O 1.24 está **fora** da política de suporte. Os módulos abaixo pedem `go 1.26.0` ou `go 1.25.0` | **go 1.26.8**, fixada por `toolchain go1.26.8` no `go.mod` | go.dev/dl/?mode=json |
| `golang.org/x/net` (icmp) | — | v0.59.0 (08/09/2026), `go 1.26.0` | **v0.59.0** | proxy.golang.org/golang.org/x/net/@latest e `@v/v0.59.0.mod` |
| `github.com/miekg/dns` | — | v1.1.73 (19/08/2026), `go 1.25.0` | **v1.1.73** | proxy.golang.org/github.com/miekg/dns/@latest e `@v/v1.1.73.mod` |
| Driver SQLite para Go | — | `modernc.org/sqlite` v1.60.1 (29/09/2026), `go 1.26.0`; implementação em Go puro, sem cgo, segundo o projeto | **`modernc.org/sqlite` v1.60.1**. A ausência de cgo se **prova no A7** com `CGO_ENABLED=0`; aqui só consta a documentação do projeto | proxy.golang.org/modernc.org/sqlite/@latest e `@v/v1.60.1.mod` |
| git / make | 2.47.3 / 4.4.1 | — | Manter | não verificado: ferramentas do sistema |

### O que a inspeção mostrou

1. **O Go instalado não serve para o que a stack pede.** O 1.24.4 está fora de suporte, e os
   módulos escolhidos exigem `go 1.26.0` (`x/net` e `sqlite`) e `go 1.25.0` (`miekg/dns`). Aqui
   o `GOTOOLCHAIN` está no padrão `auto` (conferido com `go env`), então o Go 1.24.4 **não recusa**:
   ele baixa a toolchain pedida pelo `go.mod`, do proxy oficial e conferida pelo banco de
   checksums, e a deixa em `~/go/pkg/mod`. A alternativa é uma instalação em espaço de usuário.
   **Isso é decisão do ADR-013 (A2).** Consequência a vigiar no A7: o primeiro `go test` baixa a
   toolchain pela rede (tamanho não medido), e uma máquina sem rede, como um servidor de CI
   isolado, precisa dela já presente.
2. **O piso de PHP é 8.4**, por causa do Pest 5. A máquina está nele (8.4.26), e o Laravel 13
   aceita 8.3 a 8.5, então não há conflito. O suporte ativo do 8.4 acaba em 31/12/2026.
3. **A documentação do Laravel está defasada em relação ao release.** A página de release notes
   ainda descreve o 13 como "Q1 2026", mas a release 13.34.0 saiu em 29/09/2026. Vale a versão
   do GitHub; as datas de fim de suporte do 13 (3º trimestre de 2027 e 1º de 2028) são as
   aproximadas da documentação.
4. **Extensões de PHP:** `pdo_pgsql`, `pgsql`, `intl`, `mbstring`, `openssl`, `curl`, `zip` e
   `bcmath` estão presentes. `pdo_sqlite` e `sqlite3` **não** estão, e não precisam: o spool
   SQLite é do agente Go. `redis` está instalado, mas a D-04 tira o Redis do caminho crítico.
5. **O PostgreSQL de produção da central** (outra máquina, Debian 13) não foi inspecionado: a
   linha do PostgreSQL acima é a desta estação de desenvolvimento.

---

## 2026-10-01 a 2026-10-05 · A2 a A8a — bootstrap da F0-00 e contratos

| Campo | Valor |
|---|---|
| Data e sessão | 01/10 a 05/10/2026 · a mesma sessão do A1, loop armado nela |
| Repositório / branch / commit | `BLUE3-ISP/b3monitor` · `master` · de `bba5cad` (0.1.8) até a 0.1.15 |
| Fatia do backlog | F0-00; itens A2 a A8a da fila do loop |
| Arquivos alterados | `docs/decisions.md` (ADR-004 a ADR-014), `.continue/b3monitor_2.0/PRECEDENCIA.md`, `Makefile`, `lab/pg.sh`, `central/` (Laravel 13, módulos, rota de saúde, suíte), `agents/probe` e `agents/watchdog` (módulos Go), `contracts/`, `docs/runbook.md` §1 a §3, `CLAUDE.md`/`AGENTS.md` |
| Decisões DE/ADR | ADR-004 a ADR-013 (aceitos); **ADR-014 proposto**, à espera do dono. Premissas 4 a 6 em `.loop/ASSUMPTIONS.md` |

### Comandos realmente executados e resultado

| Item | Comando | Resultado |
|---|---|---|
| A3 | `grep -c "^\| ID \|" PRECEDENCIA.md` para os 39 IDs; contagem de seções; `grep -il 'blackbox\|redis\|docker'` | 39 linhas, uma por ID; 14 seções; 8 documentos 1.0 casam, todos citados |
| A4 | `make test`, `make verify`, `make pg-up`, `make deploy-central`, `make docs-bundle` | os quatro primeiros falharam com a mensagem esperada; o bundle gerou 2.401 linhas |
| A5 | `make pg-up` (duas vezes), `make pg-status`, `psql -h 127.0.0.1 -p 54329 -U b3mon_dev -d b3mon_dev -c 'select version()'`, `make pg-down` e `make pg-up` com dado gravado antes | PostgreSQL 17.11 respondeu; idempotente; o dado sobreviveu; o PostgreSQL do sistema (5432) seguiu ativo |
| A6 | `composer create-project`, `composer require` do Pest 5 com PHPUnit 13, `./vendor/bin/pest` | Laravel 13.34.0; Pest 5.3.0; **3 testes, 15 asserções**. A trava do banco de teste foi provada nos dois sentidos |
| A7 | `go vet`, `go test -race`, `CGO_ENABLED=0 go build`, `gofmt -l` | limpos; toolchain baixada em cerca de 10 s e 235 MB em disco |
| A8a | validadores descartáveis (Go e PHP) fora do repositório; Pest e `go test` estruturais | **44 fixtures, 0 divergências** nos dois; lote de 500 válido e de 501 inválido; cada inválida falha pela regra declarada. Testes estruturais: Pest com 8 testes e 384 asserções, e Go, ambos provados pela quebra intencional do contrato |

### Teste não executado e motivo

- **Validação de payload contra os schemas, dentro do repositório:** não existe. Depende de uma biblioteca em
  cada linguagem, que é dependência nova fora do ADR-013 (item **A8b**, `- 🔒`, ADR-014 proposto). `make
  verify` falha por isso, de propósito.
- **`phpstan` e `golangci-lint`:** não instalados, e não fazem parte do que o `make verify` roda hoje.
- **O PostgreSQL de produção da central e qualquer ambiente real:** fora do escopo da rodada.
- **O `central/.env.example`:** foi removido por mim e não pôde ser recriado nesta sessão (`.env*` negado pelo
  `settings.json` da raiz). Item `- 🔒`.

### Diferença laboratório × real

O PostgreSQL de dev usa `trust` em loopback, os contratos têm só dados fictícios `lab`, e os validadores
rodaram em módulos descartáveis. Nada disso é evidência de ambiente real.

### Riscos e regressões encontrados

- O esqueleto do Laravel trouxe `AGENTS.md` e `CLAUDE.md` mandando baixar o PHP por `curl | bash` e instalar o
  Laravel Boost. **Não foram seguidos** e os arquivos foram removidos.
- O Pest 5 exige o PHPUnit 13, que o esqueleto do Laravel 13 ainda não acompanha (registrado no ADR-013).
- A biblioteca PHP candidata (`opis/json-schema`) está sem release há quase um ano (ADR-014).

### Bloqueio humano e próxima fatia

Bloqueio: aprovar ou trocar o **ADR-014** (A8b) e recriar o `central/.env.example`. Tarefas livres: B1 em diante.
Próxima fatia exata: **B1**, o motor de estado `App\State\Engine`, puro e determinístico.

### Estado dos gates

Todos como estavam: `not_started`, e `G-UP` `blocked` (Q-02).
