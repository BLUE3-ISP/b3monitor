# 14 — Glossário e convenções

**Versão:** 2.0 · 28/09/2026 · Planejamento — não implementado
**Resumo:** vocabulário do pacote, enums canônicos, palavras normativas e convenções de escrita e de repositório.
**Leia quando:** antes de qualquer outro documento; sempre que um termo ou valor de enum aparecer sem definição.
**Depende de:** nada. Os demais documentos dependem deste.
**Autoridade:** os enums da seção 3 são a única fonte. Documento que use valor fora daqui está errado, não este.

## 1. Prefixos e identificadores

| Prefixo | Significado | Onde nasce | Exemplo |
|---|---|---|---|
| `RC-nn` | Requisito consolidado de produto | [01 §3] | RC-17 independência do `sys` |
| `INV-nn` | Invariante: regra que nenhuma implementação relaxa | [01 §3] | INV-03 IPv4 e IPv6 sem fallback |
| `DE-nn` | Decisão inicial de engenharia, ajustável por ADR | [12 §4] | DE-05 spool SQLite |
| `DO-*` | Dependência operacional: dado, credencial ou autorização do ambiente real | [12 §5], [13] | DO-NOC acesso somente leitura à borda |
| `D-nn` | Decisão da revisão 1.0 → 2.0 | REVISAO, [12 §4] | D-02 executor nativo |
| `ADR-nnn` | Registro de decisão de arquitetura no repositório | `docs/adr/` | ADR-001 versões fixadas |
| `G-XXXX` | Gate de qualidade | [09 §7] | G-CORE, G-DATA, G-SEC, G-UP, G-WATCH, G-MOBILE, G-OPS |
| `F0-nn`, `F1-nn` | Fatia do backlog por fase | [09 §3–4] | F0-01 primeira fatia vertical |
| `T-nnn` | Caso de teste | [10] | T-003 três falhas nas três origens |
| `UP-nn` | Ensaio de upstream | [05 §7] | UP-04 desvio para saída de backup |
| `RB-nn` | Runbook | [11 §8] | RB-02 API responde e monitoramento não evolui |
| `P-nn`, `C-nn`, `L-nn`, `Q-nn` | Itens da revisão: empacotamento, correção, lacuna, pergunta | REVISAO | C-01 frescor em slots |

## 2. Termos

| Termo | Definição | Não confundir com |
|---|---|---|
| **Central** | Aplicação Laravel fora da infraestrutura monitorada: configuração, ingestão, motor de estado, incidentes, notificações, API, painel | `sys` (intranet), que é outro produto |
| **`sys`** | Intranet Blue3 (`sys.blue3.cloud` / `adm.blue3.com.br`). Pode consumir a central em leitura (RC-19); a central nunca depende dele | "Sysadm" (nome usado na 1.0) |
| **ShvIA** | Assistente interno da Blue3; nome que aparece no título dos debates de origem | Este produto não é ShvIA |
| **Sonda** | VM com identidade própria que executa medições: coordenador + executores + spool | Vigilante |
| **Coordenador** | Processo Go no namespace do host: política local, agenda, normalização, spool, reenvio, heartbeat | Executor |
| **Executor** | Processo Go que realiza um teste permitido e devolve métricas ou erro tipado; um por namespace de rede quando há caminho a isolar | Blackbox Exporter (descartado em D-02) |
| **Política local** | Manifesto na VM da sonda, mantido pelo administrador local: redes, protocolos, portas, redirects, referências de segredo, limites. A central só recebe revisão/hash | Configuração remota |
| **Configuração remota / bundle** | Conjunto versionado (`bundle_revision`) de assignments enviado pela central; interseção com a política local, nunca ampliação | Política local |
| **Ativo** | Serviço, aplicação, equipamento ou circuito cadastrado | Verificação |
| **Verificação (check)** | Aspecto observável de um ativo: protocolo, destino, família IP, critério, perfil | Assignment |
| **Assignment** | Vínculo versionado verificação × sonda × agenda × caminho opcional; unidade executável, com `config_generation` própria | Responsável de incidente (`owner`) |
| **Perspectiva** | Combinação origem/sonda × família IP × caminho | Sonda |
| **Domínio de observação** | Grupo de sondas que compartilham dependências (provedor, rede, região); dá no máximo um voto | Perspectiva |
| **Slot** | Instante agendado absoluto `schedule_epoch + n × interval`; parte da chave natural do resultado | Horário de início real (`started_at`) |
| **Geração (`config_generation`)** | Revisão imutável de um assignment; resultados ficam ligados à geração em que foram executados | `bundle_revision` |
| **Resultado** | Observação imutável de uma execução | Incidente; ausência de resultado |
| **Elegibilidade** | Condições para um resultado alimentar a saúde corrente [03 §3] | Validade da configuração |
| **Frescor** | Idade máxima de um resultado para representar o presente, expressa em slots [03 §12] | Buffer local; aceitação histórica |
| **Buffer local / spool** | Fila persistente SQLite na sonda; limite temporal e em bytes | Frescor |
| **Aceitação histórica** | Idade máxima que a central aceita para reconstruir histórico | Frescor; retenção |
| **Fluxo corrente** | Resultados recentes enviados assim que produzidos | Backlog |
| **Backlog / replay** | Resultados retidos durante indisponibilidade da central, reenviados por sessão com manifesto | Retentativa de transporte |
| **Controle** | Destino autorizado, independente dos ativos Blue3, medido por cada sonda por IP literal e por nome, para aferir a própria sonda | Alvo |
| **Integridade** | Confiabilidade da medição (relógio, controles, vínculo de caminho); dimensão separada da saúde | Saúde |
| **Quarentena lógica** | Verificação cuja integridade falhou N vezes: continua coletando, não vota nem alerta saúde até N execuções íntegras | Silêncio; manutenção |
| **Perfil de caminho (path profile)** | Desenho + evidência de que uma medição sai por um upstream definido sem fallback, homologado pelo NOC | Configuração de destino |
| **Incidente** | Episódio operacional com identidade estável, classe, gravidade, alcance e atendimento | Resultado; notificação |
| **Outbox** | Intenção de notificação gravada na mesma transação da transição de estado; deduplicada por `(incident_id, decision_version, reason, channel, recipient)` | Tentativa de envio |
| **Ledger de receipts** | Registro global de idempotência de resultados, com retenção própria maior que a aceitação histórica | Índice único de partição |
| **`ingestion_work`** | Trabalho durável pendente em PostgreSQL; consumido com `FOR UPDATE SKIP LOCKED` | Fila em memória |
| **Canário** | Execução sintética autenticada, com nonce, que atravessa ingestão → motor → outbox → despacho técnico | Heartbeat de sonda; heartbeat de job |
| **Vigilante (watchdog)** | Binário Go em domínio de falha distinto que injeta o canário, valida o percurso e só então emite batimento | Central |
| **DMS (dead man's switch) / mecanismo de ausência** | Serviço externo que alerta quando o batimento do vigilante não chega no prazo; provedor, DNS e token diferentes da central | Canal principal da central |
| **Heartbeat de sonda** | Autodiagnóstico periódico da sonda (versão, revisão, spool, relógio, capacidades) | Resultado de alvo |
| **Heartbeat de job** | Evento autenticado de um job externo declarando que rodou | Heartbeat de sonda; canário |
| **Silêncio** | Suspensão de avisos em escopo e prazo definidos; não altera estado técnico | Manutenção; reconhecimento |
| **Manutenção** | Janela administrativa com início, fim, autor, justificativa e escopo; coleta continua | Silêncio |
| **Reconhecer / assumir** | Ações humanas de atendimento (`acknowledge`, `owner`); não alteram medição | Resolução técnica (automática por evidência) |
| **`lab` / `real`** | Ambiente de evidência: laboratório com dados fictícios identificados; ambiente real com inventário | — |

## 3. Enums canônicos

Identificadores em inglês, `snake_case`, estáveis. Textos de interface em pt-BR ficam na camada de apresentação.

| Enum | Valores | Semântica em |
|---|---|---|
| `health` | `up` · `degraded` · `down` · `unknown` | [03 §1] |
| `scope` (abrangência) | `none` · `partial` · `all_observed` · `unknown` | [03 §1] |
| `coverage` | `full` · `reduced` · `insufficient` | [03 §5] |
| `integrity` | `valid` · `suspect` · `invalid` · `unknown` | [03 §3] |
| `stability` | `stable` · `unstable` | [03 §7.1] |
| `pending` | `none` · `pending_failure` · `pending_recovery` | [03 §4] |
| `outcome` | `pass` · `fail` · `executor_error` · `config_error` · `policy_denied` · `capability_unavailable` · `skipped` | [03 §2] |
| `execution_kind` | `scheduled` · `manual` · `canary` | [06 §12], [11 §4] |
| `ip_family` | `ipv4` · `ipv6` | [01 §4] |
| `check_kind` | `http` · `tcp` · `icmp` · `dns` · `tls` · `job_heartbeat` | [04 §5] |
| `ingest_item_status` | `accepted` · `duplicate` · `retryable` · `rejected_terminal` · `conflict` | [06 §6] |
| `batch_mode` | `live` · `replay` | [06 §6] |
| `incident_class` | `service` · `upstream_path` · `monitoring_coverage` · `monitoring_integrity` · `platform` · `job_heartbeat` | [03 §7] |
| `severity` | `info` · `warning` · `critical` | [08 §7] |
| `path_profile_state` | `draft` · `lab_verified` · `noc_validated` · `expired` · `suspended` | [05 §2] |
| `path_binding_status` | `valid` · `not_required` · `missing` · `expired` · `contradicted` | [05 §4] |
| `forwarding_observation` | `expected_egress` · `blocked_by_policy` · `divergent_egress` · `not_observed` | [05 §4] |
| `path_mechanism` | `netns_pbr` · `dedicated_interface` · `router_readback` · `traceroute_hop` · `none` | [05 Anexo A] |
| `clock_status` | `synchronized` · `unsynchronized` · `unknown` | [04 §8] |
| `channel` | `dev` · `telegram` · `push` · `email` | [08 §6] |
| `attempt_status` | `queued` · `sent` · `accepted_by_provider` · `failed` · `cancelled_by_policy` · `suppressed` | [06 §10] |
| `work_state` (backlog) | `not_started` · `in_progress` · `implemented` · `verified_lab` · `verified_real` · `blocked` | [09 §1] |
| `test_result` | `passed` · `failed` · `not_run` · `blocked` | [10 §2] |
| `test_env` | `unit` · `contract` · `lab` · `real` | [10 §1] |
| `role` | `admin` · `operator` · `reader` | [07 §3] |

## 4. Códigos de motivo (`reason_code`)

Estáveis, sem mensagem de exceção. Lista inicial; acrescentar por ADR, nunca renomear.

`http_assertions_passed` · `target_timeout` · `connection_refused` · `unexpected_status` · `assertion_failed` · `tls_handshake_failed` · `tls_certificate_invalid` · `tls_expiring` · `dns_unexpected_answer` · `dns_servfail` · `icmp_no_reply` · `executor_unavailable` · `family_unavailable` · `policy_denied` · `clock_untrusted` · `path_evidence_expired` · `path_mismatch` · `schedule_missed` · `spool_full` · `config_expired`

## 5. Palavras normativas

| Palavra | Uso |
|---|---|
| **DEVE** / **NÃO DEVE** | Obrigação. Desvio exige ADR aprovado ou é defeito |
| **RECOMENDA-SE** | Preferência forte; desvio exige justificativa registrada |
| **PODE** | Permitido; escolha local |
| **Não confundir com** | Bloco ao fim da seção listando os erros de leitura mais prováveis |

Frases sem palavra normativa são explicação, não regra.

## 6. Convenções de escrita

**Cabeçalho padrão de cada documento**

```markdown
# NN — Título
**Versão:** 2.0 · DD/MM/AAAA · Planejamento — não implementado
**Resumo:** duas ou três linhas.
**Leia quando:** situação em que este documento é a resposta.
**Depende de:** [NN §x], …
```

**Citações:** `[03 §5.1]` para documento e seção; `[T-034]`, `[UP-04]`, `[RB-02]`, `[INV-07]` para itens identificados. Nunca "documento 3", "doc 03" ou "o arquivo de testes".

**Dados fictícios:** IPv4 em `203.0.113.0/24`, `198.51.100.0/24`, `192.0.2.0/24` (RFC 5737); IPv6 em `2001:db8::/32` (RFC 3849); nomes em `.example`; UUIDs com padrão repetido (`11111111-1111-4111-8111-…`). Nenhum IP, domínio, ASN, credencial ou nome de equipamento real entra no pacote; esses são DO e ficam no inventário protegido referenciado em [13].

**Tempo:** persistência e contratos em UTC, RFC 3339; apresentação inicial em `America/Sao_Paulo`; durações em milissegundos; intervalos e frescor em segundos ou slots, conforme o perfil.

**Idiomas:** prosa em pt-BR; identificadores, enums, nomes de tabela, campos, rotas e código em inglês.

**Tabelas antes de listas.** Lista com marcadores só quando os itens não têm estrutura comum.

## 7. Convenções do repositório

| Item | Regra | Origem |
|---|---|---|
| Slug | `b3mon` em diretórios (`/var/lib/b3mon-*`), units (`b3mon-*.service`), prefixo de tabelas quando necessário, até o nome final (DO) | REVISAO Q-04 |
| `version.md` | `X.Y.Z`: X estável final (manual), Y mudança estrutural (manual), Z automático a cada nova tela, tabela, layout ou renome de botão | Convenção da intranet `sys` |
| `AGENTS.md` | Criado em F0-00 a partir deste documento e do [00]; convenções de código, testes, commits e o que o agente não faz sem autorização | [00 §1], [09 F0-00] |
| Marca de planejamento | Todo doc de especificação leva "Planejamento — não implementado" até o código correspondente existir; a remoção é um commit próprio | Convenção da intranet |
| ADR | `docs/adr/ADR-nnn-titulo.md`: contexto, decisão, alternativas, consequências, teste de verificação. Uma página | [12 §4] |
| Sessões do agente | `docs/status/SESSOES.md`, append-only, uma seção por sessão com o template do antigo [13 §9] | REVISAO P-07 |
| Evidências | `evidence/<ID>/<AAAA-MM-DD>_<env>.md` + artefatos sanitizados; ex.: `evidence/T-003/2026-10-02_lab.md` | [10 §2] |
| Segredos | Nunca em Git, fixtures, exemplos, capturas, logs ou push. `.env.example` só com placeholders | [07 §6] |
| Alterações de doc já publicado | Patch incremental "localizar/substituir" no mesmo trabalho que muda semântica, fixtures e testes | [09 §6] |
| Bundle | `make docs-bundle` gera `dist/…bundle.md` para colar em chat; não versionado | README |
