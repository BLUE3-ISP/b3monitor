# 02 — Arquitetura e stack

**Versão:** 2.0 (rascunho) · 28/09/2026 · Planejamento — não implementado
**Resumo:** componentes e responsabilidades, stack fixada para a Blue3, organização do repositório, fronteiras de confiabilidade, topologia inicial e comandos entregáveis.
**Leia quando:** for decidir onde um comportamento mora, que tecnologia usar ou como o laboratório sobe.
**Depende de:** [01] (requisitos e invariantes), [14] (enums e convenções). Detalha as decisões D-01…D-08 da revisão; cada uma vira ADR em F0-00.
**Natureza:** arquitetura base é RC; stack e organização de código são DE-01/DE-02, revisadas nesta versão.

> Rascunho: assume as recomendações D-01…D-08 da REVISAO. Itens marcados `[D-nn]` mudam se a decisão mudar.

## 1. Componentes e responsabilidades

| Componente | Responsabilidade | NÃO é sua responsabilidade |
|---|---|---|
| **Central** | Configuração, ingestão durável, motor de estado, incidentes, notificações, auditoria, API `/api/v1`, painel | Medir diretamente na LAN como substituta de sonda; depender do `sys` |
| **Coordenador da sonda** | Política local, agenda, executores, normalização, spool, reenvio, heartbeat | Alterar roteamento ou equipamentos; decidir incidentes |
| **Executor** | Realizar um teste permitido no namespace de rede em que roda e devolver métricas ou erro tipado | Agenda, quórum, incidentes, buffer, resolução de nomes fora do que o coordenador validou |
| **Módulo mobile** | Consulta, push e atendimento pelo app BLUE3 `[D-05]` | Sondagem contínua; qualquer credencial de equipamento |
| **Painel** | Administração, configuração, investigação, dashboard com atualização | Ser uma segunda autoridade de incidentes |
| **Vigilante** | Injetar canário, validar o percurso completo e emitir batimento condicionado | Depender dos workers que verifica; alertar plantão por conta própria |
| **DMS (mecanismo de ausência)** | Alertar quando o batimento do vigilante não chega no prazo | Depender da central, do DNS dela ou apenas do Telegram dela |

**Fluxo principal:** configuração aprovada → bundle para a sonda (interseção com política local) → agenda → executor → spool → ingestão em PostgreSQL → `ingestion_work` → motor → projeções, incidentes e outbox → API, painel, push, Telegram.

**Fluxo independente:** vigilante → canário autenticado → mesmo percurso de ingestão, decisão e despacho técnico → resultado e recibo correlacionados pelo nonce → batimento ao DMS → alerta por canal próprio se o batimento faltar.

**Fluxo de leitura externa:** `sys` → API da central com token de leitura → widgets da intranet (RC-19). Sentido único.

## 2. Stack fixada

Nenhuma versão exata é "a atual" por definição; F0-00 registra em ADR-001 as versões instaladas via `apt` no Debian 13 e as toolchains fixadas. Tags flutuantes e `latest` NÃO DEVEM aparecer em homologação.

| Camada | Decisão | Base |
|---|---|---|
| Sistema | Debian 13 (Trixie), instalação mínima sem ambiente gráfico; XFS ou Btrfs; administração como root, `sudo` só onde um serviço exige | `[D-01]` |
| Central — web | Apache2 + PHP-FPM 8.4 (`mod_proxy_fcgi`); Laravel 13, monólito modular, API versionada em `/api/v1` | `[D-01]` `[D-06]` |
| Central — painel | Blade + Livewire + CoreUI 5; legível em 1366×768; sessão longa quando a origem é IP interno (`INTERNAL_SESSION_IPS`), 2FA de admin sempre | `[D-06]` |
| Central — banco | PostgreSQL 17 do repositório Trixie; `results` particionada por dia; agregados explícitos | `[D-03]` |
| Central — trabalho assíncrono | `ingestion_work`, replay e outbox em PostgreSQL, consumidos com `FOR UPDATE SKIP LOCKED`; workers `php artisan queue:work --queue=…` (driver `database`) como units systemd; scheduler por cron `schedule:run` | `[D-04]` |
| Central — cache e rate limit | Driver `database`/`file` no MVP; Valkey/Redis só por ADR após benchmark (F1-08) | `[D-04]` |
| Sonda — coordenador | Go, toolchain fixada, binário estático, unit systemd endurecida, usuário próprio | `[D-07]` |
| Sonda — executores | Go nativo: `net/http` com `DialContext` para o IP validado e `TLSClientConfig.ServerName`; `crypto/tls`; `golang.org/x/net/icmp`; `github.com/miekg/dns`; `net.Dialer` | `[D-02]` |
| Sonda — isolamento de caminho | Um executor por `NetworkNamespacePath=` (systemd ≥ 242); coordenador no namespace do host; socket Unix entre eles | `[D-02]`, [04 §4], [05 Anexo A] |
| Sonda — spool | SQLite em modo WAL em `/var/lib/b3mon-probe/spool.db`; limite temporal e em bytes | DE-05 |
| Sonda — ICMP | Sockets ICMP não privilegiados (`net.ipv4.ping_group_range`); alternativa `AmbientCapabilities=CAP_NET_RAW` na unit; nunca root | [04 §10] |
| Vigilante | Go, binário único, VM de outro provedor; DMS externo (Healthchecks, Uptime Kuma push ou equivalente) | DE-11, [11 §4] |
| Módulo mobile | `lib/features/monitoramento/` no app BLUE3 (Riverpod 3, GoRouter, Dio, `flutter_secure_storage`); token próprio da central; boot sem o backend da intranet (INV-12) | `[D-05]` |
| Notificações | Push FCM/APNs enviado pela central com credenciais próprias para o mesmo bundle ID (padrão `PushDriverInterface` do `sys`, copiado); Telegram por bot próprio (nutgram) | [08 §6] |
| Laboratório | `make lab-up`: namespaces de rede no host de desenvolvimento, 3 sondas marcadas `lab`, 2 alvos sintéticos; homologação em VMs (Proxmox/Hyper-V) | `[D-01]`, [11 §1] |
| Contratos | OpenAPI 3.1 em `contracts/openapi`, JSON Schema em `contracts/schemas`; gerados/validados contra Go, PHP e Dart | DE-06 |

Executor nativo substitui o Blackbox Exporter porque este re-resolve DNS, prefere IPv6 com fallback para IPv4 por padrão, expõe apenas hash do IP efetivo e exige porta local. O executor implementa a interface `Executor` do coordenador; um adaptador externo PODE ser adicionado depois por ADR, sujeito a [T-052] e [T-057′].

## 3. Modularização da central

Layout Laravel padrão com namespaces por módulo em `app/`: `Identity`, `Inventory`, `Probes`, `Ingestion`, `State`, `Incidents`, `Notifications`, `History`, `Audit`, `Ops`. Sem pacotes Composer por módulo.

O motor de estado (`App\State\Engine`) DEVE ser determinístico: recebe política versionada, observações elegíveis e instante de avaliação; devolve estado e transições. É testado com Pest e fixtures de [10 §10], sem banco, rede ou relógio real. Consultas de rede e envio de notificações NÃO DEVEM entrar nessa classe.

Uma única autoridade decide incidentes. NÃO DEVE existir Alertmanager ou segundo motor abrindo o mesmo evento. As ideias de agrupamento e inibição orientam o produto sem implantar a ferramenta.

**Não confundir com:** módulo do painel (Livewire) não é módulo de domínio; a projeção `current_states` é cache derivado, não fonte.

## 4. Organização do repositório

```text
b3mon/                       # slug de trabalho; nome final é DO
  central/                   # Laravel 13: API, painel, workers, domínio, testes
  agents/probe/              # Go: coordenador, executores, spool, probe-doctor
  agents/watchdog/           # Go: canário + batimento
  contracts/openapi/         # contrato real, validado em CI
  contracts/schemas/         # resultado, bundle, eventos, heartbeat
  deploy/                    # units systemd, vhost Apache, sysctl, scripts de instalação
  lab/                       # netns, alvos sintéticos, fixtures identificadas como lab
  docs/specification/        # este pacote
  docs/adr/  docs/runbooks/  docs/status/
  evidence/                  # somente evidências sanitizadas
  version.md  AGENTS.md  README.md  Makefile
```

O módulo Flutter vive em `BLUE3-INTRANET-MOBILE/mobile/app/lib/features/monitoramento/` e consome `contracts/openapi` desta central (cliente gerado ou cópia versionada do contrato) `[D-08]`.

Estrutura é ponto de partida; adaptar a repositório existente sem migração cosmética. NÃO DEVE haver banco, runtime ou tabela compartilhados com o `sys`.

## 5. Confiabilidade por fronteira

| Fronteira | Garantia | Mecanismo |
|---|---|---|
| Sonda → central | Entrega pelo menos uma vez; confirmação por item só após commit durável | Ledger de receipts + `ingestion_work` na mesma transação; spool só apaga item `accepted`/`duplicate`/`rejected_terminal` |
| Banco → worker | Nenhum resultado aceito fica sem processamento | `ingestion_work` com `SKIP LOCKED`, lease com expiração e reconciliador que reenfileira leases vencidos; sem broker em memória `[D-04]` |
| Estado → notificação | Transição e intenção nascem juntas | Outbox na mesma transação; dedup `(incident_id, decision_version, reason, channel, recipient)`; provedor externo pode entregar mais de uma vez [03 §9.4] |
| Central → módulo mobile | Cache nunca é fonte de verdade | Snapshot com `as_of`, `valid_until`, cobertura; ações com `Idempotency-Key` e confirmação do servidor |
| Central → sonda | Configuração imutável com vigência | `bundle_revision` + `config_generation`, ativação atômica, validade offline; política local prevalece |
| Central → `sys` | Sentido único | Token de leitura escopado; nenhuma chamada da central ao `sys` |

## 6. Topologia inicial

Três sondas externas em provedores/ASNs distintos, uma sonda interna na Blue3 com um namespace por upstream a homologar, uma central em provedor externo e um vigilante em outro provedor. Os números são meta de piloto, não inventário; provedor, ASN, região e dependências entram em [13 §3].

Exposição de rede: apenas `:443` da central é público (API e painel). Sondas iniciam conexões de saída e NÃO DEVEM ter porta administrativa aberta para a central. Executores NÃO DEVEM escutar em porta TCP/UDP: falam com o coordenador por socket Unix. PostgreSQL escuta só em `localhost`/socket.

A central única é ponto único de falha declarado. Estar fora da Blue3 não cria alta disponibilidade; o MVP aceita a limitação com vigilância, backup testado e RB-01. Failover automático é Fase 2.

**Não confundir com:** três sondas num mesmo provedor não são três domínios; namespaces no host de laboratório não são diversidade.

## 7. Processos e units

| Host | Unit / processo | Observação |
|---|---|---|
| Central | `apache2`, `php8.4-fpm`, `postgresql@17-main` | Pacotes Debian |
| Central | `b3mon-worker@ingest.service`, `b3mon-worker@decide.service`, `b3mon-worker@notify.service` | `queue:work` por fila; `Restart=always`; um template de unit |
| Central | cron `* * * * * cd /srv/www/b3mon && php artisan schedule:run` | Partições, expurgo, agregados, revalidação de perfis |
| Sonda | `b3mon-probe.service` | Coordenador no namespace do host |
| Sonda | `b3mon-executor@<ns>.service` | Um por namespace de caminho; `NetworkNamespacePath=/run/netns/<ns>`; `default` para o namespace do host |
| Vigilante | `b3mon-watchdog.timer` + `.service` | Intervalo `WATCHDOG_INTERVAL_S` [11 §4.2] |

Endurecimento das units está em [07 §10]; layout de diretórios e permissões em [11 §2].

## 8. Caminho vertical antes de horizontalizar

A primeira fatia (F0-01) cobre todas as fronteiras com HTTP/IPv4 em laboratório. As mesmas interfaces recebem depois ICMP/TCP/DNS/TLS, IPv6, perspectivas, caminho e vigilância. O módulo mobile NÃO DEVE ser iniciado antes de [G-CORE] e [G-DATA] passarem em laboratório.

Heartbeat de job externo e canário compartilham a ingestão, mas têm tipos, credenciais e políticas próprios; nenhum dos dois é heartbeat de sonda.

## 9. Armazenamento futuro

PostgreSQL 17 é o ponto de partida, não uma promessa de escala infinita. F1-08 mede escrita, leitura, bytes por linha, índices, expurgo e rajadas de replay. TimescaleDB ou outro armazenamento só entra por ADR apoiado em ensaio; metadados e semântica de incidente permanecem independentes dessa escolha.

## 10. Comandos entregáveis

| Comando | Resultado esperado |
|---|---|
| `make lab-up` / `make lab-down` | Namespaces, sondas `lab`, alvos sintéticos e central local com healthchecks |
| `make demo` | Cenário sintético identificado como laboratório: sucesso, falha, ausência, replay |
| `make test` | Unitários e contratos, sem rede de produção |
| `make verify` | `test` + análise estática (`phpstan`, `golangci-lint`, `dart analyze`) + validação de contratos |
| `make acceptance-lab` | Casos de [10] aplicáveis ao laboratório, com evidências em `evidence/` |
| `make probe-doctor` | Diagnóstico da sonda: permissões, famílias IP, ICMP, relógio, spool, destinos permitidos, namespaces |
| `make smoke` | Verificação não destrutiva do ambiente selecionado |
| `make docs-bundle` | Consolidado em `dist/` para colar em chat |
| `make deploy-central ENV=dev|prod` | Deploy por rsync + `composer install --no-dev` + migrações + `queue:restart`, no padrão da intranet; exige autorização e backup prévio [11 §7] |

Esses comandos ainda não existem: são entregáveis, não instruções para executar sobre produção.
