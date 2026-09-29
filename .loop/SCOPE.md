# Escopo — produção do b3monitor

Esta rodada fecha **F0-00 e F0-01** do backlog (`.continue/b3monitor/09_BACKLOG_E_PLANO_DE_ENTREGA.md`
§3) e termina no item `F0-01 FECHADA` da fila. Regra de leitura do material (dono,
29/09/2026): **o 2.0 complementa o 1.0 e, no que conflitar, vale o 2.0.**

## O que pode entrar

- Tudo o que a F0-00 e a F0-01 exigem: código em `central/`, `agents/`, `contracts/`,
  `deploy/`, `lab/` e `evidence/`; documentação em `docs/`; ADRs em `docs/decisions.md`.
- Corrigir, **na mesma passada**, o documento que uma mudança deixou velho.
- O PostgreSQL de desenvolvimento em espaço de usuário (porta 54329) e o laboratório numa
  máquina só, sempre identificado como `lab`. Uma demonstração local não comprova
  diversidade de rede, e um adaptador simulado não homologa upstream.
- A trilha de segurança (F0-09) desde o primeiro dia: política local da sonda, SSRF,
  rebinding, limites.
- Decisão local pequena e reversível: ADR curto, ou `.loop/ASSUMPTIONS.md`, e seguir.

## O que para e pergunta — vira `- 🔒` e o loop segue para o próximo item

- Dados, credenciais ou tokens **de produção**, ou de qualquer serviço externo (Telegram,
  FCM, APNs, provedores de VM ou de DNS).
- Dependência nova **fora** da que o ADR-013 fixar (`composer require`, `go get` e
  `npm install` estão em `ask` no `settings.json`).
- Permissão nova no `.claude/settings.json`: conceder é ato do dono.
- Qualquer outro repositório (BLUE3-INTRANET, BLUE3-INTRANET-MOBILE) ou a intranet `sys`.
- Nome do produto, escopo comercial e qualquer mudança de invariante (INV-01…12).
- Deploy, roteamento, filtros, anúncios, DNS, equipamentos ou serviço de produção.
- Apagar item da `.continue/` sem que a coisa que ele descreve exista.
- `push --force`, reescrever histórico ou apagar branch.

## Fora desta rodada

F0-02 a F0-11 e toda a Fase 1 (F1-01…F1-10). O módulo mobile só começa depois de
G-CORE e G-DATA passarem em laboratório (02 §8). Se a fila zerar antes do item final, o
reabastecimento escolhe o próximo bloco **dentro deste escopo**; não havendo, escreva o
veredito com os números em `.loop/SEM-ESCOPO`.

## Onde acompanhar

Quadro *Produção b3monitor* (URL no `CLAUDE.md`). Ele é atualizado pelo agente a cada
item fechado; o estado que vale é o do repositório (`git log`, `docs/status/SESSOES.md`).
