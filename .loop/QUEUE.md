# Fila do loop — produção do b3monitor, até "F0-01 FECHADA"

Uma linha por unidade de trabalho. O hook entrega **o primeiro `- [ ]`**. Cada item se
executa sozinho, com o critério de pronto no fim da própria linha.

## Regras de cada fechamento (valem para todo item; leia antes de marcar `- [x]`)

1. **Regra de leitura do material (dono, 29/09/2026): o 2.0 complementa o 1.0 e, no que
   conflitar, vale o 2.0.** O material está em `.continue/b3monitor/` (1.0) e
   `.continue/b3monitor_2.0/` (2.0). Depois do item A3, leia o `PRECEDENCIA.md` do 2.0
   antes de cada documento 1.0. Dentro de um pacote vale 07 › 03 › 05 › 06 › 09; o 14
   define os enums. Um documento 1.0 **nunca** é reescrito por esta rodada.
2. **Português em tudo** (repositório da BLUE3-ISP): código, comentário, commit,
   changelog, ADR. Só o identificador de código é inglês. Os hooks e o `release.sh` são
   cópias literais do repodocs e ficam como estão.
3. **Fechar um item é commitar:** entrada do `CHANGELOG.md` primeiro, `version.md`
   bumpado, um assunto por commit (`X.Y.Z - descrição`), `CLAUDE.md` e `AGENTS.md` iguais
   abaixo do H1, `git push`, e `./tools/release.sh --repo BLUE3-ISP/b3monitor` depois de
   todo push que bumpou (o Actions da organização está travado por cobrança; ADR-002).
   Push refused → pare e registre; nunca force.
4. **Atualize o quadro** ao fechar (`ArtifactData`, URL no `CLAUDE.md`; **liste a coleção
   antes**, porque escrever em documento existente exige `if_version`): `fila/<id>` →
   `feito`, `meta/producao` (`versao`, `commit`, `loop_fechados`, `proxima`,
   `atualizado_em`) e, só **com a evidência na mão**, `items/`, `fatia/` e `gates/` — com os
   estados de 09 §1. `implemented` não é `verified_lab`, e um simulador nunca passa de
   `verified_lab`. Se o `ArtifactData` pedir confirmação, siga e registre em
   `.loop/ASSUMPTIONS.md` que o quadro ficou para trás.
5. **Nunca** segredo, token ou dado de produção. PostgreSQL só o de dev, na porta 54329.
   Sem `sudo`. Não use a porta 5432.
6. **Não escreva relato no chat.** O que precisa sobreviver vai para o commit, para
   `docs/` ou para esta fila. Decisão em aberto → adote o padrão razoável **e reversível**
   e registre em `.loop/ASSUMPTIONS.md` (pergunta · decisão · alternativa descartada ·
   como reverter).

## Fase A — bootstrap (F0-00)

- [x] **A1** Inspeção e versões (F0-00; prompt 00 §1; 02 §2): crie `docs/status/SESSOES.md` (append-only, modelo do 13 §9) com o estado real do repositório e da toolchain desta máquina (`php -v`, `composer -V`, `go version`, `psql -V`, `node -v`) contra as versões **suportadas pelos mantenedores oficiais** de Laravel, Livewire, CoreUI, Pest, `golang.org/x/net`, `miekg/dns` e de um driver SQLite para Go que compile sem cgo; não instale nada. Sem como consultar a fonte oficial, escreva `não verificado` na linha e siga. Pronto quando a tabela instalada × suportada × escolhida existe e cada linha cita a fonte consultada.
- [x] **A2** ADR-004 a ADR-013 em `docs/decisions.md`, no formato do próprio arquivo (Status, Contexto, Decisão, Consequências): ADR-004 a regra de precedência 1.0 × 2.0; ADR-005 a ADR-012 uma por D-01…D-08, com o motivo e o "se discordar" de `.continue/b3monitor_2.0/REVISAO_1.0_PARA_2.0.md` §3; ADR-013 as versões fixadas em A1 e o driver SQLite escolhido. Registre em ASSUMPTIONS o conflito 14 §5 (`docs/adr/` × `docs/decisions.md`, já assumido). Atualize `decisoes/*.adr` no quadro com os números. Pronto quando os dez ADRs existem e os links de `docs/` resolvem.
- [x] **A3** Crie `.continue/b3monitor_2.0/PRECEDENCIA.md`, o índice de sobreposição, **sem reescrever nenhum documento 1.0**: para cada documento 00–13, as seções que o 2.0 sobrescreve (D-01…D-08, C-01…C-23, L-01…L-08 da REVISAO §3–§5; INV-01…12; RC-17 e RC-19; T-057′, T-075, T-076) e o que passa a valer, mais a ordem de leitura 2.0 (C-18). Pronto quando cada D, C e L da REVISAO aparece exatamente uma vez, todo documento 00–13 tem a sua seção, e `grep -il 'blackbox\|redis\|docker' .continue/b3monitor/*.md` só cai em documentos que o índice marca como sobrescritos nessas passagens.
- [x] **A4** Layout e Makefile (02 §4 e §10; 14 §5): crie `central/`, `agents/probe/`, `agents/watchdog/`, `contracts/openapi/`, `contracts/schemas/`, `contracts/fixtures/`, `deploy/systemd/`, `lab/`, `evidence/` e `docs/status/` (`.gitkeep` onde vazio) e um `Makefile` com os alvos de 02 §10 **honestos**: o que ainda não tem o que fazer falha com `não implementado: <item>`, nunca passa. Preencha em `CLAUDE.md` e `AGENTS.md` (gêmeos) *Stack*, *Roda localmente com* e *Nunca toque*, e os gatilhos Z/Y/X de bump em `CLAUDE.md`, `AGENTS.md` e `docs/versioning.md`. Pronto quando `make test` falha com a mensagem certa e os gêmeos seguem iguais.
- [ ] **A5** PostgreSQL de dev isolado: `lab/pg.sh` (binários de `/usr/lib/postgresql/17/bin`; `initdb` em `lab/.pgdata`, socket em `lab/.pgrun`, `-p 54329`, `listen_addresses=127.0.0.1`, autenticação local sem senha de produção; roles e bancos `b3mon_dev` e `b3mon_test`) e `make pg-up`, `pg-down`, `pg-status`. Pronto quando `psql -h 127.0.0.1 -p 54329 -U b3mon_dev -d b3mon_dev -c 'select version()'` responde e `git status` não mostra nada de `lab/.pg*`. Sem `sudo` e sem tocar a 5432.
- [ ] **A6** Central em `central/` (D-01, D-06; 02 §2 e §3): `composer create-project` na versão do ADR-013, banco de dev no `.env` local (fora do git; `.env.example` sem segredo), Pest, os namespaces de módulo de 02 §3 e `GET /api/v1/health` com teste. `composer create-project` e `composer require` podem pedir confirmação: se ninguém responder, marque o item `- 🔒` com o comando exato e siga para o A7. Pronto quando `make test` roda o Pest da central e passa.
- [ ] **A7** Agentes Go (D-07; 02 §2; 04): `go mod init github.com/BLUE3-ISP/b3monitor/agents/probe` com a toolchain do ADR-013, um pacote mínimo com teste; `agents/watchdog/` só com `go.mod`; `go.mod` e `go.sum` versionados. Dependência nova (`x/net`, `miekg/dns`, SQLite) só quando um item posterior precisar, e `go get` pede confirmação. Pronto quando `go vet ./...` e `go test ./...` passam nos dois módulos.
- [ ] **A8** Contratos (06 §5 e §6; C-05, C-19): `contracts/schemas/` com JSON Schema de resultado, de bundle de configuração e de heartbeat (`executor.name: native`, `executor.build`, `path_evidence.mechanism` ∈ `netns_pbr`, `dedicated_interface`, `router_readback`, `traceroute_hop`, `none`), `contracts/openapi/openapi.yaml` (3.1) do `POST /api/v1/results`, e um validador que roda em PHP (Pest) e em Go (`go test`) contra as **mesmas** fixtures de `contracts/fixtures/`, válidas e inválidas, identificadas como `lab`. Pronto quando `make verify` valida os contratos nos dois lados.

## Fase B — F0-01, a fatia vertical

- [ ] **B1** Motor `App\State\Engine` na `central/` (03; 02 §3; INV-01…12; C-01): função pura (política versionada + observações elegíveis + instante de avaliação → estado e transições), frescor em slots com margem (`(failure_count+1)×interval` + tolerância de início + margem de transporte), e sem amostra, amostra inválida, relógio inadequado ou dado antigo **não** são saúde nem queda. Pest com as fixtures de 10 §10: sucesso, falha confirmada, ausência, fora de ordem e duplicata; sem banco, rede ou relógio real. Pronto quando `make test` cobre os cinco cenários e cada teste cita as INV que cobre.
- [ ] **B2** Migrações (06 §3 e §9; C-02): `results` particionada por dia (chave única inclui a coluna de partição, sem FK), `ingestion_receipts`, `ingestion_work`, `current_states`, `incidents`, `notification_outbox`, `notification_attempts` e `audit_log`, com identidade, revisão e família IP persistidas desde já; retenção bruta ≥ aceitação + 7 d (baseline 14 d) validada na configuração. Pronto quando `php artisan migrate` e o rollback passam no PG de dev, os testes rodam no banco `b3mon_test` e um teste prova a relação de retenção.
- [ ] **B3** Ingestão (06 §6 e §7; C-19): `POST /api/v1/results` com autenticação de sonda, `Idempotency-Key`, `Content-Encoding: gzip` (expansão até 16 MiB, 500 itens, 4 MiB compactado), recibo e `ingestion_work` **na mesma transação**, e respostas `accepted`, `duplicate` e `rejected_terminal`. Pest: aceito, duplicado, rejeitado e **resposta perdida após commit + reenvio** (o reenvio volta `duplicate` e não cria segundo trabalho). Pronto quando os quatro passam.
- [ ] **B4** Workers (02 §5 e §7; D-04): filas `ingest`, `decide` e `notify` no driver `database`, consumindo `ingestion_work` e a outbox com `FOR UPDATE SKIP LOCKED`, lease com expiração e reconciliador que reenfileira lease vencida; unit modelo `b3mon-worker@.service` em `deploy/systemd/` com o endurecimento de 07 §10 na versão 2.0 (`ProtectSystem=strict`, `NoNewPrivileges` etc.) e o cron do `schedule:run` documentado. Pest: lease vencida é retomada; worker morto no meio não perde resultado nem o processa duas vezes. Pronto quando os dois passam.
- [ ] **B5** Incidente e notificação (03 §9; 06 §4; D-04): a decisão abre e fecha incidente por confirmação e recuperação **sustentadas**; transição e intenção de notificação nascem na mesma transação (outbox); canal `dev` (`provider=dev-log`, `storage/logs/notifications-dev.log`) **identificado como simulado**, sem token real; dedup por `(incident_id, decision_version, reason, channel, recipient)`; reconciliação após replay sem tempestade. Pest: replay de 100 amostras antigas não dispara notificação, e o mesmo incidente é notificado uma vez por decisão. Pronto quando passam.
- [ ] **B6** Coordenador Go (04; 07; parte de F0-02): política local em arquivo versionado que a central **não amplia**, agenda persistente, spool SQLite em WAL (256 MiB + 32 MiB de diagnóstico, C-15), reenvio pelo menos uma vez e heartbeat. `go test`: crash e reinício mantêm a agenda; central fora → spool → reenvio na volta, sem perda e sem duplicar. O driver SQLite é dependência nova (`go get` pede confirmação; a escolha está no ADR-013). Pronto quando os dois passam.
- [ ] **B7** Executor HTTP nativo (D-02; 04 §4; 07; C-06): `net/http` com `DialContext` fixado no IP validado e `TLSClientConfig.ServerName`, **IPv4 explícito e sem fallback de família**, sem porta de escuta (fala com o coordenador por socket Unix), e política de destinos permitidos (CIDR, host, porta) aplicada antes de conectar. `go test` adversarial: DNS rebinding entre validação e conexão (T-052), redirect para destino não permitido, IP privado, loopback e link-local, porta fora da lista. Pronto quando todos os casos são recusados e o caso legítimo passa.
- [ ] **B8** Laboratório (02 §6 e §10; L-02): alvo HTTP em `lab/target/`, **identificado `lab` em toda resposta e log**, com modos `ok`, `falha` e `lento` alternáveis por endpoint local; `make lab-up` e `lab-down` sobem PG, central, worker(s), coordenador, executor e alvo; `make demo` roda o cenário sintético (sucesso, falha → incidente, ausência, replay). Pronto quando `make lab-up && make demo` termina com o incidente aberto e fechado em `notifications-dev.log`.
- [ ] **B9** Painel mínimo (08 §5; D-06; C-12): Blade + Livewire + CoreUI 5 em `central/`, com estados atuais mostrando `as_of`, `valid_until`, cobertura e integridade **separadas de saúde**, e a lista de incidentes; nunca mostrar verde antigo quando a medição perdeu confiabilidade; legível em 1366×768 sem rolagem horizontal; teste Livewire. Livewire e CoreUI são dependências novas (`composer require` e `npm install` pedem confirmação). Pronto quando o Pest do painel passa e uma captura sanitizada fica em `evidence/f0-01/`.
- [ ] **B10** Aceite da F0-01 (10; 09 §2 e §6): `make acceptance-lab` executa sucesso, falha, ausência, duplicata, resposta perdida após commit e recuperação, cada caso citando INV e RC; evidência **sanitizada** em `evidence/f0-01/` com comando, ambiente, versão e commit, resultado; o que não pôde rodar (IPv6 ausente, iOS, upstream real) fica registrado como **bloqueio, nunca como aceite**; teste ignorado não conta como aprovado. Pronto quando o caminho passa de ponta a ponta a partir do zero.
- [ ] **B11** Fechamento (13 §8; 09 §6): atualize o inventário e o quadro de gates do 13 **sem marcar como validado o que só foi codificado** (nada acima de `verified_lab`; G-UP segue `blocked`), `docs/status/SESSOES.md`, mova para `docs/` o que agora existe (com a linha em `.continue/README.md` §2, sem apagar item da fila cujo produto ainda não existe), atualize o quadro (`fatia/`, `items/F0-01`, `gates/`, `sessoes/`, `meta/producao`), CHANGELOG, versão, push e `./tools/release.sh`. Pronto quando `git status` está limpo e sincronizado e o `gh release list` tem o `Latest` na versão do `version.md`.
- [ ] **F0-01 FECHADA** — pare o PG do clone principal (`make pg-down`), faça `git clone` do repositório num diretório temporário e, de lá, rode `make lab-up && make demo` do zero; registre em `docs/status/SESSOES.md` o comando, o ambiente, o commit e o resultado. Este item é o fim da rodada.

## Travados no dono — fora da fila de execução

- 🔒 Q-02: fabricante, modelo e versão da borda de rede (bloqueia só o G-UP real; o laboratório segue com adaptador simulado).
- 🔒 Q-03: IP de saída do escritório que a central vai enxergar (só a regra de sessão longa do painel depende dele).
- 🔒 Nome final do produto (`b3mon` segue como slug de trabalho).
- 🔒 Provisionar as 3 sondas externas, o vigilante e o mecanismo de ausência em provedores distintos (dinheiro e contrato).
- 🔒 Bot do Telegram, destinatários do piloto e credenciais FCM e APNs.
- 🔒 Qualquer deploy, mudança de rede ou DNS, e alteração em outros repositórios (módulo Flutter em BLUE3-INTRANET-MOBILE; leitura sys → central em BLUE3-INTRANET).
- 🔒 Runner no servidor cicd para o `make verify` em CI (ADR-002), quando houver o que rodar.
