# central

> **Status:** `ACTIVE` · A aplicação Laravel 13 do b3monitor: ingestão, motor de estado, incidentes,
> API e painel. Hoje só existe o esqueleto, a rota de saúde e a suíte; o resto nasce nos itens
> B1 a B9 da fila do loop.

## O que há aqui

| Caminho | O que é |
|---|---|
| `app/<Módulo>/` | Um namespace por módulo de [02 §3](../.continue/b3monitor_2.0/02_ARQUITETURA_E_STACK.md): `Identity`, `Inventory`, `Probes`, `Ingestion`, `State`, `Incidents`, `Notifications`, `History`, `Audit`, `Ops`. Sem pacotes Composer por módulo. A maioria está vazia (`.gitkeep`) |
| `app/Ops/HealthController.php` | `GET /api/v1/health`: o processo responde e o PostgreSQL atende. **Não é o canário** (F0-07): 200 aqui não prova que os workers andam |
| `routes/api.php` | A API, sob `/api/v1` (`bootstrap/app.php`) |
| `tests/` | Pest. `tests/TestCase.php` tem a trava que só deixa a suíte rodar em `b3mon_test` |

## Rodar

Da raiz do repositório, com o PostgreSQL de dev no ar:

```bash
make pg-up        # porta 54329, em espaço de usuário
make test         # esta suíte
```

O passo a passo, as variáveis do `.env` e o que fazer quando algo não sobe estão em
[docs/runbook.md](../docs/runbook.md), seções 2 e 3.

## Regras que valem aqui

- **A suíte só roda em `pgsql` / `b3mon_test` / porta 54329.** O `RefreshDatabase` recria o banco a
  cada teste, e a trava do `TestCase` recusa qualquer outra conexão antes de apagar o que quer que
  seja. O `phpunit.xml` fixa o banco com `force="true"`.
- **Sem Redis no caminho crítico** ([ADR-008](../docs/decisions.md)): fila e cache no PostgreSQL.
- **Dependência nova** (`composer require`) é decisão do dono, e as versões estão no
  [ADR-013](../docs/decisions.md).
- **O motor de estado é puro** (item B1): sem banco, rede nem relógio real, e nenhum envio de
  notificação dentro dele.
