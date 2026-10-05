# Motor de estado

> **Status:** `ACTIVE` · O que o motor de `central/app/State/` faz **hoje** (item B1 da fila). A
> especificação completa é o [03 do pacote 1.0](../.continue/b3monitor/03_ESTADOS_INCIDENTES_E_REPLAY.md),
> que continua na fila porque parte dela ainda não existe: ver "O que não está aqui".

## O que é

Uma **função pura**: `Engine::evaluate(Policy, perspectivas previstas, observações, instante)` devolve uma
`Evaluation`. Não lê o relógio, não toca banco nem rede e **nunca envia notificação**. O instante vem de fora,
e por isso a mesma entrada dá sempre a mesma saída, em qualquer ordem de chegada das observações. Um teste
garante a pureza lendo o código-fonte: se alguém introduzir `DB::`, `now(` ou `new DateTimeImmutable()`, ele
quebra.

## O que ele decide

| Dimensão | Valores | Regra |
|---|---|---|
| Saúde de uma perspectiva | `up`, `down`, `unknown` | `failure_count` falhas válidas em slots **consecutivos** confirmam `down`; `recovery_count` sucessos confirmam `up`. Lacuna, amostra inelegível ou troca de geração zeram a sequência (03 §4) |
| Confirmação em curso | `none`, `pending_failure`, `pending_recovery` | Antes da confirmação, mantém o último estado confirmado **se ainda fresco** e mostra a pendência: nunca um verde sozinho |
| Saúde agregada | `up`, `degraded`, `down`, `unknown` | Por **domínio** de observação, com a tabela 5.1 do 03 |
| Abrangência | `none`, `partial`, `all_observed`, `unknown` | `all_observed` não significa toda a internet |
| Cobertura | `full`, `reduced`, `insufficient` | `insufficient` quando os domínios com voto são menos que o mínimo da política: o resultado é `unknown / unknown`, e as evidências individuais continuam visíveis |

**Elegibilidade (03 §3).** Uma amostra só alimenta a saúde corrente se mede o alvo (`pass` ou `fail`), tem relógio
sincronizado, integridade `valid`, é da geração vigente, não está no futuro e não tem conflito no slot. `executor_error`,
`config_error`, `policy_denied` e `capability_unavailable` **nunca são falha do alvo** (INV-02). Uma amostra inelegível
fica guardada com o motivo, interrompe a sequência e **não soma sucesso, falha, recuperação nem flap**.

**O presente expira por tempo.** Uma perspectiva sem evidência recente vira `unknown` mesmo que nenhuma amostra nova chegue:
quem chama o motor de novo com um instante mais tarde vê o verde sumir (T-008). Um estado `down` que perde toda a
observação também vira `unknown`, **nunca `up`**: ausência não é recuperação (T-009). O último estado confirmado fica em
`lastConfirmed` como histórico e nunca é apresentado como o presente.

**Duas sondas do mesmo domínio dão no máximo um voto**, e se elas discordam o domínio não vota e aparece em
`conflictedDomains` (T-011). O mínimo de domínios vem da política e o motor não o reduz sozinho quando sondas somem.

## Decisões que este código tomou

| Decisão | Por quê | Reverte |
|---|---|---|
| **Frescor = `(failure_count + 1) × intervalo + tolerância + margem`** (132 s no perfil crítico) | C-01 do 2.0. A REVISAO diz que isso dá "cerca de 105 a 120 s", e **os dois não batem**: o "105 a 120" é `failure_count × intervalo + tolerância + margem`. Foi usada a fórmula, que é a parte normativa. Fica como campo da `Policy` | Trocar o `+ 1` em `Policy::freshnessS()`, e o teste de C-01 diz quando for a hora |
| **Margem de transporte de 6 s** | O 2.0 manda ter a margem e não diz quanto | Campo da `Policy` |
| **Idade `==` frescor ainda vale** (`>` é que expira) | O limite precisa ser um lado só; um teste fixa qual | Um caractere em `Engine` |
| **`skipped` é ausência, não incapacidade** | Um slot que não rodou não é uma medição que falhou: não força `unknown`, só o frescor decide | `Ineligibility::Skipped` |
| **Duas observações do mesmo slot com conteúdo diferente: nenhuma vale** | Escolher uma por ordem de chegada tornaria o resultado dependente dela | Exigiria um critério de desempate que o 03 não dá |
| **Os motivos de inelegibilidade são internos** | Vão ser persistidos com a observação e, ao chegar ao banco, precisam de ADR que os fixe (o 14 §4 só lista os `reason_code` que a sonda transporta) | — |

## O que não está aqui

O B1 é a confirmação por perspectiva, a elegibilidade, o frescor e o quórum por domínio. Ficam para o **F0-04**:
integridade persistente e quarentena lógica (03 §7.2), anti-flap e estabilidade (§7.1), redundância (§8), controles das
sondas (§6) e replay, ordenação de reconexão e reconciliação (§9). Ficam para o **B5** os incidentes e a decisão de
notificar: o motor só relata as `Transition`. IPv4 e IPv6 são avaliados em **chamadas separadas**, uma por família, e
nunca num semáforo só (INV-03).

## Como se prova

`central/tests/Unit/State/EngineTest.php`: 40 testes, 430 asserções, **sem banco, rede nem relógio real**. Os casos do
10 §10 (T-003, T-005, T-007) vêm de `tests/Fixtures/motor/casos.json` **verbatim**, junto de oito derivados da tabela 5.1 e do 03 §3;
os demais citam o T-xxx que cobrem (T-001 a T-013, T-016, T-017, T-024, T-026). O determinismo é testado com mais de 30
permutações da ordem de chegada. **A suíte foi submetida a mutação à mão:** onze estragos diferentes no motor (frescor
`>` virando `>=`, lacuna que não zera a sequência, geração antiga que entra na conta, incapacidade de medir virando
falha, relógio ruim e integridade inválida que passam, conflito que escolhe a primeira, domínio discordante que ainda
vota, mínimo de domínios ignorado, confirmação com uma falha a menos, e slot futuro que participa) derrubaram, **cada
um, pelo menos um teste**.
