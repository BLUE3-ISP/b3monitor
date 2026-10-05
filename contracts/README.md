# contracts

> **Status:** `ACTIVE` · Os contratos entre a central, as sondas e os clientes. **Uma fonte só**: o
> mesmo conjunto de schemas e de fixtures é lido pela central (PHP) e pelos agentes (Go).

## O que há aqui

| Caminho | O que é |
|---|---|
| `schemas/*.schema.json` | JSON Schema 2020-12 de cada corpo: `result` (resultado normalizado, 06 §5), `ingest-batch` (lote, 06 §6), `ingest-response` (resposta por item), `bundle` (configuração da sonda, 06 §4) e `heartbeat` (autodiagnóstico, 04 §8). `common.schema.json` concentra os tipos e os **enums do glossário 14 §3**, a única fonte |
| `openapi/openapi.yaml` | OpenAPI 3.1. Referencia os schemas por `$ref` externo, então não existe um segundo contrato que possa divergir. Hoje só `GET /health` existe na central; `POST /probe/results` é o item B3 |
| `fixtures/**` | Exemplos de **laboratório** (`lab`), com dados fictícios (RFC 5737 e 3849, `.example`). `manifest.json` lista cada um, o schema contra o qual vale e, nas inválidas, **a regra que viola** |

## As regras que moram no schema

Os schemas carregam as invariantes que cabem em JSON Schema, de modo que um valor errado é recusado
na porta, e não descoberto depois no motor:

- **INV-03, IPv4 e IPv6 sem fallback:** o IP efetivo tem de ser da família do assignment (`format`).
- **INV-02, desconhecido não é zero:** relógio `unknown` exige `offset_ms` nulo.
- **Tempo só em UTC com `Z`** (14 §6) e **nenhum campo extra** em nível algum, em especial URL de
  callback (06 §11).
- **Agendado × manual** (06 §3 e §12), **`accepted` só se durável** (06 §3) e **replay carrega a sessão**.
- **O executor é `native`** (C-05, D-02): o `blackbox-adapter` do 1.0 não existe mais.

O que **não** cabe em schema e fica com o servidor: os limites de 4 MiB e de expansão de 16 MiB, a
credencial que corresponde ao `probe_id`, e "não aceitar `not_required` em assignment que exige prova de
upstream" (cruza com a configuração).

## Duas ressalvas de uso

1. **A validação de `format` precisa estar ligada.** É ela que verifica a família IP do INV-03. Uma
   biblioteca com `format` só como anotação aceita um IPv6 num assignment IPv4 sem reclamar.
2. **Hoje nada no repositório valida contra os schemas.** Os testes (`tests/Unit/ContratosTest.php` na
   central e `internal/contratos` na sonda) conferem a **estrutura**: JSON bem formado, `$ref` que
   resolve, manifesto e disco em correspondência, toda fixture inválida dizendo a regra. A validação
   de verdade depende de uma biblioteca em cada linguagem, e isso é decisão do dono
   ([ADR-014](../docs/decisions.md), proposta). Até lá, a conferência foi feita **uma vez**, com
   validadores descartáveis fora do repositório: as 44 fixtures batem com o manifesto em Go e em PHP, e
   cada inválida falha pelo motivo declarado.

## Acrescentar uma fixture

Valores de enum só mudam por ADR (14 §4). Uma fixture nova entra com o arquivo, a linha no manifesto e,
se for inválida, **uma regra só**, dita em `regra`. Derive-a de uma válida mudando uma coisa.
