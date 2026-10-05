# deploy/systemd

> **Status:** `ACTIVE` · As units da central. Nada aqui foi instalado em servidor nenhum: o deploy
> da central é decisão do dono (`make deploy-central` é recusado de propósito).

| Arquivo | O que é |
|---|---|
| `b3mon-probe.service` | **A sonda** (B7b): o coordenador com o executor embutido. Endurecida como o worker (exposição 1.2 OK no `security --offline`), com `StateDirectory` para o spool e a última configuração. A credencial vai num arquivo à parte, `0600` |
| `b3mon-worker@.service` | Unit **modelo** do worker de uma fila. A instância é a fila: `b3mon-worker@ingest`, `@decide`, `@notify` (02 §7 do 2.0). Roda `php artisan b3mon:work <fila>` |

## Quais instâncias habilitar

| Instância | Estado | Por quê |
|---|---|---|
| `b3mon-worker@ingest` | **pode** | Tem handler (B4): transforma o resultado aceito num pedido de avaliação |
| `b3mon-worker@decide` | **pode** | Roda o motor sobre o banco, projeta o estado e decide o incidente (B5) |
| `b3mon-worker@notify` | **pode, e só `dev`** | Entrega a outbox. O único provedor é o `dev` (**simulado**: escreve em `storage/logs/notifications-dev.log`). Um item de telegram, push ou e-mail **falha e para como `dead`**, porque esses canais dependem de credenciais do dono |

## O cron do scheduler

O agendador do Laravel precisa de **uma** entrada de cron, a cada minuto, como o usuário do serviço
(02 §7 do 2.0):

```cron
* * * * * b3mon cd /srv/www/b3mon/central && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Hoje ele roda `b3mon:partitions` (de hora em hora), que cria as partições diárias de `results` que faltam — **sem ele a
ingestão passa a responder `retryable` quando o último dia da janela acaba** —, e `b3mon:work-reconcile` (a cada minuto), que devolve à fila o item com a lease vencida
em **todas** as filas, inclusive nas que não têm worker vivo. O worker também reconcilia a própria
fila a cada 15 s: o cron cobre a fila sem worker, e o worker cobre o cron parado. Particionamento,
expurgo e agregados entram no mesmo `schedule:run` quando existirem.

## Como o worker morre sem perder nada

O processamento e a conclusão do item acontecem **na mesma transação**, sob o lock da linha
(`FOR UPDATE`) e com a lease conferida. Se o processo é morto, o PostgreSQL desfaz a transação: o
efeito some, o item continua `processing` com o dono morto, e quando a lease vence (`B3MON_WORK_LEASE_S`,
60 s) o reconciliador o devolve a `pending`. Um item que gasta as tentativas (`B3MON_WORK_MAX_ATTEMPTS`,
5), por falha **ou** por derrubar o worker, para como `dead` com o motivo em `last_error`, visível, em vez
de voltar para sempre. Provado em `central/tests/Concorrencia/` com um processo de verdade e `SIGKILL`.

## Endurecimento

O da C-07 da REVISAO (`ProtectSystem=strict`, `NoNewPrivileges`, `CapabilityBoundingSet=` vazio,
`RestrictAddressFamilies`, `SystemCallFilter=@system-service`). O worker só escreve em `storage/` e
`bootstrap/cache/`. Conferido offline:

```bash
systemd-analyze verify --man=no b3mon-worker@.service
systemd-analyze security --offline=true b3mon-worker@ingest.service   # exposição 1.3 OK
```

⚠️ **Não conferido num servidor:** o usuário `b3mon`, o diretório `/srv/www/b3mon` e o
`postgresql@17-main` são o layout previsto em 02 §7, e nenhum existe aqui. `MemoryDenyWriteExecute=`
ficou **de fora** de propósito: o JIT do OPcache a quebra, e não foi medido se o PHP-CLI do worker o
liga. Quem instalar mede e, se o JIT estiver desligado, acrescenta.
