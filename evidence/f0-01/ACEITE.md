# Aceite da F0-01 no laboratório

> **É laboratório.** Aprovar aqui prova o **caminho** (alvo → sonda → ingestão → motor → incidente → notificação simulada → painel). Não homologa upstream,
> IPv6, mobile, diversidade real de rede nem canal real, e nada passa de `verified_lab` (09 §1). Os bloqueios estão no fim, **escritos como bloqueio**.

| | |
|---|---|
| Comando | `make lab-up && make acceptance-lab` (`lab/acceptance.sh`) |
| Versão | 0.1.26 |
| Commit | 7f8a173 (árvore com alterações não commitadas) |
| Data (UTC) | 2026-10-06T14:19:12Z |
| Duração | 183 s |
| Sistema | Linux 6.12.107+deb13-amd64 |
| PHP | 8.4.26 |
| Go | go1.26.8 (toolchain do módulo agents/probe) |
| PostgreSQL | 17.11 |
| Ambiente | `lab`: central em `php -S`, três workers, agendador, alvo `lab` e duas sondas simuladas na mesma máquina; canal `dev` SIMULADO |

## Casos

| Caso | Prova | INV | RC | T | Resultado | O que se afirma |
|---|---|---|---|---|---|---|
| Duplicata | AO VIVO | INV-07 | RC-06 | T-027 | APROVADO | mesmo resultado enviado 3 vezes: `accepted`, `duplicate` e `duplicate` durável; **uma** linha no ledger; incidentes e avisos inalterados |
| Resposta perdida depois do commit | AO VIVO | INV-07 | RC-06 | T-028 | APROVADO | o commit aconteceu sem a resposta ser lida; o reenvio é `duplicate` durável (a sonda pode apagar do spool); **uma** linha no ledger. A reação da **sonda** é provada na SUÍTE (abaixo) |
| Conflito (mesma chave, conteúdo diferente) | AO VIVO | INV-07 | RC-06 | T-029 | APROVADO | `conflict` não durável; o original ficou imutável; o conflito foi **auditado** |
| Sucesso | AO VIVO | INV-01, INV-02 | RC-01, RC-03 | T-001 (com 2 domínios, não 3) | APROVADO | alvo responde 200; estado `up`, cobertura plena, nenhum incidente (3 asserções) |
| Falha → incidente | AO VIVO | INV-01 | RC-05 | T-003 | APROVADO | alvo 503: **um** incidente `critical` `all_observed` e **uma** notificação `opened` (não uma por amostra) (4 asserções) |
| Recuperação | AO VIVO | INV-01 | RC-05 | T-003 | APROVADO | alvo volta: incidente **fechado** e notificação `recovered`; estado de volta a `up` (3 asserções) |
| Ausência | AO VIVO | INV-02, INV-11 | RC-03 | T-008 | APROVADO | as duas sondas param: o estado deixa de ser `up` **sozinho**, vira `unknown` com cobertura insuficiente; **nenhum** incidente nem aviso (6 asserções) |
| Reenvio (replay) com a central fora | AO VIVO | INV-07, INV-08 | RC-06, RC-07 | T-030 | APROVADO | central cai, o spool guarda, a central volta: **um** incidente com início observado no passado e **uma** notificação (7 asserções) |
| Fecho do cenário | AO VIVO | INV-07 | RC-05, RC-07 | — | APROVADO | duas quedas, duas `opened`, duas `recovered`, nenhum item `dead`, todo aviso `[SIMULADO]` (6 asserções) |
| Resposta perdida: a reação da sonda | SUÍTE | INV-07 | RC-06 | T-028 | APROVADO | a sonda reenvia depois da resposta perdida, recebe `duplicate` e só então apaga do spool (1 teste(s) Go) |
| Central fora → spool → reenvio (a sonda) | SUÍTE | INV-07 | RC-06 | T-045 | APROVADO | 10 resultados na central, nenhum no spool, nenhum duplicado (1 teste(s) Go) |
| Reinício no meio do teste (a sonda) | SUÍTE | INV-07 | RC-06 | T-047 | APROVADO | processo morto com SIGKILL: nada se perde, nada duplica, nenhum sucesso fabricado (1 teste(s) Go) |
| Ingestão idempotente (a central) | SUÍTE | INV-07 | RC-06 | T-027, T-028, T-029 | APROVADO | aceito, duplicado, conflito e resposta perdida depois do commit, com o recibo e o trabalho na mesma transação (20 testes Pest, nenhum pulado) |
| Expiração por tempo (a central) | SUÍTE | INV-02, INV-11 | RC-03 | T-008 | APROVADO | sem resultado novo, o estado vira `unknown` pelo relógio do motor; não fecha incidente aberto nem avisa (6 testes Pest, nenhum pulado) |
| Replay sem tempestade (a central) | SUÍTE | INV-08 | RC-07 | T-030, T-032, T-036 | APROVADO | incidente que abre e fecha inteiro no backlog, e histórico antigo que não sobrescreve o presente (5 testes Pest, nenhum pulado) |
| Painel: nunca verde antigo | SUÍTE | INV-11 | RC-03 | — | APROVADO | validade vencida aparece como desconhecida, mesmo com o banco dizendo `up`; saúde, cobertura e integridade separadas (25 testes Pest, nenhum pulado) |

**AO VIVO** = contra a central de verdade, rodando agora. **SUÍTE** = teste automatizado do repositório, rodado agora; teste pulado ou ausente reprova.

## Bloqueado (o que não rodou e **não é aceite**)

| O que | INV | RC | T | Por quê |
|---|---|---|---|---|
| IPv6 | INV-03 | RC-04 | T-016, T-017 | O laboratório só tem IPv4 em loopback. O executor recusa fallback entre famílias (testado em Go), mas não há alvo IPv6 real nem sonda com capacidade IPv6 medida (F0-06). |
| Upstream real e prova de caminho | INV-05 | RC-08, RC-09, RC-18 | UP-01 a 09 | Sem upstream, sem borda e sem vínculo de saída reais: G-UP segue `blocked` e depende de Q-02 (fabricante e modelo da borda), que é do dono. |
| Diversidade real de rede | INV-04 | RC-02, RC-10 | T-021, T-022 | As duas sondas do laboratório estão na mesma máquina e na mesma rede: dois domínios de observação **declarados**, não independentes. |
| Relógio medido (NTP/PTP) | INV-02 | RC-02 | T-026 | O relógio das sondas é **declarado** pelo operador (`lab_declared`); a sonda ainda não mede referência externa. |
| Canal de notificação real (Telegram, push) | INV-10 | RC-13 | T-040, T-058 a 061 | Só o canal `dev`, SIMULADO. Telegram, FCM e APNs dependem de credenciais do dono; o vigilante independente é do F0-07. |
| iOS e mobile | INV-12 | RC-16 | T-067 a 075 | O módulo mobile vive em outro repositório e não faz parte desta fatia (D-05, D-08). |
| Segurança de produção (autenticação, 2FA) | INV-09 | RC-14 | T-050 a 057 | O painel não tem login (F0-05) e é fechado por padrão, só loopback. A política local do laboratório é de laboratório. |
| Carga, disco real enchendo e fuso de produção | INV-07 | RC-06, RC-15 | T-049, T-063, T-064 | Nenhum teste rodou com carga real nem com disco de verdade enchendo. |
