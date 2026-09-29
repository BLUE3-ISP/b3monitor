# 06 — Modelo de dados e contratos

**Versão:** 1.0 · 28/09/2026  
**Natureza:** DE-06; desenho inicial necessário à implementação. Não é descrição de API já existente.

## 1. Convenções

API HTTP em `/api/v1`; JSON UTF-8; timestamps RFC 3339 em UTC; durações em milissegundos; percentuais de 0 a 100 quando nomeados como porcentagem; IDs opacos. O backend determina `received_at` e `processed_at`. Campos sensíveis não entram em snapshots públicos/mobile.

Exemplos abaixo são fictícios: `.example` e endereços de documentação não são destinos produtivos. O agente deve gerar schemas executáveis e OpenAPI real em `contracts/`, testados contra os produtores e consumidores. Os exemplos deste arquivo não substituem validação formal.

Resultados são imutáveis. Projeções de estado e incidentes são derivados versionados. Um atributo não observado usa `null` ou status explícito; nunca valor zero inventado.

## 2. Entidades e relações mínimas

| Entidade | Campos/invariantes principais |
|---|---|
| `users`, `roles`, `sessions` | Papéis, 2FA, revogação; autenticação distinta da sonda |
| `asset_groups`, `locations`, `assets` | Identidade amigável, tipo, criticidade, responsável, etiquetas |
| `checks`, `check_revisions` | Protocolo, destino, família IP, asserções, papel obrigatório/auxiliar, vigência |
| `probes`, `probe_credentials` | Identidade, origem, capacidades, domínio de observação, status e rotação |
| `assignments`, `assignment_generations` | Check + probe + agenda + perfil + caminho; revisão imutável e vigência |
| `state_policies`, `policy_revisions` | Confirmação, frescor, quórum, limites e anti-flap |
| `path_profiles`, `path_validations` | Desenho, revisão, método de evidência, validade, aceite do NOC |
| `results` | Payload normalizado imutável, chave natural, horários e origem |
| `result_receipts` | Ledger de idempotência global com hash, chave natural e retenção explícita |
| `ingestion_work` | Trabalho durável pendente/feito; reconciliação após perda de Redis |
| `replay_sessions`, `replay_parts` | Manifesto, faixas, quantidade, progresso e reconciliação |
| `current_states`, `state_events` | Snapshot atual e transições com política/evidência |
| `incidents`, `incident_events` | Episódio canônico, escopo, gravidade, recuperação, reconhecimento e aliases |
| `notification_outbox`, `notification_attempts` | Intenção transacional, dedup, tentativas e aceite externo |
| `maintenance_windows`, `silences` | Início/fim, autor, razão, escopo; sem término nulo |
| `devices` | Token push, usuário, plataforma e revogação |
| `audit_events` | Autor, ação, recurso, horário, diff sanitizado e correlação |
| `measurement_aggregates` | Durações por estado/cobertura/integridade e contagens; não só média de médias |
| `job_heartbeats` | Identidade do job, sequência, janela e evento; escopo separado da sonda |

O domínio inicial é uma única organização. Não adicionar tenancy comercial, billing ou catálogo SaaS. Preparar autorização por recurso sem construir outro produto.

## 3. Identidade, partição e aceitação durável

Chave natural de resultado agendado: `(probe_id, assignment_id, config_generation, scheduled_at)`. O ID de resultado também deve ser único. A família IP pertence ao assignment; alterar família exige nova geração/identidade compatível com o contrato.

Para garantir idempotência mesmo com `results` particionada por data, usar ledger de receipts com unicidade global e retenção própria. Não pressupor que um índice criado em uma partição deduplica todas as demais. Persistir receipt, resultado e item de trabalho na mesma transação.

`accepted` só é devolvido após commit. Se a conexão cair depois do commit, o reenvio recebe `duplicate`, sem novo incidente. Hash de conteúdo exclui metadados de transporte mutáveis e inclui os campos imutáveis da observação. Mesmo ID com outro conteúdo, ou mesma chave com outro resultado, retorna conflito e gera auditoria.

Resultados mais velhos que a janela de aceitação histórica são rejeitados de forma explícita. O ledger vive além dessa janela, incluindo margem para retries. Após expurgo, a regra de idade continua impedindo que resultados antigos sejam aceitos novamente. Configurações de retenção devem validar essa relação.

Eventos, auditoria e evidências resumidas de incidentes têm retenção independente das amostras. Guardar em cada incidente uma síntese suficiente para explicar a decisão mesmo depois do expurgo bruto.

## 4. Configuração enviada à sonda

```json
{
  "schema_version": "1.0",
  "probe_id": "33333333-3333-4333-8333-333333333333",
  "bundle_revision": 3,
  "issued_at": "2026-09-28T14:00:00Z",
  "expires_at": "2026-09-29T14:00:00Z",
  "assignments": [
    {
      "assignment_id": "44444444-4444-4444-8444-444444444444",
      "config_generation": 3,
      "check_id": "22222222-2222-4222-8222-222222222222",
      "kind": "http",
      "ip_family": "ipv4",
      "target": {
        "url": "https://portal.monitor.example/health",
        "method": "GET",
        "allowed_status_codes": [200],
        "follow_redirects": false
      },
      "schedule": {
        "epoch": "2026-09-28T14:00:00Z",
        "interval_s": 30,
        "jitter_offset_ms": 2000,
        "start_tolerance_ms": 6000,
        "timeout_ms": 5000
      },
      "policy_revision": "critical-v1",
      "path_profile_id": null,
      "credential_ref": null
    }
  ]
}
```

O `bundle_revision` versiona o conjunto entregue à sonda; `config_generation` versiona cada assignment. Alterar um check não deve reiniciar sequências dos assignments inalterados. A sonda calcula o hash da configuração validada e informa o aplicado. A configuração não inclui uma nova política local de redes: ela só seleciona operações já autorizadas. Segredos usam referências a um store local ou entrega cifrada restrita, nunca texto aberto em logs/exports.

## 5. Resultado normalizado

```json
{
  "schema_version": "1.0",
  "result_id": "55555555-5555-4555-8555-555555555555",
  "probe_id": "33333333-3333-4333-8333-333333333333",
  "assignment_id": "44444444-4444-4444-8444-444444444444",
  "config_generation": 3,
  "policy_revision": "critical-v1",
  "execution_kind": "scheduled",
  "scheduled_at": "2026-09-28T15:00:00Z",
  "started_at": "2026-09-28T15:00:02Z",
  "finished_at": "2026-09-28T15:00:02.120Z",
  "duration_ms": 120,
  "ip_family": "ipv4",
  "outcome": "pass",
  "reason_code": "http_assertions_passed",
  "actual_destination": {
    "ip": "203.0.113.10",
    "port": 443,
    "hostname": "portal.monitor.example"
  },
  "metrics": {
    "http_status": 200,
    "total_duration_ms": 120,
    "icmp_sent": null,
    "icmp_received": null
  },
  "clock": {
    "status": "synchronized",
    "offset_ms": 12,
    "reference_kind": "external_ntp"
  },
  "integrity": {
    "probe_assessment": "valid",
    "reason_codes": [],
    "local_policy_revision": "local-v1"
  },
  "path_evidence": {
    "status": "not_required"
  },
  "executor": {
    "name": "blackbox-adapter",
    "build": "lab-fixture"
  }
}
```

`probe_assessment` é relato da sonda; a central faz sua avaliação final de frescor, configuração, controles e autorização. Não confiar cegamente em `valid` recebido. O resultado não contém corpo HTTP completo por padrão, headers secretos nem credenciais. Asserções armazenam esperado/observado sanitizados e limitados.

Para caminho, `path_evidence` inclui perfil/revisão, método, `binding_status`, instante de aferição, validade, `forwarding_observation` e referências de evidência. O método concreto só será ativado após o documento 05. Não aceitar `not_required` em assignment que exige prova de upstream.

Os códigos de erro devem ser estáveis e distintos: `target_timeout`, `unexpected_status`, `assertion_failed`, `dns_unexpected_answer`, `executor_unavailable`, `family_unavailable`, `policy_denied`, `clock_untrusted`, `path_evidence_expired`, `path_mismatch`, `schedule_missed`. Nunca incluir mensagem de exceção com segredo no retorno comum.

## 6. Ingestão em lote

```json
{
  "batch_id": "66666666-6666-4666-8666-666666666666",
  "mode": "live",
  "replay_session_id": null,
  "results": []
}
```

O array vazio acima demonstra o envelope; envio produtivo exige de 1 a 500 resultados, respeitando também limite inicial de 4 MiB por requisição. Esses limites são DE ajustáveis por ensaio, não tamanho ilimitado. Não confiar em tamanho comprimido para prevenir expansão abusiva.

Resposta por item:

```json
{
  "batch_id": "66666666-6666-4666-8666-666666666666",
  "items": [
    {
      "result_id": "55555555-5555-4555-8555-555555555555",
      "status": "accepted",
      "received_at": "2026-09-28T15:00:03Z",
      "durable": true
    }
  ]
}
```

Status de item: `accepted`, `duplicate`, `retryable`, `rejected_terminal`, `conflict`, com `reason_code` quando necessário. Transporte pode responder 400/401/403/413/429/5xx antes de processar itens. O cliente não assume aceitação de itens sem confirmação. `Retry-After` orienta retentativa.

Replay usa manifesto com ID de sessão, probe, faixas por assignment/geração, contagem e hash de partes. Um endpoint de conclusão verifica integridade/completude, não apenas um booleano do cliente. Os detalhes do schema devem ser publicados antes de codificar os dois lados.

## 7. Endpoints a implementar

| Método e caminho | Principal autorizado | Função |
|---|---|---|
| `POST /auth/login`, `/auth/refresh`, `/auth/logout` | Usuário/sessão | Sessões e revogação; 2FA no fluxo correspondente |
| `GET /dashboard` | Usuário | Snapshot agregado com validade |
| `GET /assets`, `GET /assets/{id}` | Usuário | Consulta autorizada e paginada |
| `GET /assets/{id}/measurements` | Usuário | Histórico limitado e agregado |
| `GET /incidents`, `GET /incidents/{id}` | Usuário | Episódios/evidências sanitizadas |
| `POST /incidents/{id}/acknowledgements` | Operador | Reconhecimento idempotente |
| `POST /incidents/{id}/assignments` | Operador | Atribuição de responsável |
| `POST /incidents/{id}/comments` | Operador | Comentário auditado |
| `POST /silences`, `DELETE /silences/{id}` | Operador autorizado | Silêncio com término e justificativa |
| `POST /maintenance-windows` | Operador autorizado | Janela futura/presente com término |
| `POST /checks/{id}/runs` | Operador autorizado | Nova execução de check cadastrado, com limite |
| `POST /devices`, `DELETE /devices/{id}` | Dono do dispositivo | Registrar/remover token push |
| `GET /probe/config` | Sonda | Apenas sua configuração |
| `POST /probe/heartbeat` | Sonda | Capacidade/saúde, não resultado de alvo |
| `POST /probe/results` | Sonda | Ingestão durável de resultados |
| `POST /probe/replay-sessions` | Sonda | Abrir sessão e manifesto |
| `POST /probe/replay-sessions/{id}/complete` | Sonda dona | Validar completude e iniciar reconciliação |
| `POST /jobs/{id}/heartbeat` | Credencial do job | Batimento limitado daquele job |
| `POST /canary/runs`, `GET /canary/runs/{id}` | Vigilante | Percurso sintético e consulta correlacionada |
| CRUD administrativo de ativos/checks/sondas/políticas | Administrador | Configuração versionada e auditoria |

O prefixo `/api/v1` aplica-se à tabela. O painel pode usar endpoints internos próprios, preservando regras de domínio e autorização. Paginação por cursor para histórico e incidentes; filtros allowlisted e limites máximos.

Credencial de sonda não acessa APIs humanas nem altera ativos. Credencial de canário não pode injetar resultados arbitrários de sondas reais. Usuário de leitura não obtém tokens de equipamento.

## 8. Snapshot para mobile

Campos mínimos: ID/nome do ativo; `health`, `scope`, `coverage`, `integrity`, `stability`; `pending_failure`/`pending_recovery`; `as_of`; `last_valid_observation_at`; `valid_until`; contagens de perspectivas/domínios; verificações por família; incidente ativo; motivos; manutenção/silêncio; permissões das ações.

Snapshot foi calculado agora não significa medição feita agora. Exibir ambos. O cliente não substitui estados desconhecidos por `up` nem infere recuperação ao esvaziar uma lista de incidentes por erro de rede.

Toda ação mutável sensível suporta `Idempotency-Key`, vinculada ao usuário, rota e hash do payload. Mesma chave com payload diferente gera conflito. Guardar resposta idempotente ao menos durante a janela de retentativa do cliente. Usar versão/ETag para conflitos de edição; não sobrescrever silenciosamente mudança de outro operador.

## 9. Histórico e retenção

Baseline: amostras 7 dias; agregados, incidentes e auditoria 12 meses; ledger de dedup pelo menos 14 dias. Eventos de incidente guardam evidência resumida além da retenção bruta. Uma medição atrasada deve invalidar/recalcular os agregados das janelas afetadas antes de publicá-los como completos.

Partições temporais preparadas antecipadamente; rotina de expurgo auditável. Monitorar atraso de agregação e falha de criação de partição. Queries de detalhe usam índice por assignment/tempo e paginação; dashboard consulta projeção, não varre toda a tabela bruta.

Agregados devem preservar contagens e durações. Não calcular percentis corretos por média de percentis nem uma média global não ponderada de médias de origens. Na ausência de estrutura adequada, omitir a métrica em vez de apresentá-la com precisão falsa.

## 10. Eventos e outbox

Eventos internos versionados sugeridos: `result.accepted.v1`, `state.changed.v1`, `incident.opened.v1`, `incident.updated.v1`, `incident.recovered.v1`, `notification.requested.v1`. Não exige message broker novo nem event sourcing integral.

A transação que altera incidente grava evento e intenção. Worker de outbox faz claim/lock com expiração, registra tentativa e retenta com backoff. Falha entre envio e registro pode repetir entrega externa; reutilizar chave no provedor quando suportado. `accepted_by_provider` não significa entregue ao dispositivo nem lido por humano.

## 11. Contratos de validação obrigatórios

Validar IDs vinculados à credencial, enum, família/endereço, duração não negativa, limites de strings/corpos, horário tolerado, geração permitida, path evidence exigida e autorização por recurso. Não permitir payload conter URL de callback arbitrária ou instrução executável.

Schemas reais e testes de compatibilidade devem acompanhar mudanças. Alteração incompatível de contrato não é liberada sem estratégia de transição de sondas/mobile; a central deve informar revisão mínima suportada e rejeitar de forma diagnosticável clientes incompatíveis.

## 12. Execuções manuais e heartbeat de job

Execução manual possui `execution_kind=manual`, `manual_request_id` obrigatório e `scheduled_at=null`. Sua chave natural é `(probe_id, assignment_id, config_generation, manual_request_id)`, além do `result_id` estável. A API de reexecução deduplica a solicitação do usuário; o resultado fica fora das sequências agendadas salvo política explícita futura. Não reutilizar a chave de um slot agendado.

Heartbeat de job é evento autenticado com identidade própria, ID/seq estável, instante observado e situação declarada. Registrar também o horário de recebimento da central. Cada job define periodicidade/deadline e tolerância; ausência só sustenta incidente do job quando a própria ingestão tem cobertura confiável para o período. Durante perda da central, mostrar limitação de observação e reconciliar eventos reenviados, sem presumir que o job parou. Batimento recebido prova apenas a declaração configurada; não comprova o sucesso de toda a tarefa de negócio. Não inferir êxito de job por heartbeat de sonda nem pelo canário.

O expurgo de partições deve respeitar o horário de corte da aceitação histórica: só remover partições inteiramente anteriores ao corte. Uma partição diária na borda da janela pode precisar permanecer além de sete dias de calendário para não descartar uma amostra ainda aceitável.
