# Modelo de dados

> **Status:** `ACTIVE` · O que o banco da central (`central/database/migrations/`) garante **hoje** (item B2 da
> fila). A especificação é o [06 do pacote 1.0](../.continue/b3monitor/06_MODELO_DE_DADOS_E_CONTRATOS.md), que
> continua na fila porque a maior parte das entidades ainda não existe: ver "O que não está aqui".

## Princípio

**O que o banco consegue recusar, ele recusa.** Código com defeito não pode gravar um estado impossível, e uma
invariante que mora só na aplicação desaparece no primeiro caminho que esquecer dela. Cada tabela abaixo diz o que
ela impõe, e cada imposição tem um teste que a viola e confere o **SQLSTATE exato** da recusa.

## Tabelas

| Tabela | O que é | O que o banco impõe |
|---|---|---|
| `results` | O payload normalizado, **imutável**, **particionado por dia** de `started_at` | UPDATE e DELETE recusados por gatilho; agendado tem `scheduled_at` e não tem `manual_request_id`, e manual é o inverso (06 §12); duração não negativa; fim depois do início; todo enum do glossário 14 em CHECK |
| `result_receipts` | O **ledger de idempotência global**, sem partição, com `retain_until` próprio | `result_id` único; a chave natural `(sonda, assignment, geração, slot)` única **em todas as partições**; a chave de uma execução manual não colide com a de um slot agendado |
| `ingestion_work` | Trabalho durável, consumido com `FOR UPDATE SKIP LOCKED` (D-04, item B4) | Um item por resultado; exige recibo; `processing` exige dono e validade do lease, e `done` exige a data de processamento |
| `probes`, `probe_credentials`, `assignments` | O mínimo para a ingestão dizer "este lote é desta sonda": a credencial (só o SHA-256 do token), o status da sonda e a geração vigente de cada assignment | Nome de sonda único; token único; sonda `active`, `suspended` ou `revoked`; geração a partir de 1; família IP do glossário |
| `current_states` | **Projeção** do estado por verificação × família IP: saúde, abrangência, cobertura, `as_of`, validade | Cobertura insuficiente ⇔ saúde `unknown` ⇔ abrangência `unknown` (INV-11: nada de "verde" com cobertura insuficiente); domínios válidos ≤ previstos |
| `state_events` | Transições confirmadas, imutáveis | A mesma transição reprocessada não vira dois eventos; estado de origem diferente do de destino |
| `incidents`, `incident_events` | O episódio e a sua linha do tempo | **Um incidente aberto por chave de correlação** (03 §7); detecção depois do início; reconhecimento exige autor; a linha do tempo é imutável e não existe "fechar como resolvido" à mão |
| `notification_outbox` | A intenção de notificar, na mesma transação da transição | Unicidade `(incidente, versão da decisão, motivo, canal, destinatário)`; `simulated` é coluna **gerada**, verdadeira só para o canal `dev`; lease coerente |
| `notification_attempts` | As tentativas de envio, imutáveis | Numeradas por intenção; `accepted_by_provider` é o teto: `delivered` não existe |
| `audit_events` | Quem fez o quê, imutável | Tipo de ator restrito |

## Por que `results` é particionada, e o que isso custa

Partição diária por `started_at`, para o expurgo ser **por partição inteira** (`DROP`), nunca por linha (06 §12).
Em PostgreSQL toda chave única de uma tabela particionada tem de incluir a coluna de partição, e **nada pode ter FK para
ela**. Por isso a idempotência global não mora em `results`: mora no ledger `result_receipts`, que não é particionado e
vive **mais** que o bruto. `ingestion_work` guarda `result_id` e `result_started_at` e acha a linha pela chave completa.

As partições são preparadas com antecedência por `b3mon_ensure_result_partitions(primeiro, último)`, idempotente. Sem
partição para o dia de um resultado o `INSERT` **falha alto**, com `check_violation`: é o sinal que o monitoramento de
"falha de criação de partição" (06 §9) vai ver, em vez de uma linha caindo num balde escondido. Quem cria as partições
no dia a dia é o agendador (item B4).

## Retenção (C-02 do 2.0)

Três janelas, e a relação entre elas é **validada ao subir a aplicação** (`App\Ops\Retention`): a retenção bruta tem de
ser pelo menos a aceitação histórica + 7 dias, e o ledger pelo menos o bruto + 7. A base é 7, 14 e 21 dias, em
`config/b3mon.php`. Uma configuração incoerente derruba o boot: no 1.0 a retenção bruta igual à aceitação histórica fazia
o expurgo poder apagar uma amostra que ainda era aceitável.

## Como se evita a deriva

Os valores de enum dos CHECK vêm de `App\Ops\Glossario`, e um teste os compara com
`contracts/schemas/common.schema.json`: o banco e o contrato não divergem em silêncio. As migrações sobem, descem e sobem
de novo (testado), e podem ser reaplicadas: as funções são `CREATE OR REPLACE`, porque o `migrate:fresh` apaga as
tabelas e **não** as funções.

## O que não está aqui

Nomes e escolhas que diferem da fila: o ledger é `result_receipts` e a auditoria é `audit_events`, como diz o 06 §2
(a fila dizia `ingestion_receipts` e `audit_log`). `probes`, `probe_credentials` e `assignments` existem **no mínimo** que
a ingestão exige (B3); o cadastro completo, com aprovação, rotação e revogação por interface, é do F0-05. **Não existem**
ainda: `checks` (F0-05), `replay_sessions` e `replay_parts` (F0-04), `maintenance_windows` e `silences` (F1-04), `devices`
(F1-06), `measurement_aggregates` (F0-10) e `job_heartbeats` (F1-01). `results` e `result_receipts` guardam `probe_id` e
`assignment_id` **sem chave estrangeira**: em `results` ela não é possível por ser particionada, e no ledger ficou de fora
de propósito, para que a gravação do recibo nunca dependa de uma consulta ao cadastro. As tabelas de usuário, cache e fila
do esqueleto do Laravel continuam como vieram.


## Como se prova

`central/tests/Feature/Banco/` (43 testes, 126 asserções, sobre `results`, ledger, estado, incidentes, notificação e rollback) e `tests/Unit/RetencaoTest.php` e `GlossarioTest.php`, contra o `b3mon_test` do
PostgreSQL de dev. **A suíte foi submetida a mutação no SQL:** dez estragos (o gatilho de imutabilidade de `results` e de
`audit_events` restrito a DELETE, o ledger sem a chave natural, o CHECK de agendado e manual removido, `ingestion_work` sem
unicidade, o estado "verde" com cobertura insuficiente, o incidente aberto deixando de ser único, a outbox sem
deduplicação, o canal simulado virando coluna comum, e o reconhecimento sem autor) derrubaram, **cada um, pelo menos um
teste**, e os de imutabilidade pelos testes de imutabilidade.
