# Incidentes e notificação

> **Status:** `ACTIVE` · O que a central faz **hoje** entre o resultado ingerido e o aviso (item B5 da fila). A
> especificação é o [03 §7 e §9](../.continue/b3monitor/03_ESTADOS_INCIDENTES_E_REPLAY.md) do pacote 1.0 e a
> [14](../.continue/b3monitor_2.0/14_GLOSSARIO_E_CONVENCOES.md) do 2.0; as filas estão em
> [trabalho-assincrono.md](trabalho-assincrono.md).

## O caminho

```
ingestão ─▶ ingestion_work ─▶ [ingest] ─▶ decision_work ─▶ [decide] ─▶ current_states, state_events
                                                              │
                                                              └─▶ incidents, incident_events, notification_outbox
                                                                                                  │
                                                                                          [notify] ┘─▶ provedor do canal
```

O `[decide]` roda numa **única transação**: a projeção do estado, o incidente e a intenção de notificar entram juntos ou
nenhum dos três entra. O `[notify]` roda depois do commit.

## A decisão

Para cada pedido (verificação × família IP) o `Decider`:

1. toma um **lock consultivo** do par, para duas decisões do mesmo check não correrem em paralelo;
2. **descarta a avaliação se ela é mais velha que a projeção atual**: o estado nunca regride. Sem isso, um pedido atrasado
   avaliado "no passado" depois de o incidente ter fechado o reabriria e avisaria uma queda que já acabou;
3. lê as **perspectivas** (um assignment de uma sonda **ativa**: sonda suspensa ou revogada sai do previsto, e o domínio dela
   deixa de votar) e as **observações** agendadas da janela (`B3MON_DECIDE_WINDOW_S`, 1 hora; manual não entra);
4. roda o `Engine` (puro, o mesmo dos testes de motor) com a política `critical-v1` no instante do passo;
5. grava `current_states` e `state_events` (`StateProjector`);
6. decide o incidente (`IncidentManager`).

### `current_states` e `state_events`

`current_states` é **projeção**: a versão só sobe quando algo **material** muda (saúde, abrangência, cobertura, domínios
válidos ou previstos); reavaliar o mesmo estado só atualiza `as_of` e a validade (`última observação elegível + 132 s`).
`state_events` é o histórico, imutável: uma linha por transição de cada perspectiva, **com a data original do slot**, e uma
da saúde agregada. Reavaliar o mesmo conjunto não duplica (índice único).

## O incidente

A decisão olha **só o presente** da avaliação:

| Presente | Sem incidente aberto | Com incidente aberto |
|---|---|---|
| `down` ou `degraded` | **abre** (e avisa, uma vez) | muda gravidade ou abrangência: **atualiza**; avisa só o **agravamento** (`warning` → `critical`) |
| `up` | nada | **fecha**; avisa a recuperação **só se a abertura foi avisada** |
| `unknown` | nada | **nada**: não fecha por falta de evidência (nunca um verde antigo) e não repete aviso |

A confirmação e a recuperação **sustentadas** já vêm do motor: três falhas ou três sucessos **consecutivos** por perspectiva,
com quórum de dois domínios. `down` e `up` só chegam aqui depois delas. Gravidade: `down` é `critical`, `degraded` é `warning`.

- **Chave de correlação:** `service:<check>:<família>`: um incidente de serviço aberto por vez, por índice único parcial.
- **Início observado e confirmação são coisas diferentes** (03 §7): o início é a **primeira** falha do episódio
  (`failure_count − 1` intervalos antes do slot que confirmou); a confirmação é o instante da avaliação. Na recuperação,
  idem: o fim observado é o primeiro sucesso da última perspectiva a se recuperar.
- **`decision_version`** é a `version` do incidente: 1 na abertura, e +1 em cada decisão material (agravamento, recuperação).

## A outbox e o canal `dev`

Cada decisão que avisa grava **uma intenção por destinatário** (`B3MON` `notifications.destinations`: hoje `dev`/`lab`) em
`notification_outbox`, com unicidade `(incident_id, decision_version, reason, channel, recipient)`. Os motivos são `opened`,
`worsened` e `recovered`.

O canal **`dev` é simulado**: o provedor `dev-log` só acrescenta uma linha em `storage/logs/notifications-dev.log`, com o
prefixo `[SIMULADO]` e `"simulated":true` no JSON, sem token, rede ou credencial. No banco, `notification_outbox.simulated` é
uma coluna **gerada** (`channel = 'dev'`): a marca não depende de o código lembrar de escrevê-la.

**A entrega é pelo menos uma vez** (03 §9.4), e isto é dito em vez de prometer "exatamente uma vez". O envio não volta com o
`ROLLBACK`: se o processo morre entre o envio e o commit, o item é entregue de novo com a **mesma chave de idempotência**
(`outbox-<id>`), e o log mostra as duas linhas, identificáveis. O que o produto garante é a **decisão lógica deduplicada** e as
retentativas controladas. `accepted_by_provider` não é "entregue ao dispositivo" nem "lido".

Canal **sem provedor** (telegram, push, e-mail: dependem de credencial do dono) **falha o item**, que tenta de novo e, esgotadas
as tentativas, para como `dead` com o motivo. O aviso não some em silêncio.

## Replay não gera tempestade

Cem amostras antigas, chegando de uma vez depois de uma indisponibilidade, **recompõem o histórico** (as transições entram em
`state_events` com a data em que aconteceram) mas **não disparam aviso**: o presente delas é `unknown` (ninguém mediu nada
recente), e a decisão olha o presente. O mesmo vale para uma queda que começou e terminou inteiramente no passado. Já uma queda
**ainda em curso** que chega em replay abre **um** incidente, com o início no passado, e avisa **uma vez**, por mais itens de
ingestão e decisões que existam, porque a decisão compara a avaliação com o incidente aberto e não com o número de itens.

## O que o B5 NÃO faz

- **Reabertura por recaída** (`reopen_window_s`), **instabilidade** (anti-flap, 03 §7.1) e **integridade persistente** (§7.2):
  após a recuperação, uma nova queda abre um incidente novo. São do F0-04.
- **Reconstruir incidente totalmente passado.** Uma queda que acabou inteira no passado fica só em `state_events`: não vira linha
  em `incidents` (03 §9.3 pede a recomposição; é do F0-04, com a sessão de replay e o manifesto).
- **Avisar perda de cobertura** (03 §9.4: "só há evidência antiga e nenhuma cobertura atual"). Hoje o estado fica `unknown` e
  visível no painel, sem aviso.
- **Canais reais** e **política de silêncio e manutenção** (03 §10).
- **Registrar tentativas que falham** em `notification_attempts`: só a aceita vira linha; a falha fica em `last_error` da
  outbox, porque a transação do passo desfaz o que o handler grava quando lança.
- Perfis de política por check: a política é `critical-v1` fixa (`Policy::critical()`).

## Como se prova

`central/tests/Feature/Decisao/` (a decisão, a entrega e o replay) e um caso em `tests/Concorrencia/` (o lock consultivo
serializa a decisão do mesmo check). A prova que o item pede passa **pelo caminho inteiro**, ingestão → fila `ingest` → fila
`decide` → outbox: **100 amostras antigas** de falha viram 100 itens de ingestão, **um** pedido de decisão e **zero**
notificações, e a mesma queda notificada **uma vez por decisão** (abertura, agravamento e recuperação, versões 1, 2 e 3).

**A suíte foi submetida a mutação:** 36 estragos que de fato mudam o comportamento (sem lock consultivo, avaliação velha que
regride o estado, gravidade invertida, abre ou fecha com `unknown`, recuperação que avisa sempre ou nunca, alívio que avisa,
início observado igual à confirmação, chave de correlação sem família, outbox sem dedup, sonda suspensa que vota, janela de
histórico ignorada, resultado de família errada que conta, tentativa não registrada, chave de idempotência instável, canal
`dev` sem a marca de simulado…). **Cinco escaparam na primeira rodada** e ganharam teste: a versão que não sobe quando só a cobertura muda, a outbox sem dedup (o `IncidentManager` já é idempotente, então o `Notifier` nunca recebia a repetição), a janela de histórico, a família do resultado, e o filtro `execution_kind = 'scheduled'`. Este último parecia equivalente (o banco recusa manual com `scheduled_at`), mas o esquema só amarra agendado e manual: um resultado de **canário** pode trazer horário agendado e entraria na sequência do check. Na segunda rodada os 36 foram pegos.

**O teste de replay achou um defeito real**, e o conserto é a regra do passo 2: avaliar "no passado" depois de a recuperação
reabria o incidente e avisava uma queda que já tinha acabado.
