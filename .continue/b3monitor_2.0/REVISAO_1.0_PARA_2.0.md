# Revisão do pacote 1.0 e plano da versão 2.0

**Data:** 28/09/2026
**Escopo:** os 14 documentos (00–13), o README e o consolidado, cruzados com o ambiente Blue3 conhecido: intranet `sys` (Laravel + Blade/CoreUI, MariaDB, deploy por rsync), app BLUE3 mobile (Flutter, já publicado, push FCM/APNs e Telegram funcionando) e operação Debian bare-metal sem Docker.
**Uso:** base para aprovar a 2.0. As decisões **D-01 a D-08** (seção 3) precisam de resposta; o restante é correção e entra de qualquer forma.

## 1. Veredito

O pacote é bom onde importa. A semântica do documento 03 (saúde × abrangência × cobertura × integridade, elegibilidade, três janelas, reconciliação de replay, outbox transacional) é o núcleo do produto e está correta e implementável. A prova de caminho (05) é honesta ao não prometer receita universal. Os gates e a regra "mock não homologa" (09/10) protegem contra falso verde. Nada disso muda na 2.0 além de ajustes finos.

Os problemas concentram-se em três grupos:

1. **Empacotamento.** O README distribuído é o consolidado inteiro; não há glossário; as invariantes se repetem sem ID em sete documentos; o doc 13 mistura inventário estável com log de sessões.
2. **Stack herdada do debate, não da Blue3.** Docker Compose, Blackbox Exporter, Redis obrigatório e um segundo app Flutter foram decididos sem olhar a operação real. Metade da seção de segurança (07 §10) trata riscos de container que não existirão.
3. **Correções técnicas pontuais** (23, seção 4). Uma delas — frescor igual a exatamente 3 slots — afeta a confirmação de queda.

## 2. Defeitos de empacotamento

| # | Defeito | Evidência | Correção 2.0 |
|---|---|---|---|
| P-01 | `README.md` é o consolidado inteiro (161 KB); o README curto só existe como primeira seção dele | `md5sum` idêntico ao `blue3_monitoramento_documentacao_consolidada_v1_0.md` | README próprio (entregue). Consolidado passa a ser gerado sob demanda por `make docs-bundle`, não versionado como fonte |
| P-02 | Consolidado como entrada do agente degrada recall: 162 KB num arquivo só | — | O agente lê os arquivos modulares; o bundle serve só para colar em chat |
| P-03 | Vocabulário sem definição central: RC, DE, DO, ADR, G-*, F0/F1, T-, UP-, RB-, DMS, "Sysadm" | `DMS_GRACE_S` aparece sem expansão; "Sysadm" não é o nome real (`sys`) | `14_GLOSSARIO_E_CONVENCOES.md` (entregue) |
| P-04 | Citações em três formatos: "documento 13", "doc 03", "seção 12 do doc 03" | grep nos 14 arquivos | Forma única `[03 §12]` |
| P-05 | Invariantes repetidas sem ID em 00 §3, 01 §3/§7, 03, 08 §7, 09 §6, 10 §12, 12 §2 | — | `INV-01…INV-12` em 01 §3; o prompt 00 é o único lugar que as repete; os demais citam por ID |
| P-06 | Rastreabilidade grossa: "RC-03/04/05/10 → T-001 a 026" | 10 §12 | Coluna `INV/RC` em cada linha de teste |
| P-07 | 13 §9–10 (log de sessões e decisões) dentro do checklist de inventário | Arquivo "vivo" cresce sem limite | `docs/status/SESSOES.md` (append-only) e `docs/adr/`; o 13 fica inventário + quadro de gates |
| P-08 | Centenas de frases "Não …" sem marcador normativo | — | DEVE / NÃO DEVE / PODE; blocos "Não confundir com" ao fim das seções |
| P-09 | Sem "quando ler este documento" no cabeçalho | — | Cabeçalho padrão: Resumo · Leia quando · Depende de |

## 3. Desalinhamento com o ambiente Blue3 — decisões a fechar

A recomendação já está preenchida; basta confirmar ou trocar. O rascunho do 02 (entregue) assume a coluna "Recomendação 2.0".

| ID | Tema | 1.0 dizia | Recomendação 2.0 | Motivo | Se discordar |
|---|---|---|---|---|---|
| D-01 | Empacotamento e implantação | Docker Compose para dev e produção | Debian 13 bare-metal, systemd, Apache2 + PHP-FPM na central; sonda = binário Go em unit endurecida; Compose sai do pacote | É como tu opera (root, sem GUI, XFS/Btrfs, deploy rsync). Elimina socket Docker, `privileged`, `NET_ADMIN`, tags `latest`; 07 §10 e 11 §1–3 encolhem | Mantém 07 §10 e 11 como estão; ADR-001 registra Compose |
| D-02 | Executor da sonda | Blackbox Exporter local, "a avaliar" | Executor nativo Go (`net/http` com dial pinado ao IP validado + `ServerName`, `crypto/tls`, `x/net/icmp`, `miekg/dns`, `net.Dialer`); Blackbox descartado | Blackbox re-resolve DNS (T-052 vira problema estrutural); `preferred_ip_protocol` default `ip6` com `ip_protocol_fallback: true` viola RC-04 por padrão; expõe `probe_ip_addr_hash`, não o IP efetivo que 06 §5 exige; porta local extra (T-057); não roda um executor por namespace de caminho. O nativo é menos código que o adaptador mais as mitigações | Mantém adaptador + teste de rebinding + T-057 |
| D-03 | Banco da central | PostgreSQL | PostgreSQL 17 (pacote do Trixie) | Particionamento declarativo, JSONB para evidências, DDL transacional (partições criadas em migração), `SKIP LOCKED`; tu já opera PG | MariaDB 11.8 por ADR; reescrever 06 §3/§9. As restrições (chave única inclui coluna de partição; sem FK em particionada) valem para os dois |
| D-04 | Transporte de trabalho | Redis + reconciliador | Sem Redis no caminho crítico: `ingestion_work` e outbox em PG com `FOR UPDATE SKIP LOCKED`; workers `queue:work` (driver `database`) sob systemd; cache `file`/`database` | Um serviço a menos para backup, monitoramento e explicação; T-039 deixa de existir; 41,7 resultados/s em lotes de 500 não justificam broker. Valkey/Redis só por ADR após benchmark (F1-08) | Redis/Valkey: reconciliador e T-039 continuam obrigatórios |
| D-05 | App mobile | App Flutter novo, iOS/Android | Módulo `monitoramento` no app BLUE3 existente (área de funcionários), com **boot e autenticação independentes** do backend da intranet: token próprio da central, cache próprio, sem passar pelo `EnsureClientVersion` do backend mobile | App já está na App Store e a caminho do Play; push FCM/APNs, secure storage, Riverpod/GoRouter/Dio e o padrão de módulos existem. Segundo app dobra assinatura, listagem, revisão e força-update | App separado: 08, F1-05/06 e G-MOBILE ficam como 1.0. Nada mais muda |
| D-06 | Painel web | "server-driven do ecossistema Laravel" | Blade + Livewire + CoreUI 5, legível em 1366×768, sessão longa quando a origem é IP interno (`INTERNAL_SESSION_IPS`, mesmo padrão do `sys`), 2FA de admin sempre | Consistência de componentes com o `sys`; requisitos que tu já impõe aos projetos | Filament/Inertia por ADR |
| D-07 | Linguagem da sonda e do vigilante | Go na sonda; vigilante "simples" | Go nos dois, mesma toolchain fixada | Daemon 24×7, ICMP, namespace de rede, SQLite, binário estático sem runtime na VM. PHP CLI não atende 04/07. Teu aprendizado de Laravel fica onde está a lógica: a central | PHP na sonda é inviável para 04 §3/§9 |
| D-08 | Repositórios | Monorepo com `apps/mobile` | Monorepo próprio na org `blue3-internet`, sem diretório mobile; o módulo Flutter vive em BLUE3-INTRANET-MOBILE e consome `contracts/openapi` da central | RC-17 + D-05 | — |

Consequências que entram na 2.0 independentemente das respostas:

- RC-17 reescrito: "independente do `sys`" em vez de "Sysadm".
- Nova **RC-19**: o `sys` PODE consumir a API da central em leitura. O "sistema de teste por ping" e o widget de ping do dashboard da intranet passam a consumir a central em vez de reimplementar; a central NUNCA depende do `sys`.
- Nova **INV-12** + **T-075**: o módulo mobile abre, autentica e exibe incidentes com o backend da intranet fora do ar. Sem isso, D-05 quebra a razão de existir do produto.

## 4. Correções técnicas pontuais

| # | Doc | Problema | Correção 2.0 |
|---|---|---|---|
| C-01 | 03 §12 | Frescor crítico 90 s = exatamente 3 slots de 30 s. Com jitter (±3 s), tolerância de início (6 s) e transporte, a 3ª amostra chega quando a 1ª expira: a confirmação em 3 slots oscila no limite | Frescor expresso em slots no perfil: `(failure_count + 1) × interval` + tolerância de início + margem de transporte (≈ 105–120 s para intervalo de 30 s) |
| C-02 | 03 §12 / 06 §9 | Retenção bruta (7 d) igual à aceitação histórica (7 d); 06 §12 remenda a borda de partição | Bruto ≥ aceitação + 7 d (baseline 14 d); ledger de receipts ≥ bruto + 7 d (21 d); a relação é validada na configuração |
| C-03 | 05 §2 | Estados do perfil sem `expired` (evidência vencida ≠ `suspended`) | `draft → lab_verified → noc_validated ⇄ expired → suspended`; tabela de transições e quem pode acionar cada uma |
| C-04 | 06 §7 | `POST /incidents/{id}/assignments` colide com a entidade `assignments` (check × sonda) | `PUT /incidents/{id}/owner` |
| C-05 | 06 §5 | `executor.name: blackbox-adapter`; `path_evidence` sem enum de mecanismo | `executor.name: native`, `executor.build` = versão/commit Go; `path_evidence.mechanism` ∈ `netns_pbr`, `dedicated_interface`, `router_readback`, `traceroute_hop`, `none` |
| C-06 | 10 T-057 | Teste presume Blackbox | T-057′: executor nativo sem porta de escuta; T-076: executor em namespace só enxerga as rotas do namespace |
| C-07 | 07 §10, 04 §10, 11 §1–3, 11 §9 | Texto de container (socket Docker, `privileged`, imagens, "containers `running`") | Endurecimento systemd (`ProtectSystem=strict`, `NoNewPrivileges`, `CapabilityBoundingSet`, `RestrictAddressFamilies`, `SystemCallFilter=@system-service`, `StateDirectory`); saúde por `systemctl is-active` + canário, nunca só o processo vivo |
| C-08 | 04 §10 | ICMP "a testar" sem mecanismo | Sockets ICMP não privilegiados (`net.ipv4.ping_group_range`; o Debian atual libera via systemd `50-default.conf` — confirmar com `sysctl`); se não, `AmbientCapabilities=CAP_NET_RAW` na unit. Nunca root |
| C-09 | 04 §3–4 | Executor por caminho sem mecanismo concreto | Um processo executor por `NetworkNamespacePath=` (systemd ≥ 242), coordenador no namespace do host, comunicação por socket Unix. Dá função real à separação coordenador/executor |
| C-10 | 12 §6 vs 05 §2 | Links de Policy Routing MikroTik ao lado de "não pressupor MikroTik" | Confirmar a borda (Q-02) ou rotular os links como exemplo |
| C-11 | 13 §9–10 | Ver P-07 | `docs/status/SESSOES.md`, `docs/adr/ADR-NNN.md` |
| C-12 | 08 §5 | Painel sem requisito de viewport nem de sessão | 1366×768 legível sem rolagem horizontal; sessão longa por IP interno; ver D-06 |
| C-13 | 01 §3 | RC-17 cita "Sysadm" | "`sys` (intranet Blue3)"; RC-19 nova |
| C-14 | 06 §2 | `devices` presume app próprio | Com D-05: o app registra o token push também na central; a central guarda credenciais APNs/FCM próprias para o mesmo bundle ID |
| C-15 | 04 §6 | Spool "2 h, tamanho a medir" | Dimensionar já: 500 exec/min/sonda × 120 min ≈ 60 k linhas ≈ 60 MB; limite inicial 256 MB + reserva de diagnóstico 32 MB |
| C-16 | 11 §6 | Backup genérico | `pg_dump` diário + WAL contínuo (`pgBackRest`, RPO ≤ 15 min) para destino em outro provedor; Bacula opcional como segundo destino; `APP_KEY` e chaves de cifra fora do banco e do mesmo backup |
| C-17 | 09 F0-01, 08 §6 | "Canal de desenvolvimento" indefinido | Canal `dev` = `notification_attempts` com `provider=dev-log` + `storage/logs/notifications-dev.log`; sem token real |
| C-18 | 00 | Prompt com ordem de leitura antiga; sem AGENTS.md/version.md | Ordem 2.0; F0-00 cria `AGENTS.md` e `version.md` conforme [14 §5] |
| C-19 | 06 §6 | Só limite de 4 MiB | Aceitar `Content-Encoding: gzip` com limite de expansão (16 MiB) e de itens (500) |
| C-20 | 05 | Sem menu de mecanismos; o NOC decide do zero | Anexo A no 05 2.0: M1 netns + PBR por origem na borda; M2 interface/VLAN dedicada por upstream; M3 releitura somente-leitura de regra/rota na borda (revalidação, não prova); M4 1º salto de traceroute (complementar). Tabela do que cada um prova e não prova |
| C-21 | 02 §9 | `make dev-up` presume Compose | `make lab-up` (netns + alvos sintéticos no host), `make docs-bundle` |
| C-22 | 10 §11 | Só metas de leitura e alerta | Ingestão p95 < 1 s por lote de 500; decisão p95 < 5 s do commit ao snapshot |
| C-23 | 08 §2 | Tela "Acesso" presume app novo | Com D-05: login na central dentro do módulo; boot sem intranet (INV-12/T-075) |

## 5. Lacunas de conteúdo a acrescentar

| # | Onde | O que falta |
|---|---|---|
| L-01 | 01 | Fronteira com o `sys`: TLS/certificado vencendo é monitoramento; registro de domínio vencendo continua no `sys` (`whois:update`). Evita duplicar o lembrete de renovações |
| L-02 | 02 / 11 | Laboratório numa máquina só: `make lab-up` cria 3 namespaces marcados `lab`, 3 sondas e 2 alvos sintéticos; nunca alega diversidade real |
| L-03 | 11 | Escolha do mecanismo de ausência (DMS): exemplos aceitáveis — Healthchecks (hospedado ou próprio), Uptime Kuma "push monitor" em VPS de outro provedor, ou script cron + bot Telegram próprio. Regra: provedor, DNS e token diferentes da central |
| L-04 | 11 | Observabilidade da própria plataforma: `/metrics` da central (formato Prometheus, acesso restrito) e `probe-doctor` como fonte das métricas do 11 §5 |
| L-05 | 07 | Identidade humana: base de usuários própria da central (RC-17); sem SSO no MVP; OIDC opcional na Fase 2 |
| L-06 | 08 | Reuso de padrões do `sys`: `PushDriverInterface` (FCM + APNs direto) e nutgram para Telegram — copiar o padrão, nunca o serviço |
| L-07 | 09 | Item F1-10: integração de leitura `sys → central` (widget de ping do dashboard e "teste por ping") |
| L-08 | 14 | Glossário, enums canônicos, palavras normativas, cabeçalho padrão, dados fictícios (RFC 5737/3849, `.example`) |

## 6. Estrutura da 2.0

| Arquivo | Ação | Depende de D-xx |
|---|---|---|
| `README.md` | Reescrito — **entregue** | — |
| `00_PROMPT_PARA_O_AGENTE.md` | Ajustado: ordem de leitura, invariantes por ID, AGENTS.md/version.md | D-01, D-02, D-05 |
| `01_PRODUTO_E_ESCOPO.md` | Ajustado: INV-01…12, RC-17 reescrita, RC-19, fronteira com o `sys` | D-05 |
| `02_ARQUITETURA_E_STACK.md` | Reescrito — **entregue como rascunho** sob as recomendações | D-01…D-08 |
| `03_ESTADOS_INCIDENTES_E_REPLAY.md` | Mantido; C-01, C-02, enums movidos para 14 (03 referencia) | — |
| `04_SONDAS_E_EXECUCAO.md` | Reescrito nas seções 4, 6, 9, 10 (executor nativo, netns, systemd, ICMP) | D-01, D-02 |
| `05_UPSTREAMS_E_PROVA_DE_CAMINHO.md` | Mantido; C-03 + Anexo A (menu de mecanismos) | Q-02 |
| `06_MODELO_DE_DADOS_E_CONTRATOS.md` | Ajustado: C-02, C-04, C-05, C-14, C-19; `ingestion_work` sem Redis | D-03, D-04, D-05 |
| `07_SEGURANCA.md` | §10 reescrito para systemd; L-05 | D-01 |
| `08_APP_PAINEL_E_ALERTAS.md` | Reescrito nas seções 2, 5, 8 (módulo, painel CoreUI, sessão) | D-05, D-06 |
| `09_BACKLOG_E_PLANO_DE_ENTREGA.md` | Ajustado: F0-01 sem Blackbox, F1-05/06 como módulo, F1-10 | D-02, D-05 |
| `10_TESTES_E_CRITERIOS_DE_ACEITE.md` | Ajustado: coluna INV/RC, T-057′, T-075, T-076, metas C-22 | D-02, D-05 |
| `11_IMPLANTACAO_OPERACAO_E_RUNBOOKS.md` | Reescrito nas seções 1–3, 6, 7; L-02, L-03, L-04 | D-01, D-04 |
| `12_DECISOES_PREMISSAS_E_FONTES.md` | Ajustado: DE-12…DE-15 (decisões desta revisão), links rotulados | todas |
| `13_CHECKLIST_DO_PILOTO_E_STATUS.md` | Ajustado: sem §9–10; campos de plataforma da borda | — |
| `14_GLOSSARIO_E_CONVENCOES.md` | Novo — **entregue** | — |
| `CHANGELOG.md` | Novo: 1.0 → 2.0 | fim do lote C |
| `docs/status/SESSOES.md` | Novo: template do 13 §9 | — |
| `docs/adr/ADR-000-template.md` | Novo: template do 13 §10 | — |
| `Makefile` alvo `docs-bundle` | Novo: gera o consolidado com demoção de headings | — |

Bundle de referência (substitui o consolidado versionado):

```bash
# make docs-bundle
{ for f in README.md 00_*.md 01_*.md 02_*.md 03_*.md 04_*.md 05_*.md 06_*.md 07_*.md \
           08_*.md 09_*.md 10_*.md 11_*.md 12_*.md 13_*.md 14_*.md; do
    printf '\n---\n\n**Arquivo de origem:** `%s`\n\n' "$f"
    sed 's/^#/##/' "$f"
  done; } > dist/blue3_monitoramento_v2_bundle.md
```

## 7. Plano de geração

| Lote | Documentos | Condição |
|---|---|---|
| Entregue agora | Esta revisão, README 2.0, 14, 02 (rascunho) | — |
| A | 00, 01, 03, 06 | Respostas a D-02, D-03, D-04, D-05 |
| B | 04, 05 (+ Anexo A), 07, 08 | D-01, D-02, D-05, D-06 e Q-02 |
| C | 09, 10, 11, 12, 13, CHANGELOG, templates, Makefile | Lotes A e B fechados |

Se as recomendações forem aceitas sem alteração, os lotes seguem direto. Cada documento sai com cabeçalho "Versão 2.0 · 28/09/2026" e com a marca **Planejamento — não implementado** até haver código.

## 8. Perguntas que destravam

| ID | Pergunta | Por que importa |
|---|---|---|
| Q-01 | D-01 a D-08: confirma ou troca? | Define 9 dos 14 documentos |
| Q-02 | Borda de rede: fabricante, modelo e versão (os links do 12 apontam para MikroTik RouterOS)? | Sem isso o Anexo A do 05 continua genérico |
| Q-03 | IP de saída do escritório que a central verá: `170.233.230.254`? (docs do `sys` registram também `170.233.231.251`) | Regra de sessão longa do painel (D-06) |
| Q-04 | `b3mon` serve como slug de trabalho (units, diretórios, tabelas, prefixo de ADR) até o nome final? | Evita renomear depois em 14 arquivos |

## 9. Changelog resumido 1.0 → 2.0

- README real; consolidado passa a ser gerado.
- Glossário, enums canônicos e palavras normativas centralizados (14).
- Invariantes numeradas (INV) e rastreadas por teste.
- Stack alinhada à operação Blue3: bare-metal/systemd, executor Go nativo, PostgreSQL 17, sem Redis no caminho crítico, painel CoreUI, módulo no app existente.
- Frescor em slots com margem; retenção bruta > aceitação; estado `expired` em perfis de caminho; endpoint de responsável renomeado.
- Menu de mecanismos de prova de caminho; ICMP e isolamento por namespace com mecanismo definido.
- RC-19 (o `sys` consome a central), INV-12/T-075 (módulo funciona sem intranet).
- Log de sessões e ADRs fora do checklist; templates próprios.
