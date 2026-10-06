# O aceite da F0-01 no laboratório

> **Status:** `ACTIVE` · `make acceptance-lab` (item B10 da fila). Especificação: [10 §1 e §6](../.continue/b3monitor/10_TESTES_E_CRITERIOS_DE_ACEITE.md) e
> [09 §2 e §6](../.continue/b3monitor/09_BACKLOG_E_PLANO_DE_ENTREGA.md). **É laboratório**: aprovar aqui prova o **caminho** e não leva nenhum gate acima de
> `verified_lab` (09 §1). O resultado da última execução, sanitizado, fica em [evidence/f0-01/ACEITE.md](../evidence/f0-01/ACEITE.md).

## O que roda

`make lab-up && make acceptance-lab` (~4 min). Cada caso cita as **invariantes** (INV, de [PRECEDENCIA §6](../.continue/b3monitor_2.0/PRECEDENCIA.md)), os **requisitos** (RC, de 01 §3) e o
**teste do catálogo** (T, de 10), e diz **qual bloco o prova**:

| Bloco | O que é | Casos |
|---|---|---|
| **A. Ao vivo, na central** | `POST /api/v1/probe/results` de verdade, com o token da sonda `lab-a` e um resultado **manual** (passa pelo mesmo recibo único por `result_id` do agendado, mas não entra na decisão do estado, então não inventa incidente) | **Duplicata** (o mesmo resultado três vezes: `accepted`, `duplicate`, `duplicate` durável; **uma** linha no ledger; incidentes e avisos intactos), **resposta perdida depois do commit** (a primeira resposta é descartada; o reenvio é `duplicate` durável) e **conflito** (mesma chave, conteúdo diferente: `conflict`, original imutável, **auditado**) |
| **B. Ao vivo, o cenário** | `lab/demo.sh` ([lab.md](lab.md)), com o resultado de cada fase lido da saída | **Sucesso**, **falha → incidente**, **recuperação**, **ausência** (sem queda inventada) e **reenvio com a central fora** |
| **C. Suíte** | O teste automatizado do repositório, rodado na hora, para o que não se provoca ao vivo | A reação da **sonda** à resposta perdida (Go), central fora → spool → reenvio (Go), reinício no meio do teste com SIGKILL (Go), a ingestão idempotente, a expiração por tempo, o replay e o painel (Pest). **Zero testes ou teste pulado reprova** (09 §6) |
| **D. Bloqueado** | O que o laboratório não pode provar | IPv6, upstream real, diversidade real de rede, relógio medido, canal real, iOS, segurança de produção e carga. **Escritos como bloqueio, nunca como aceite** |

Sai com código diferente de zero se qualquer caso dos blocos A, B ou C falhar. Os bloqueios não reprovam, e não aprovam: ficam listados.

## Uma honestidade sobre a "resposta perdida"

Ao vivo, a resposta perdida é **simulada pelo cliente**: ele manda o lote e **descarta** a resposta. Para a central é idêntico (ela commitou e não sabe se a sonda soube), e o
que se afirma é do lado da central (o commit aconteceu, o reenvio é `duplicate` durável, uma só linha). A reação da **sonda** (só apagar do spool depois do `duplicate`) é provada
pelo teste Go com a central falsa, e **não** ao vivo. Matar a conexão no meio da resposta de um `php -S` seria uma corrida, não uma prova.

## O que a evidência contém

`evidence/f0-01/ACEITE.md` é **regenerado** a cada execução, com o comando, a versão, o commit (e se a árvore estava suja), o ambiente (sistema, PHP, Go, PostgreSQL) e o resultado
de cada caso. **Sem** home, host, credencial nem log bruto: só o que se pode publicar. As capturas do painel estão no mesmo diretório ([README](../evidence/f0-01/README.md)).

## O que não prova

- **Que o produto funciona em produção.** Duas sondas simuladas na mesma máquina, um alvo de mentira e um canal simulado provam o caminho, não a rede, o upstream nem a operação.
- **T-001 pede três origens**; o laboratório tem duas (o quórum do perfil crítico é de dois domínios). O caso diz isso.
- **A duplicata ao vivo usa um resultado manual**, não um agendado. A idempotência do agendado é provada na suíte (`ProbeResultsTest`), que usa o slot.
