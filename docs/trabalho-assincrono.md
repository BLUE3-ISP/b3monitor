# Trabalho assíncrono da central

> **Status:** `ACTIVE` · O que existe **hoje** (item B4 da fila) entre o resultado aceito e a decisão. A decisão
> de usar tabelas de domínio e não a `jobs` do Laravel está no [ADR-015](decisions.md); a especificação é o
> [02 §5 e §7 do 2.0](../.continue/b3monitor_2.0/02_ARQUITETURA_E_STACK.md).

## As três filas

| Fila | Tabela | Quem a alimenta | Quem a consome | Estado |
|---|---|---|---|---|
| `ingest` | `ingestion_work` | a ingestão, na mesma transação do recibo | `IngestHandler`: confere que o resultado está em `results` e enfileira a reavaliação do check | **existe** |
| `decide` | `decision_work` | o `IngestHandler`, **coalescido**: no máximo um pedido pendente por (check, família IP) | o motor sobre o banco | **B5** |
| `notify` | `notification_outbox` | a transição de estado, na mesma transação | o envio | **B5** |

`b3mon:work decide` e `b3mon:work notify` **recusam subir** (`não implementado`, saída diferente de zero), em vez de
consumir itens sem fazer nada com eles. A coalescência mora no banco (índice único parcial): um pedido já em
processamento **não** impede o próximo, porque o resultado que chegou durante o processamento precisa de outra avaliação.

## O ciclo de vida de um item

```
pending ──claim──▶ processing ──finish──▶ done
   ▲                   │
   │   fail (espera)   │ lease vence
   └───────────────────┤ (reconciliador)
                       ▼
                     dead   (esgotou as tentativas)
```

- **`claim`**: um `UPDATE … WHERE id = (SELECT … FOR UPDATE SKIP LOCKED LIMIT 1)`. Uma instrução só: dois workers nunca
  pegam o mesmo item, e um item que outro está pegando agora é **pulado**, não esperado.
- **O passo do worker** (`Worker::process`) abre uma transação, **tranca a linha com a lease conferida** (`hold`), roda o
  handler e conclui (`finish`). O efeito e a conclusão entram **juntos ou nenhum dos dois entra**.
- **`fail`**: o handler lançou. A transação já voltou (nenhum efeito ficou), e o item volta a `pending` com espera
  `base × 2^(tentativa-1)` limitada por um teto (5 s, 300 s), ou vira `dead` na última tentativa.
- **`reconcile`**: devolve a `pending` o que ficou `processing` com a lease vencida. Idempotente, seguro em vários
  processos ao mesmo tempo. Roda no próprio worker (a cada 15 s) e pelo agendador (`b3mon:work-reconcile`, a cada
  minuto): um cobre o outro parado.

## O que acontece quando o worker morre

| Situação | O que o banco faz | Resultado |
|---|---|---|
| Processo morto (SIGKILL, OOM, queda) no meio do passo | desfaz a transação inteira | efeito some, item continua `processing` do morto; a lease vence, o reconciliador o devolve, outro worker o processa **uma vez** |
| Worker lento, que perdeu a lease | a conferência da lease falha **antes** do handler | passo descartado, handler nem roda, nenhum efeito |
| Worker lento, e outro já concluiu | idem (`status` não é mais `processing`) | idem |
| Item que **derruba o worker toda vez** | a lease vence a cada tentativa; ao esgotá-las o reconciliador o **para** como `dead` | para, visível, com o motivo em `last_error`; o resultado continua no ledger |
| Item que o handler **recusa toda vez** | `fail` esgota as tentativas | `dead`, com a mensagem |

`dead` é um estado final **que alguém precisa olhar**: o item não volta sozinho e não é apagado. Ainda **não há** painel,
métrica nem alerta de `dead`: é o canário do F0-07.

## Configuração

Em `config/b3mon.php`, seção `work`; variáveis `B3MON_WORK_*` (lease 60 s, 5 tentativas, espera 5 s com teto de 300 s,
pausa ociosa de 1 s, reconciliação a cada 15 s). Parâmetros iniciais de homologação, não SLA. A lease tem de ser
**maior** que o pior processamento de um item: o passo conferido pela lease descarta o que passar dela, em vez de
duplicar, mas descartar todo item não adianta nada.

A unit systemd e o cron do `schedule:run`: [deploy/systemd/README.md](../deploy/systemd/README.md). O mesmo `schedule:run`
mantém as partições de `results` (`b3mon:partitions`).

## O que não está aqui

- **O motor sobre o banco** (`decide`) e **o envio** (`notify`): B5. O `decision_work` acumula pedidos coalescidos, no
  máximo um pendente por (check, família), até lá.
- **Expurgo de itens `done` e `dead`**: as três tabelas só crescem. Entra com o expurgo do F0-05.
- **Efeito fora do banco com a mesma atomicidade.** O envio de notificação não cabe na transação; a idempotência dele é a
  chave única `(incident_id, decision_version, reason, channel, recipient)` da outbox (B5).
- **O `available_at` de um item novo é o relógio do banco** (`now()`), não o injetado: só as operações da fila recebem o
  instante de fora.

## Como se prova

`central/tests/Feature/Trabalho/` (a máquina da fila contra as três tabelas, o passo do worker, o `IngestHandler` e os
comandos) e `central/tests/Concorrencia/`, que precisa de **commit de verdade** e por isso usa `DatabaseMigrations`: duas
conexões (`SKIP LOCKED` pula o que a outra segura; o `hold` tranca de fato, e a outra conexão estoura o `lock_timeout`) e
**um processo PHP de verdade morto com SIGKILL no meio do passo**, depois do que o item é reconciliado e processado uma
vez. **A suíte foi submetida a mutação:** 26 estragos que de fato mudam o comportamento (sem `SKIP LOCKED`, `hold` que
não confere o dono ou não tranca, `finish` e `fail` sem conferir o dono, reconciliador que ignora a validade da lease ou
nunca para o item, worker fora da transação, coalescência ou resultado ausente ignorados, handler de `decide` antes da
hora…) derrubaram, **cada um, pelo menos um teste**. Quatro escaparam na primeira rodada (`hold` sem dono, `hold` sem
lock, coalescência, reconciliação do comando) e ganharam teste antes de o item fechar.
