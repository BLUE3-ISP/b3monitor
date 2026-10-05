# Ingestão de resultados

> **Status:** `ACTIVE` · O que `POST /api/v1/probe/results` faz **hoje** (item B3 da fila). O contrato é
> [`contracts/openapi/openapi.yaml`](../contracts/openapi/openapi.yaml) e os schemas de
> [`contracts/schemas/`](../contracts/schemas/); a especificação é o
> [06 do pacote 1.0](../.continue/b3monitor/06_MODELO_DE_DADOS_E_CONTRATOS.md) §3, §6 e §7.

## O que ele promete

**`accepted` só é devolvido depois do commit durável.** Cada resultado é gravado numa transação própria: o recibo no
ledger, a linha em `results` e o item em `ingestion_work` entram **juntos ou não entram**. Se a conexão cair depois do
commit e antes da resposta, a sonda reenvia, e o reenvio é `duplicate`: nenhum segundo trabalho, nenhum incidente novo.
A sonda só apaga do spool o que a resposta confirmou, item por item.

## O que ele responde

Falhas de **transporte** vêm antes de qualquer item ser processado, com um corpo `{error, message, errors?}`:

| Status | Quando | Nada foi gravado |
|---|---|---|
| `400` | Corpo que não é JSON, gzip corrompido, truncado ou com bytes sobrando, `Content-Encoding` desconhecido, ou lote que viola o contrato (a lista de erros diz o **caminho e a regra**, nunca o valor recebido) | sim |
| `401` | Token ausente, desconhecido, credencial revogada ou sonda suspensa: a mesma resposta para todos, para não ensinar quem sonda | sim |
| `403` | A credencial não corresponde ao lote: resultado de **outra sonda**, assignment que não é dela ou geração que ainda não existe. **O lote inteiro cai**, não só o item | sim |
| `413` | Corpo acima de **4 MiB**, ou que expande para mais de **16 MiB** | sim |

Passado isso, a resposta é `200` com **um item por resultado, na ordem recebida**:

| `status` | O que aconteceu | `durable` |
|---|---|---|
| `accepted` | Gravado: recibo, resultado e trabalho, na mesma transação | `true` |
| `duplicate` | O ledger já tinha este `result_id` com o **mesmo conteúdo** (hash canônico). Reenvio, não anomalia | `true` |
| `conflict` | Mesmo `result_id` com outro conteúdo, **ou** mesma chave natural `(sonda, assignment, geração, slot)` com outro `result_id`. A observação original **não** é sobrescrita e a divergência vai para `audit_events` | `false` |
| `rejected_terminal` | Mais velho que a janela de aceitação histórica (7 dias). Rejeição explícita; não se tenta de novo | `false` |
| `retryable` | Falha da **plataforma**, não do resultado: hoje, a falta de partição para o dia. A transação inteira voltou, inclusive o recibo | `false` |

Um item que falha **não impede** os demais do lote, e o que já commitou fica.

## Como ele garante o que promete

- **Idempotência global no ledger.** `INSERT … ON CONFLICT DO NOTHING` sem alvo cobre as três chaves únicas de uma vez
  (o `result_id` e as duas chaves naturais). Quem perde uma corrida espera o commit do outro e cai no `duplicate` ou no
  `conflict`.
- **Hash canônico.** O conteúdo é hasheado com as chaves ordenadas, então a ordem em que a sonda serializou os campos não
  transforma um reenvio em conflito.
- **Credencial.** Token aleatório de 256 bits, devolvido **uma vez**; o banco guarda só o SHA-256. Só serve aos
  endpoints `/probe/*`. O `received_at` e o `retain_until` são do **servidor**: o relógio da sonda não decide.
- **Limites de transporte** (C-19), no `BodyReader`: a expansão do gzip é **contada enquanto acontece**, em pedaços de
  4 KiB, e aborta ao passar de 16 MiB. O tamanho comprimido não previne uma bomba de descompressão (17 MiB de zeros
  comprimem para dezenas de KiB), então não é nele que se confia. De 1 a 500 itens por lote.

## O que não está aqui

O **motor não é acionado** pela ingestão: o item em `ingestion_work` fica `pending` até o worker `ingest` o consumir
([trabalho-assincrono.md](trabalho-assincrono.md)), e a decisão, o incidente e a notificação são do **B5**. **Não há limite de taxa** (`429`), que o contrato já lista. Não
existe cadastro administrativo de sondas: `ProbeEnrollment` é o mínimo para a ingestão dizer "este lote é desta sonda", e
a aprovação, a rotação e a revogação por interface são do F0-05. O replay (`mode: replay`) é aceito e gravado como
qualquer lote durável, **sem** o manifesto, a sessão e a reconciliação, que são do F0-04.

## A validação é código próprio, e por quê

O `BatchValidator` é uma **segunda implementação** das regras do contrato, porque a biblioteca de JSON Schema depende de
decisão do dono ([ADR-014](decisions.md), proposta). O que o impede de divergir do contrato em silêncio é o teste de
conformidade: ele passa cada fixture `valido-*` de `contracts/fixtures` e exige que cada `invalido-*` seja recusada, com o
manifesto como oráculo, e há uma versão ponta a ponta pelo endpoint. Quando a biblioteca for aprovada, troca-se o corpo do
validador e as fixtures conferem a troca.

## Como se prova

`central/tests/Feature/Ingestao/` e `tests/Unit/Ingestion/`: **60 testes**, pelo endpoint real, conferindo o banco depois
de cada chamada. Os que mais pesam: a **resposta perdida e o reenvio** (`duplicate`, sem segundo trabalho), a
**atomicidade** (sem partição, o item é `retryable` e nenhum recibo fica órfão), o **conflito** (original intacto e
auditado), o **403 no lote inteiro**, e a **bomba de descompressão**. **A suíte foi submetida a mutação:** treze estragos
que de fato mudam o comportamento (o reenvio de outro conteúdo virando `duplicate`, a janela de aceitação ignorada, a falta
de partição estourando em vez de `retryable`, o resultado, o trabalho ou a auditoria não gravados, o `accepted` não
durável, a autorização desligada, a geração futura aceita, a expansão do gzip e o corpo cru sem limite, a família IP não
conferida, e a credencial revogada ou a sonda suspensa ainda autenticando) derrubaram, **cada um, pelo menos um teste**.
