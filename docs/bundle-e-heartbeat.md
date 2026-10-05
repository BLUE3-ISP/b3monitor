# O bundle e o heartbeat, do lado da central

> **Status:** `ACTIVE` · `GET /api/v1/probe/config` e `POST /api/v1/probe/heartbeat` (item B7b da fila). Os contratos são
> [`contracts/openapi/openapi.yaml`](../contracts/openapi/openapi.yaml), `bundle.schema.json` e `heartbeat.schema.json`; a
> especificação é o [06 §4 e §7](../.continue/b3monitor/06_MODELO_DE_DADOS_E_CONTRATOS.md) e o [04 §8](../.continue/b3monitor/04_SONDAS_E_EXECUCAO.md).
> O lado da sonda está em [sonda.md](sonda.md).

## `GET /api/v1/probe/config`: o bundle desta sonda

A sonda recebe **o seu** bundle e só ele. A identidade vem **sempre da credencial**: não existe `?probe_id=` para pedir o de outra
(um parâmetro assim é ignorado, e há teste). O bundle é a junção dos **assignments desta sonda** com os **checks** deles
(`BundleBuilder`); um assignment de outra sonda nunca entra, e um assignment cujo check ainda não existe também não.

- **Nunca política local de redes (INV-09).** O bundle SELECIONA operações; o que a VM aceita medir é a política local da sonda, que a
  central não vê nem amplia. Não há campo para isso, nem para URL de callback ou instrução executável, e segredos entram por
  referência (`credential_ref`), nunca em texto.
- **`bundle_revision` é monótona e sobe sozinha.** Vem de `probes.bundle_revision`, que um **gatilho do banco** sobe quando um
  assignment da sonda é inserido, removido, trocado de sonda ou atualizado, ou quando muda o **check** de um assignment dela. Mudar a
  configuração de outra sonda, ou um check que ela não executa, **não** sobe a revisão dela.
- **Jitter determinístico (04 §3).** `jitter_offset_ms` é derivado do hash do assignment **e da geração**, até 10% do intervalo: o mesmo
  assignment na mesma geração tem sempre o mesmo, em qualquer central e a qualquer momento; mudou a geração, muda o offset.
- **Validade.** `expires_at = emitido + B3MON_BUNDLE_VALIDITY_S` (24 h). É o tempo que a sonda pode medir com este bundle sem falar com a
  central (04 §1); passado isso ela para (`config_expired`).
- `Cache-Control: no-store`: a sonda vê a revisão vigente, e um intermediário não serve o bundle de outra.

O que **ainda não existe**: o cadastro administrativo de checks e assignments (F0-05). Hoje a definição de um check entra pelo banco; o
laboratório (B8) a semeia por um comando.

## `POST /api/v1/probe/heartbeat`: o autodiagnóstico

O heartbeat é **capacidade e saúde** da sonda, nunca resultado de alvo e nunca heartbeat de job (14 §2). O `HeartbeatValidator` é uma
implementação própria das regras do contrato (ADR-014 em aberto), guardada pelo manifesto das fixtures **e** por um teste por regra
(cada campo, cada limite, cada `additionalProperties`), e partilha as primitivas com o `BatchValidator` (`ValidaContrato`).

| Status | Quando |
|---|---|
| `204` | Registrado |
| `400` | Não é JSON, ou viola o contrato (a lista diz o **caminho e a regra**, nunca o valor recebido) |
| `401` | Credencial ausente, inválida, ou sonda suspensa |
| `403` | O `probe_id` do corpo **não é o da credencial**: uma sonda não relata a saúde de outra |
| `413` | Mais de 64 KiB |

Guarda o **estado atual** em `probe_status` (uma linha por sonda, atualizada e contada), com o **desvio de relógio** entre a sonda e a
central em milissegundos, que pode ser **negativo** (04 §8: o painel precisa do sinal). Relógio `unknown` com `offset_ms` não nulo é
recusado (INV-02). O **histórico** de saúde da sonda é do F0-07, e um heartbeat que não chega **não** vira incidente aqui.

## Como se prova

`central/tests/Feature/Sonda/` (o bundle: forma igual à fixture do contrato, só o que é da sonda, revisão por gatilho, jitter,
`?probe_id=` ignorado; o heartbeat: estado atual, desvio positivo e negativo, 400 sem valor, 403, 413) e
`tests/Unit/Ingestion/HeartbeatValidatorTest.php` (cada regra do schema, 27 violações, 27 campos ausentes, campo extra em 6 níveis).
**A suíte foi submetida a mutação:** 35 estragos (assignments de todas as sondas, jitter sem teto ou sem a geração, revisão constante,
identidade vinda do parâmetro, gatilho que não sobe em cada caso, validador sem cada regra, `ON CONFLICT` que duplica…) e **sete
escaparam na primeira rodada** (a família IP do bundle e seis regras do validador que só as fixtures exercitavam por acaso). Cada um
ganhou teste, e na segunda rodada os 35 foram pegos.

**O que a prova não prova:** que a sonda de verdade consome o bundle de verdade. Os dois lados foram provados contra a **mesma
fixture** do contrato (a forma do JSON que a central serve é a da fixture que o `bundle.Ler` da sonda aceita), mas o encontro dos
dois é o laboratório (B8) e o aceite (B10).
