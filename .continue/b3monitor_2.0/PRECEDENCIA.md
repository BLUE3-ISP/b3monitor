# PRECEDENCIA — o que o 2.0 sobrescreve no 1.0

**Versão:** complemento ao 2.0 · 01/10/2026 · Planejamento — não implementado
**Resumo:** índice de sobreposição entre o pacote 1.0 (`../b3monitor/`) e a revisão 2.0. Diz, documento por documento, o que o 2.0 muda e o que passa a valer. **Não reescreve nenhum documento 1.0.**
**Leia quando:** for abrir qualquer documento 00–13 do 1.0, antes de lê-lo.
**Depende de:** [REVISAO_1.0_PARA_2.0](REVISAO_1.0_PARA_2.0.md) (a fonte de cada linha), [14](14_GLOSSARIO_E_CONVENCOES.md) (enums), ADR-004 em `docs/decisions.md` (a regra).

## 1. A regra

**O 2.0 complementa o 1.0; no que conflitar, vale o 2.0** (decisão do dono, 29/09/2026; ADR-004). O 1.0 continua a base. Esta página existe porque o 2.0 só reescreveu quatro dos dezessete arquivos que a REVISAO planejava (README, 14, rascunho do 02 e a própria REVISAO), então o resto do 2.0 mora em tabelas, e quem lê um documento 1.0 sozinho lê Docker, Blackbox e Redis como se valessem.

Dentro de um mesmo pacote vale a ordem do README 2.0: limites de segurança do 07 › semântica de estados do 03 › prova de caminho do 05 › contratos do 06 › fases do 09. O glossário 14 define os enums: um documento que use valor fora do 14 está errado, não o 14. Esta regra **não** cobre conflito entre o 2.0 e a norma do repositório (repodocs): ali decide a norma, e cada caso é uma premissa registrada (hoje, só o local dos ADRs: `docs/decisions.md`, e não `docs/adr/` como o 14 §7 escreve).

**Ordem de leitura 2.0** (C-18, que substitui a ordem do 00): 14 (vocabulário e enums) → README 2.0 → 01 → 02 → 03 → 06 → 04 → 05 → 07 → 09 → 10 → 13, e 08, 11 e 12 conforme a fatia em andamento. **Antes de cada documento 1.0, a sua seção da §3 abaixo.**

**Como conferir o índice.** Cada D, C e L da REVISAO é uma linha da §4, com o ID na primeira coluna; as demais menções são ponteiros. A conferência é por `grep` na primeira coluna (§6).

## 2. Arquivos substituídos por inteiro

| 1.0 | Passa a valer | Por quê |
|---|---|---|
| `b3monitor/README.md` e `blue3_monitoramento_documentacao_consolidada_v1.0.md` (idênticos byte a byte, 161 KB) | `b3monitor_2.0/README.md`; o consolidado passa a ser gerado por `make docs-bundle` | O README distribuído era o consolidado inteiro (P-01, P-02) |
| `02_ARQUITETURA_E_STACK.md` | `b3monitor_2.0/02_ARQUITETURA_E_STACK.md` | O rascunho do 2.0 já assume D-01…D-08, agora aceitas (ADR-005 a ADR-012) |
| — (não existia) | `b3monitor_2.0/14_GLOSSARIO_E_CONVENCOES.md` | Vocabulário, enums canônicos, palavras normativas (P-03, L-08) |

## 3. Por documento do 1.0

Cada seção diz onde o 1.0 está sobrescrito e aponta as linhas da §4. Documento sem sobreposição diz isso.

### 00 — Prompt para o agente
**§1 e §2** (a direção "Redis como transporte", "Blackbox Exporter avaliado", "Docker Compose", e a frase sobre "demonstração Docker na mesma máquina") estão sobrescritas por D-01, D-02, D-04 e D-05. A ordem de leitura do 00 vale só como ordem do 1.0: a ordem 2.0 está na §1 desta página (C-18). O 00 §3 é a única repetição autorizada das invariantes: seus onze itens, mais o INV-12 novo, ganham ID na §5. Vale o resto: autonomia, segurança, evidência.

### 01 — Produto e escopo
**§3, RC-17** ("independente do Sysadm") é reescrito (C-13). **§3 ganha RC-19** e o INV-12 (§5 abaixo). **Fronteira com o `sys`:** L-01. O resto vale como está.

### 02 — Arquitetura e stack
**Substituído por inteiro** pelo 02 do 2.0 (§2 acima). Pontos do 1.0 que mudam: §2 (Redis, Blackbox, Compose: D-01, D-02, D-04), §4 (diretório `compose/`, e a frase "não compartilhar banco com Sysadm": D-01, C-13, D-08), §5 (Redis "pode perder mensagens": D-04), §6 (implantação em Docker, "não publicar Blackbox, Redis": D-01, D-02, D-04), §9 (`make dev-up` pressupõe Compose: C-21).

### 03 — Estados, incidentes e replay
**§12** (matriz de parâmetros): frescor em slots, não em 90 s fixos (C-01), e retenção bruta maior que a aceitação (C-02). Os enums saem do texto e vão para o 14 §3, que o 03 passa a referenciar. **O 03 é a fonte normativa de saúde, cobertura e integridade e nada mais muda** (§1 a §11 valem).

### 04 — Sondas e execução
**§3 e §4** (agenda, contrato com o executor): executor por namespace (C-09) e executor nativo no lugar do Blackbox (D-02; a frase "ao usar Blackbox Exporter" fica sem efeito). **§6** (spool): dimensionar já (C-15). **§10** (privilégios): sem contêiner privilegiado (D-01, C-07) e ICMP com mecanismo definido (C-08). O resto vale.

### 05 — Upstreams e prova de caminho
**§2** (estados do perfil): `expired` entra (C-03). **Anexo A**, que o 1.0 não tem: o menu de mecanismos (C-20). **Links de MikroTik** do 12 §6 contra "não pressupor MikroTik" do 05 §2: C-10. O resto vale, e o 05 continua precedendo o 06 e o 09.

### 06 — Modelo de dados e contratos
**§2** (`devices`): C-14. **§2** (`ingestion_work`, "perda de Redis"): D-04. **§3 e §9** (identidade, partição, retenção): D-03 e C-02. **§5** (`executor.name: blackbox-adapter`, `path_evidence` sem enum): C-05. **§6** (limite de 4 MiB): C-19. **§7** (`POST /incidents/{id}/assignments` colide com a entidade): C-04. O resto vale.

### 07 — Segurança
O 07 **precede** todos os outros documentos em conflito. **§5** (exceção de loopback "destinada ao Blackbox local") fica sem objeto: D-02. **§6** ("compose versionado, imagens"): D-01. **§10** ("Dependências, containers e execução"): reescrito para systemd (C-07, D-01). **§3**: identidade humana na base própria da central (L-05). O resto vale.

### 08 — App, painel e alertas
**§2** (telas, "Acesso"): módulo dentro do app BLUE3 (D-05, C-23). **§5** (painel web): CoreUI 5, 1366×768, sessão longa (D-06, C-12). **§6** (canal de desenvolvimento): C-17. Padrões a copiar do `sys`: L-06. O resto vale.

### 09 — Backlog e plano de entrega
**F0-01** (§2 e §3): sem Blackbox (D-02) e com o canal `dev` definido (C-17). **F1-05 e F1-06** viram módulo (D-05). **F1-10** é novo (L-07). O resto vale.

### 10 — Testes e critérios de aceite
**§5, T-039** (Redis perde a fila) deixa de existir (D-04). **§6, T-057** vira T-057′ e nasce o T-076 (C-06). **T-075** é novo (INV-12). **§11** (carga): C-22. **§12**: a rastreabilidade ganha coluna INV/RC por teste (P-06). O resto vale.

### 11 — Implantação, operação e runbooks
**§1** (desenvolvimento por Compose; "não compartilhar banco com Sysadm"), **§2** (Redis no inventário; "não afirmar HA por usar Docker"), **§3** (ICMP "sem container privilegiado"; porta do executor) e **§9** (containers `running`): D-01, D-04, C-07. **§6** (backup): C-16. Novos: laboratório numa máquina só (L-02), mecanismo de ausência (L-03) e observabilidade da plataforma (L-04). O resto vale.

### 12 — Decisões, premissas e fontes
**§2** (a linha "Blackbox é executor") e **§4** (DE-01: "Redis e Compose como direção inicial"): D-01, D-02 e D-04. **§6**: links de MikroTik rotulados como exemplo (C-10); os links do Blackbox Exporter e da configuração dele são só referência histórica, sem efeito (D-02). O 12 ganha DE-12 a DE-15, as decisões desta revisão (§6 abaixo). **§4** é onde mora a numeração `DE-nn`, e os ADRs vão para `docs/decisions.md`.

### 13 — Checklist do piloto e status
**§9 e §10** (registro de sessões e de decisões) saem do checklist: sessões em `docs/status/SESSOES.md`, decisões em `docs/decisions.md` (C-11, P-07). **§11** ("independente do Sysadm") diz `sys` (C-13). **§2, §4 e §5**: campos de plataforma da borda dependem da Q-02, que está aberta. O resto, o inventário e o quadro de gates, vale.

## 4. Sobreposições, uma linha por ID

### Decisões da revisão (D)

| ID | Onde no 1.0 | O que o 1.0 diz | O que vale |
|---|---|---|---|
| D-01 | 00 §1; 02 §2 e §6; 07 §6 e §10; 11 §1, §3 e §9 | Docker Compose em desenvolvimento e produção | Debian 13 bare-metal, systemd, Apache2 + PHP-FPM na central; sonda em binário Go com unit endurecida. Sem Compose (ADR-005) |
| D-02 | 00 §1; 02 §2; 04 §4; 07 §5; 09 F0-01; 10 T-057 | Blackbox Exporter local "a avaliar" | Executor nativo em Go (`net/http` com dial pinado, `crypto/tls`, `x/net/icmp`, `miekg/dns`). Blackbox descartado (ADR-006) |
| D-03 | 06 §3 e §9 | PostgreSQL | PostgreSQL 17 do Trixie (ADR-007) |
| D-04 | 00 §1; 02 §2 e §5; 06 §2; 10 T-039; 11 §2 | Redis com reconciliador | Sem Redis no caminho crítico: `ingestion_work` e outbox em PostgreSQL com `SKIP LOCKED`; `queue:work` com driver `database` (ADR-008) |
| D-05 | 00 §1; 08 §2; 09 F1-05 e F1-06 | App Flutter novo, iOS e Android | Módulo `monitoramento` no app BLUE3, com boot e autenticação independentes do backend da intranet (ADR-009) |
| D-06 | 08 §5 | "Server-driven do ecossistema Laravel" | Blade + Livewire + CoreUI 5, legível em 1366×768, sessão longa por IP interno, 2FA de admin sempre (ADR-010) |
| D-07 | 02 §2 | Go na sonda; vigilante "simples" | Go nos dois, com a mesma toolchain fixada (ADR-011, ADR-013) |
| D-08 | 02 §4 | Monorepo com `apps/mobile` | Monorepo próprio, sem diretório mobile; o módulo Flutter vive em `BLUE3-INTRANET-MOBILE` e consome `contracts/openapi` (ADR-012) |

### Correções técnicas (C)

| ID | Onde no 1.0 | O que o 1.0 diz | O que vale |
|---|---|---|---|
| C-01 | 03 §12 | Frescor crítico de 90 s, exatamente 3 slots de 30 s; com jitter a confirmação oscila no limite | Frescor em slots no perfil: `(failure_count + 1) × interval` + tolerância de início + margem de transporte (cerca de 105 a 120 s para intervalo de 30 s) |
| C-02 | 03 §12; 06 §3 e §9 | Retenção bruta de 7 d igual à aceitação histórica de 7 d; o 06 §12 remenda a borda | Bruto ≥ aceitação + 7 d (base 14 d); ledger de receipts ≥ bruto + 7 d (21 d); a relação é validada na configuração |
| C-03 | 05 §2 | Estados do perfil sem `expired` | `draft → lab_verified → noc_validated ⇄ expired → suspended`, com tabela de transições e quem aciona cada uma; enum em 14 §3 |
| C-04 | 06 §7 | `POST /incidents/{id}/assignments` colide com a entidade `assignments` | `PUT /incidents/{id}/owner` |
| C-05 | 06 §5 | `executor.name: blackbox-adapter`; `path_evidence` sem enum de mecanismo | `executor.name: native`, `executor.build` com versão ou commit; `path_evidence.mechanism` em `netns_pbr`, `dedicated_interface`, `router_readback`, `traceroute_hop`, `none` |
| C-06 | 10 §6 (T-057) | Teste presume Blackbox | T-057′: executor sem porta de escuta. T-076: executor num namespace só enxerga as rotas dele |
| C-07 | 07 §10; 04 §10; 11 §1 a §3 e §9 | Texto de contêiner: socket Docker, `privileged`, imagens, "containers `running`" | Endurecimento systemd (`ProtectSystem=strict`, `NoNewPrivileges`, `CapabilityBoundingSet`, `RestrictAddressFamilies`, `SystemCallFilter=@system-service`, `StateDirectory`); saúde por `systemctl is-active` e canário, nunca só o processo vivo |
| C-08 | 04 §10 | ICMP "a testar", sem mecanismo | Sockets ICMP não privilegiados (`net.ipv4.ping_group_range`; conferir com `sysctl`); senão `AmbientCapabilities=CAP_NET_RAW` na unit. Nunca root |
| C-09 | 04 §3 e §4 | Executor por caminho, sem mecanismo concreto | Um processo executor por `NetworkNamespacePath=` (systemd 242 ou mais novo); coordenador no namespace do host; socket Unix entre eles |
| C-10 | 12 §6 e 05 §2 | Links de Policy Routing MikroTik ao lado de "não pressupor MikroTik" | Confirmar a borda (Q-02) ou rotular os links como exemplo |
| C-11 | 13 §9 e §10 | Registro de sessões e de decisões dentro do checklist de inventário | `docs/status/SESSOES.md` (append-only) e `docs/decisions.md` neste repositório |
| C-12 | 08 §5 | Painel sem requisito de viewport nem de sessão | 1366×768 legível sem rolagem horizontal; sessão longa por IP interno (D-06) |
| C-13 | 01 §3 (RC-17); 02 §4; 11 §1; 13 §11 | "Independente do Sysadm" | "Independente do `sys` (intranet Blue3)"; nasce a RC-19 |
| C-14 | 06 §2 | `devices` presume app próprio | O app registra o token push também na central; a central guarda credenciais APNs e FCM próprias para o mesmo bundle ID |
| C-15 | 04 §6 | Spool "2 h, tamanho a medir" | Dimensionar já: 500 execuções por minuto por sonda × 120 min ≈ 60 mil linhas ≈ 60 MB; limite inicial 256 MiB mais reserva de diagnóstico de 32 MiB |
| C-16 | 11 §6 | Backup genérico | `pg_dump` diário + WAL contínuo (pgBackRest, RPO de até 15 min) para outro provedor; Bacula opcional como segundo destino; `APP_KEY` e chaves de cifra fora do banco e do mesmo backup |
| C-17 | 09 F0-01; 08 §6 | "Canal de desenvolvimento" indefinido | Canal `dev` = `notification_attempts` com `provider=dev-log` mais `storage/logs/notifications-dev.log`, sem token real |
| C-18 | 00 | Ordem de leitura antiga; sem `AGENTS.md` nem `version.md` | Ordem 2.0 (§1); F0-00 cria `AGENTS.md` e `version.md` conforme o 14 §7. Neste repositório ambos já existem |
| C-19 | 06 §6 | Só o limite de 4 MiB | Aceitar `Content-Encoding: gzip` com limite de expansão de 16 MiB e de 500 itens |
| C-20 | 05 | Sem menu de mecanismos; o NOC decide do zero | Anexo A: M1 netns + PBR por origem na borda; M2 interface ou VLAN dedicada por upstream; M3 releitura somente-leitura de regra ou rota (revalidação, não prova); M4 primeiro salto de traceroute (complementar), com a tabela do que cada um prova e não prova |
| C-21 | 02 §9 | `make dev-up` presume Compose | `make lab-up` (netns + alvos sintéticos no host) e `make docs-bundle` |
| C-22 | 10 §11 | Só metas de leitura e de alerta | Ingestão com p95 < 1 s por lote de 500; decisão com p95 < 5 s do commit ao snapshot |
| C-23 | 08 §2 | Tela "Acesso" presume app novo | Login na central dentro do módulo; boot sem a intranet (INV-12, T-075) |

### Lacunas (L)

| ID | Onde no 1.0 | O que faltava | O que vale |
|---|---|---|---|
| L-01 | 01 | Fronteira com o `sys` | TLS ou certificado vencendo é monitoramento; registro de domínio vencendo continua no `sys` (`whois:update`). Evita duplicar o lembrete |
| L-02 | 02 e 11 | Laboratório numa máquina só | `make lab-up` cria 3 namespaces marcados `lab`, 3 sondas e 2 alvos sintéticos; nunca alega diversidade real |
| L-03 | 11 | Mecanismo de ausência (DMS) | Exemplos aceitáveis: Healthchecks, Uptime Kuma push monitor em VPS de outro provedor, ou cron + bot Telegram próprio. Provedor, DNS e token diferentes da central |
| L-04 | 11 | Observabilidade da própria plataforma | `/metrics` da central (formato Prometheus, acesso restrito) e `probe-doctor` como fonte das métricas de 11 §5 |
| L-05 | 07 | Identidade humana | Base de usuários própria da central (RC-17); sem SSO no MVP; OIDC opcional na Fase 2 |
| L-06 | 08 | Reuso de padrões do `sys` | `PushDriverInterface` (FCM e APNs direto) e nutgram para Telegram: copiar o padrão, nunca o serviço |
| L-07 | 09 | Integração `sys` → central | Item F1-10: widget de ping do dashboard e "teste por ping" da intranet passam a consumir a central |
| L-08 | 14 | Glossário e convenções | Glossário, enums canônicos, palavras normativas, cabeçalho padrão e dados fictícios (RFC 5737 e 3849, `.example`) |

### Defeitos de empacotamento (P), já resolvidos pelo 2.0

| ID | Defeito | Resolvido por |
|---|---|---|
| P-01 | O `README.md` distribuído era o consolidado inteiro | README 2.0 (§2) |
| P-02 | O consolidado de 162 KB degrada a leitura do agente | O agente lê os arquivos modulares; o bundle é gerado, não versionado |
| P-03 | Vocabulário sem definição central | Documento 14 |
| P-04 | Três formatos de citação | Forma única `[03 §12]` |
| P-05 | Invariantes repetidas sem ID | `INV-01` a `INV-12` (§5) |
| P-06 | Rastreabilidade grossa | Coluna INV/RC por teste (10 §12) |
| P-07 | Log de sessões dentro do checklist | `docs/status/SESSOES.md` |
| P-08 | Frases "Não …" sem marcador normativo | DEVE, NÃO DEVE, PODE (14 §5) |
| P-09 | Sem "quando ler" no cabeçalho | Cabeçalho padrão (14 §6) |

## 5. Invariantes, com ID

O 2.0 numera as invariantes `INV-01` a `INV-12` em 01 §3, **mas esse arquivo não foi escrito**, e o 14 só cita o INV-03 (IPv4 e IPv6 sem fallback). A numeração abaixo segue a **ordem do 00 §3**, que é a única repetição autorizada no 1.0, e bate com o INV-03 do 14. É uma derivação deste índice, **não** uma numeração oficial: se o 01 do 2.0 vier a ser escrito, ele manda.

| ID | Invariante | Origem |
|---|---|---|
| INV-01 | Serviço, cobertura e integridade da medição são dimensões distintas | 00 §3, 1º item |
| INV-02 | Sem amostra, amostra inválida, relógio inadequado ou dado antigo não significam saúde nem queda do alvo | 2º |
| INV-03 | IPv4 e IPv6 são verificações independentes, sem fallback entre famílias | 3º |
| INV-04 | Sonda perdida não derruba automaticamente os ativos; controles falhos não apagam evidências reais | 4º |
| INV-05 | Upstream não fica saudável pelo sucesso do backup, pelo IP devolvido por um eco ou por contador de regra de marcação | 5º |
| INV-06 | Reconhecimento, atribuição de responsável, silêncio e manutenção não resolvem falhas | 6º |
| INV-07 | Agenda e buffer ficam na sonda; entrega pelo menos uma vez; persistência e processamento idempotentes | 7º |
| INV-08 | Replay recompõe histórico, mas só evidência recente e elegível determina o estado atual; notificações são reconciliadas | 8º |
| INV-09 | A política local de segurança não pode ser ampliada unilateralmente pela central | 9º |
| INV-10 | O vigilante usa canário de processamento e batimento condicionado em mecanismo independente | 10º |
| INV-11 | Estado antigo saudável nunca é apresentado como atual quando a medição perdeu confiabilidade | 11º |
| INV-12 | O módulo mobile abre, autentica e exibe incidentes com o backend da intranet fora do ar (D-05; T-075) | **novo no 2.0** |

## 6. Conferência

```bash
cd .continue/b3monitor_2.0

# cada D, C e L é exatamente uma linha de tabela, com o ID na 1ª coluna (esperado: 39 linhas "1")
for id in D-0{1..8} C-{01..23} L-0{1..8}; do printf '%s %s\n' "$id" "$(grep -c "^| $id |" PRECEDENCIA.md)"; done | awk '$2 != 1 {bad=1; print "FALHA:", $0} END {if (!bad) print "ok: 39 IDs, uma linha cada"}'

# todo documento 00 a 13 tem a sua seção na §3 (esperado: 14)
grep -c '^### [01][0-9] — ' PRECEDENCIA.md

# onde o 1.0 ainda fala de Blackbox, Redis ou Docker: cada documento listado precisa ter a sua seção acima citando essa passagem
grep -il 'blackbox\|redis\|docker' ../b3monitor/[01][0-9]_*.md
```

Resultado esperado do último comando: `00`, `02`, `04`, `06`, `07`, `10`, `11` e `12` (e o `01` e o `13` só pelo "Sysadm", que não casa). Em cada um, as passagens estão nas seções que a §3 marca como sobrescritas. O `12` também cita Blackbox e Redis em **referências técnicas herdadas** (§6) que valem como consulta histórica e não são regra.

## 7. O que o 2.0 acrescenta e o 1.0 não tem

| Item | O que é | Onde nasce |
|---|---|---|
| RC-19 | O `sys` PODE consumir a API da central em leitura; a central NUNCA depende do `sys` | REVISAO §3 |
| INV-12 | O módulo mobile abre, autentica e exibe incidentes com a intranet fora do ar | REVISAO §3 |
| T-075 | Teste do INV-12 | REVISAO §3 |
| T-057′ e T-076 | Executor sem porta de escuta; executor em namespace só enxerga as rotas dele | C-06 |
| F1-10 | Integração de leitura `sys` → central | L-07 |
| DE-12 a DE-15 | Decisões desta revisão, no 12 §4 | REVISAO §6 |
| Q-02, Q-03, Q-04 | Perguntas que destravam; Q-04 (slug `b3mon`) foi assumida, Q-02 e Q-03 estão abertas | REVISAO §8 |
