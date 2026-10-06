# Inventário e quadro de gates

> **Status:** `ACTIVE` · A parte **viva** do [13 do pacote 1.0](../../.continue/b3monitor/13_CHECKLIST_DO_PILOTO_E_STATUS.md) (§1, §3, §4, §8 e §11). O 13 original **não é reescrito**
> ([ADR-004](../decisions.md)): este arquivo é o que o agente e o responsável operacional atualizam. Estados de trabalho: os de [09 §1](../../.continue/b3monitor/09_BACKLOG_E_PLANO_DE_ENTREGA.md),
> e **nada passa de `verified_lab`**: nenhum item aqui foi verificado em ambiente real. Atualizado em 06/10/2026, versão 0.1.27.

## 1. Situação

| Item | Estado | Evidência |
|---|---|---|
| Pacote de especificação | Lido; o 2.0 complementa o 1.0 e, no conflito, vale o 2.0 | [PRECEDENCIA.md](../../.continue/b3monitor_2.0/PRECEDENCIA.md), [decisions.md](../decisions.md) |
| Repositório e branch | `BLUE3-ISP/b3monitor`, `master`; versão em `version.md` | `git log`, [CHANGELOG](../../CHANGELOG.md) |
| Central (`central/`) | Implementada até a F0-01: ingestão, workers, motor, incidente, outbox, bundle, heartbeat, painel | Suíte Pest (402) no banco de teste |
| Sonda (`agents/probe`) | Implementada até a F0-01: política local, agenda, spool, envio, executor HTTP, binário | `go test -race` (139) |
| Testes de aplicativo | **Rodam no laboratório**: `make test`, `make demo`, `make acceptance-lab` | [evidence/f0-01/ACEITE.md](../../evidence/f0-01/ACEITE.md), [aceite.md](../aceite.md) |
| `make verify` | **Falha de propósito** até o A8b: a validação de payload contra os schemas depende do ADR-014 (do dono) | [decisions.md](../decisions.md) |
| Infraestrutura real | **Não inventariada nem provisionada** | — |
| Upstreams reais | **Não homologados** (G-UP `blocked`, Q-02) | — |
| Mobile em dispositivos reais | **Não existe** nesta rodada (outro repositório, D-05/D-08) | — |
| Liberação operacional | **Não autorizada nem realizada** | Depende dos gates |

## 3. Inventário de sondas

| Posição | Papel | Onde | IPv4/IPv6 comprovados | Domínio | Estado |
|---|---|---|---|---|---|
| `lab-a` | **Simulação de laboratório** | Mesma máquina de desenvolvimento, `127.0.0.1` | Só IPv4 em loopback; **IPv6 não comprovado** | `lab-dom-a` (declarado) | Roda no `make lab-up` |
| `lab-b` | **Simulação de laboratório** | A mesma máquina | Idem | `lab-dom-b` (declarado) | Roda no `make lab-up` |
| EXT-A, EXT-B, EXT-C | Externas | — | — | — | **Não provisionadas** (decisão e dinheiro do dono) |
| INT-A | Interna | — | — | — | **Não provisionada** |

As duas sondas do laboratório **não são diversidade de rede**: dois domínios declarados na mesma máquina. O relógio é declarado pelo operador (`lab_declared`), não medido.

## 4. Central e vigilância

| Campo | Valor |
|---|---|
| Local da central | **Só laboratório** (`make lab-up`, `php -S` em `127.0.0.1:18000`). Nenhum deploy: `make deploy-central` recusa de propósito |
| Painel | `/painel`, **sem login** (F0-05), fechado por padrão e só loopback ([painel.md](../painel.md)) |
| Canal de aviso | **`dev`, SIMULADO**. Telegram, FCM e APNs: sem credencial |
| Vigilante e mecanismo de ausência independentes | **Não existem** (F0-07). `agents/watchdog` só tem o `go.mod` |
| Backup, RPO, RTO, restore | **Não definidos nem medidos** (F0-10) |
| FQDN, DNS, TLS, hardware | **PENDENTE** (do dono) |

## 8. Quadro de gates

| Gate | Estado | O que está provado em laboratório | O que falta |
|---|---|---|---|
| G-CORE | `in_progress` | O motor (T-001 a T-013, T-016, T-017, T-024, T-026) e, ao vivo, sucesso, falha, recuperação e ausência (T-001, T-003, T-008) | T-014, T-015, T-018 a T-023, T-025 (F0-04); três origens (o laboratório tem duas) |
| G-DATA | `in_progress` | Idempotência, conflito auditado, resposta perdida depois do commit, atomicidade, workers com lease, outbox transacional, replay sem tempestade, spool da sonda | Replay paginado com sessão e manifesto (T-031, T-033 a T-035), restauração e retenção (F0-10) |
| G-SEC | `in_progress` | Política local que a central não amplia (INV-09), SSRF e rebinding recusados pelo executor, token `0600`, painel fechado por padrão | Autenticação e 2FA (F0-05), T-050 a T-057 por inteiro, revisão de segredos |
| **G-UP** | **`blocked`** | — (um simulador não homologa) | Inventário e acesso do NOC (Q-02) |
| G-WATCH | `not_started` | — | Canário, vigilante e ausência independentes (F0-07) |
| G-MOBILE | `not_started` | — | Módulo no app BLUE3 e dispositivos reais (F1) |
| G-OPS | `not_started` | — | Carga, restore, retenção, responsáveis e rollback |

O bloqueio de G-UP não impede a F0-01 nem o laboratório. **Não se muda nenhum gate para `passed` porque existe um mock.**

## 11. Checklist de liberação

Nenhuma linha está marcada. Das quinze, **só duas têm prova de laboratório** e ainda não valem como liberação: *estados desconhecidos ou comprometidos não exibem verde antigo*
([painel.md](../painel.md)) e, em parte, *replay, dedup, outbox e recuperação de fila comprovados* (G-DATA acima). O resto depende de infraestrutura, pessoas e decisões que não existem.
