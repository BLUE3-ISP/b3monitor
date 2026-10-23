# A sonda: coordenador, agenda, spool e envio

> **Status:** `ACTIVE` · O que `agents/probe` faz **hoje** (item B6 da fila). A especificação é o
> [04 do pacote 1.0](../.continue/b3monitor/04_SONDAS_E_EXECUCAO.md) (com as sobreposições de
> [PRECEDENCIA.md](../.continue/b3monitor_2.0/PRECEDENCIA.md): C-05, C-06, C-15). O **executor** nativo (a medição de
> verdade, com a defesa de SSRF e de rebinding) é o B7 ([executor-http.md](executor-http.md)), e o binário e o processo são o B7b (abaixo).

## O ciclo (04 §1)

```
política local ─▶ bundle ─▶ agenda ─▶ planeja o slot ─▶ executa ─▶ resultado final ─▶ spool ─▶ envio ─▶ central
   (arquivo)     (central)  (slots)   (persistido ANTES)  (B7)     (imutável)       (SQLite)    (por item)
```

| Pacote | O que é |
|---|---|
| `internal/politica` | A política **local**: arquivo versionado junto do binário (`politica/lab.json`), lido em modo estrito, com hash SHA-256 que o heartbeat informa. O que ela não cobre, a sonda não mede |
| `internal/bundle` | A configuração que a central envia, lida em modo estrito (campo desconhecido é erro, em todo nível: não há URL de callback nem instrução executável). Confere as fixtures do contrato |
| `internal/agenda` | A aritmética dos slots: `epoch + n × intervalo`, absolutos; o jitter só desloca **quando começa**, nunca qual é o slot |
| `internal/spool` | SQLite em WAL (`synchronous=FULL`): agenda, fila de envio, quarentena, descartes e lacunas, num arquivo e em transações |
| `internal/envio` | O cliente da central e o enviador: resposta **por item**, backoff exponencial com jitter, `Retry-After`, e lote envenenado partido ao meio |
| `internal/coordenador` | O ciclo: reconcilia no início, planeja, executa, grava; e monta o heartbeat |
| `internal/resultado` | O resultado normalizado, no formato do contrato (igual, chave a chave, à fixture `valido-http-ok`) |
| `internal/testes` | A **central falsa** dos testes (não é a central; ver "O que a prova não prova") |

## O que ele garante

- **A central não amplia a política local (INV-09).** Um assignment que a política não cobre (destino, porta, espécie,
  intervalo mínimo) vira um resultado `policy_denied`, **sem conexão alguma** e com o executor nem chamado.
- **O slot é planejado antes de executar**, com o `result_id` já gerado, e o resultado entra na fila **na mesma transação**
  que marca o slot como concluído. Um resultado finalizado é imutável: finalizar de novo o mesmo slot é um erro, inclusive
  depois de a central o ter confirmado (não renasce).
- **Reinício não inventa sucesso.** Um slot que estava `executando` e não se sabe se terminou vira `executor_error` com a
  **identidade original**; um planejado cuja janela ainda está aberta roda com o **mesmo** `result_id`; um planejado que perdeu
  a janela vira `skipped` (`schedule_missed`). Nada que já tem resultado é reexecutado.
- **Slot perdido não vira rajada nem sucesso fingido (04 §3).** Depois de uma parada, só o slot vivo executa; os **3** mais
  recentes que passaram viram `skipped`, e o resto é uma **lacuna registrada** (não se inunda a central com milhares de
  "pulei", e a parada continua visível).
- **Configuração vencida não executa** (`config_expired`). Um bundle de outra sonda não é aplicado. Uma geração nova não
  mistura os resultados da antiga.
- **Nada sai do spool sem confirmação durável.** Só `accepted` e `duplicate` **com `durable: true`** apagam um item, um a um.
  HTTP 200 do lote não apaga o lote; um item que a resposta não cita fica; `retryable` volta depois de uma espera;
  `rejected_terminal` e `conflict` vão para a **quarentena** local (com limite), sem travar a fila.
- **Resposta perdida depois do commit é `duplicate`.** A central grava, a conexão cai, a sonda não sabe, reenvia, e o reenvio
  é `duplicate`: pelo menos uma vez no transporte, **exatamente uma vez no resultado**.
- **Lote envenenado não trava a fila.** O 403 do contrato derruba o lote **inteiro**; a sonda o parte ao meio até isolar o
  item culpado, que vai para a quarentena, e o resto é entregue. `401` não culpa item nenhum.
- **Limite temporal e em bytes, e descarte nunca é silencioso (C-15).** 2 horas e 256 MiB (mais 32 MiB de reserva de
  diagnóstico para a quarentena). Passou, os **mais antigos** saem, e cada descarte é uma linha com a **faixa de slots, a
  quantidade e o motivo**. O dado **corrente** vai primeiro no envio, para não ficar bloqueado atrás de horas de histórico.
- **Desconhecido não vira zero (INV-02).** Sem referência externa de relógio, o resultado diz `unknown` com `offset_ms` nulo; o
  heartbeat traz nulo onde não sabe (idade do spool vazio, última execução, revisão sem bundle) e `unknown` nas capacidades
  não medidas; sem conseguir medir o disco, **não monta** o heartbeat em vez de inventar um número.

## O processo (B7b)

`b3mon-probe run -config probe.json` (`make build-probe`; exemplo em `agents/probe/exemplos/`, unit em `deploy/systemd/b3mon-probe.service`):

1. **Lê a configuração local em modo estrito** (campo desconhecido é erro) e o **token de um arquivo à parte**, que tem de ser `0600` ou
   mais restrito: um token legível por outro usuário é recusado, e a mensagem de erro e o log **nunca** o contêm.
2. **Abre o spool e reconcilia** o que a queda deixou pela metade, **antes** do primeiro passo.
3. **A configuração vem da central; se ela não responde, da última válida em disco.** Só um bundle **válido e desta sonda** é aplicado e
   gravado (atômico, `0600`): um bundle recusado (campo extra, outra sonda, não-JSON, geração zero) **não sobrescreve** o bom, e a recusa
   vai para o log. A atualização é de 60 em 60 s; o heartbeat, de 30 em 30 s.
4. **O laço**: planeja e executa o devido, envia o spool, manda o heartbeat. Erro de rede **nunca é fatal**; só uma falha do próprio spool derruba.
5. **SIGTERM/SIGINT terminam limpo (código 0)**: o sinal só é olhado **entre** os passos, então a medição em andamento é **finalizada** e
   entregue antes de sair, e o spool é fechado. Cancelar no meio deixaria o slot `executando` à toa (o teste com o binário real achou isto: a
   primeira versão saía com erro).

O relógio é **declarado pelo operador** no arquivo (a sonda não mede NTP ainda): sem declaração é `unknown` com offset nulo; declarar
`synchronized` exige `reference_kind` (o laboratório usa `lab_declared`), para um relógio declarado não passar por medido.

`b3mon-probe executor -policy ... -socket ...` serve o executor num socket Unix `0600` (nunca TCP nem UDP).

## O que ele NÃO faz

- **Medir sozinha.** O `Executor` é uma interface; o nativo (dial fixado no IP validado, IPv4 sem fallback, política de destinos,
  defesa de SSRF e de rebinding) está em [executor-http.md](executor-http.md). O coordenador o chama pelo socket Unix ou direto.
- **Backlog com sessão e manifesto (04 §7; 03 §9.3).** Tudo vai como `mode: live`. A sonda já manda o dado corrente primeiro,
  mas **não distingue** replay, **não** tem cursor de replay persistente nem a sessão com faixa e quantidade esperada: é o F0-04.
- **Referência externa de relógio.** O relógio é `unknown` até haver NTP/PTP medido. Com ele `unknown`, a central nega o voto
  temporal (`clock_untrusted`): o laboratório precisa declarar um relógio, e o declara como o que é (B8).
- **Concorrência por destino e por caminho.** Os slots rodam em sequência, o que evita a sobreposição da mesma verificação
  mas não é o modelo final.
- **Espécies além de HTTP** (F0-03) e **IPv6** (F0-06): o bundle recusa `tcp`, `icmp`, `dns` e `tls` com o motivo.

## Como se prova

`make test-agents` fails when Go package discovery fails and preserves the
diagnostic. A successful empty package list retains the explicit skip message.
Run `python3 -m unittest discover -s tests -v` to check this distinction and the
test/verification control flow with local command doubles. These regression
checks do not start PostgreSQL or validate the central or pilot.

`go test ./...` em `agents/probe` (139 testes, e `-race` limpo). Os que mais pesam: **um processo de verdade morto com SIGKILL**
no meio do trabalho, duas vezes (o spool, com um slot `executando`; o coordenador, no meio da execução do slot 1), e o
reinício conferindo que nada se perdeu, nada duplicou e nenhum sucesso foi fabricado; o **disco cheio** (`PRAGMA
max_page_count`: `Finalizar` falha limpo, o slot continua incompleto e reconciliável, e funciona de novo com espaço); e o
cenário **central fora → spool → reenvio**, com a resposta perdida depois do commit, terminando com 10 resultados na central e
nenhum no spool. O resultado e o bundle são conferidos contra as **fixtures do contrato** (`contracts/fixtures`), as mesmas
que a central lê.

**A suíte foi submetida a mutação:** 56 estragos que de fato mudam o comportamento (janela de início, jitter, tolerância,
curinga de host, porta e CIDR ignorados, campo desconhecido aceito, finalizado reescrito, duplicate que não sai da fila,
status desconhecido que apaga, descarte sem registro, `accepted` sem `durable`, backoff sem teto ou sem jitter, `Retry-After`
ignorado, 403 que não isola, gzip quebrado, reinício que fabrica sucesso, identidade nova a cada planejamento, perdidos em
rajada…). **Nove escaparam na primeira rodada**: sete viraram teste ou correção, e **dois seguem vivos e são equivalentes**: a
checagem de espécie em `Autoriza` (o ramo seguinte nega do mesmo jeito) e a de `Devido` no laço do coordenador (o intervalo
`[primeiro vivo, atual]` só tem slots devidos). Os dois ficam como defesa em profundidade. Um dos que escaparam era defeito do
**teste**: o gzip quebrado passava porque a partição do lote para um item por vez fugia do corpo comprimido, e o contador de
lotes comprimidos da central falsa passou a pegá-lo; outro, um gerador de id de teste que repetia o mesmo valor depois do
reinício e escondia a perda da identidade persistida.

**O processo (B7b)** foi provado com o **binário de verdade**, compilado e rodando com o relógio real: sobe, busca o bundle numa central falsa, mede
um alvo local, entrega os resultados e o heartbeat, e termina com **código 0** em SIGTERM, com o spool reabrível e sem slot incompleto, o token fora do
log e o bundle em disco com `0600`. **Mutação à mão do processo: 31 estragos, 30 pegos e 1 equivalente** (`CreateTemp` já cria o arquivo com `0600`).
Um detalhe da própria mutação: o cache de resultados do `go test` devolvia `ok` para um binário que a mutação tinha quebrado, e os scripts passaram a usar
`-count=1` e a conferir que o estrago foi de fato aplicado (um padrão com várias linhas casava no `grep` e não no `perl`, e o mutante não existia).

## O que a prova não prova

A **central falsa** modela o contrato (idempotência por `result_id` com conteúdo, resposta por item, `accepted` só depois do
"commit", queda depois do commit, 403 no lote inteiro): os testes provam que a sonda reage ao **contrato**, não que a integração
funciona. Essa prova é o laboratório com a central de verdade (B8) e o aceite (B10). Nenhum teste rodou com relógio de NTP real,
com disco de verdade enchendo, nem com mais de um assignment em carga.
