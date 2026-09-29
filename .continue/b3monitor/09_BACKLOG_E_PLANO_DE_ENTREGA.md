# 09 — Backlog e plano de entrega

**Versão:** 1.0 · 28/09/2026  
**Natureza:** fases RC; decomposição e sequência DE-09.  
**Regra:** implementar fatias verificáveis; não condicionar todo o software à disponibilidade do roteador real.

## 1. Estados de trabalho

`not_started`, `in_progress`, `implemented`, `verified_lab`, `verified_real`, `blocked`. Código pronto não implica teste aprovado. Um item pode estar implementado e bloqueado apenas na homologação real; registrar a distinção.

Não existem prazos ou estimativas de esforço aprovados neste pacote. O agente dimensionará depois da inspeção, sem presumir equipe, hardware ou velocidade de execução.

## 2. Primeira fatia vertical — F0-01

Entregar laboratório com uma aplicação HTTP controlada, coordenador Go e política local, agenda persistente, ingestão durável, estado por sequência válida, incidente, outbox com canal de desenvolvimento e painel mínimo. Persistir identidade/revisão/família desde o início, mesmo antes de todos os protocolos.

Provar sucesso, falha, ausência, duplicata, resposta de ingestão perdida após commit e recuperação. A notificação de desenvolvimento deve estar identificada como simulada; não usar token real para cada execução de CI.

Esse caminho é a primeira demonstração. Não gastar a primeira etapa em dezenas de CRUDs, design mobile, todos os conectores e uma arquitetura que ainda não executa um teste.

## 3. Backlog da Fase 0

| ID | Entrega | Dependência | Evidência de saída |
|---|---|---|---|
| F0-00 | Inspeção do repo, convenções, bootstrap, ADR de stack, contracts skeleton | Nenhuma | Ambiente sobe; ferramentas/versionamento documentados; alterações existentes preservadas |
| F0-01 | Fatia HTTP/IPv4 completa descrita acima | F0-00 | Demo real local e testes de sucesso/falha/duplicação |
| F0-02 | Agenda robusta, spool, gerações, controles e diagnóstico | F0-01 | Crash/reinício, sem central, skipped, limite de disco e relógio |
| F0-03 | ICMP/TCP/DNS e IPv4/IPv6 separados | F0-02 | Família efetiva, incapacidade e fallback bloqueado |
| F0-04 | Quórum, cobertura, integridade, anti-flap e replay | F0-01/02 | Motor determinístico e casos temporais/fora de ordem |
| F0-05 | Cadastro/admin mínimo, ACL de usuário, 2FA admin e auditoria básica | F0-01 | Controle de acesso e revisões; sem credencial default no piloto real |
| F0-06 | Telegram, outbox recuperável e canal de desenvolvimento | F0-04/05 | Envio autorizado real e dedup de decisões |
| F0-07 | Canário de processamento, vigilante e ausência de batimento | F0-04/06 | API 200 com worker parado detectada; vigilante morto também |
| F0-08 | Perfis de caminho, adaptador mínimo e ensaios UP em lab | F0-02/03/04 | Prova positiva, falha sem fallback e bypass detectado |
| F0-09 | Políticas locais/SSRF, testes adversariais e limites | Desde F0-01 | Fronteiras de segurança testadas; não etapa cosmética tardia |
| F0-10 | Retenção, agregados básicos, backup e restore de laboratório | F0-04 | Expurgo seguro, dados desconhecidos explícitos e restauração |
| F0-11 | Piloto real: sondas externas/interna, 10–20 ativos, upstreams disponíveis | F0-02 a 10 + DO | Evidências reais, inventário, métricas e aceite do NOC |

F0-09 é uma trilha transversal desde a primeira fatia; a linha serve ao fechamento formal, não autoriza desenvolver meses sem política local. F0-05 deve estar pronto antes de qualquer piloto com infraestrutura real.

### Gate 0A — software/laboratório

Todos os casos obrigatórios de motor, contrato, segurança e resiliência executáveis em laboratório passam. A demonstração é reproduzível, usa dados fictícios identificados e não depende de cliente produtivo. É permitido continuar desenvolvimento F1 quando apenas a validação real está bloqueada.

### Gate 0B — piloto real

Sondas/destinos/IPv6 efetivos; diversidade registrada; canal de alerta autorizado; vigilância independente; rota/caminho aprovado; ensaios upstream e backup/restore aplicáveis demonstrados. Sem 0B, não anunciar upstream homologado ou produção confiável.

## 4. Backlog da Fase 1 — operação mobile

| ID | Entrega | Dependência | Evidência de saída |
|---|---|---|---|
| F1-01 | Asserções JSON limitadas, TLS periódico e heartbeat de job | Contratos/motor F0 | Funções tipadas e ausência de heartbeat sem confundir perda da central |
| F1-02 | Painel com administração, histórico, filtros e perfis | F0-05/10 | Operador administra sem editar banco manualmente |
| F1-03 | APIs operacionais, reconhecimento, responsável, comentários | F0-04/05 | Autorização, idempotência e concorrência |
| F1-04 | Silêncios/manutenção com término, auditoria e relatórios | F1-03 | Não alteram saúde; término reavalia alerta; sem indicador maquiado |
| F1-05 | App Flutter: acesso, dashboard, ativos, sondas e incidentes | F1-02/03 + API estável | Consulta real, cache honesto e permissões |
| F1-06 | Push e ciclo completo de atendimento mobile | F1-05 + DO mobile | iPhone/Android reais, logout, permissão e deep link |
| F1-07 | Dois canais críticos e escalonamento simples | F0-06/F1-06 + destinatários | Tentativas separadas; não reconhecimento provoca regra aprovada |
| F1-08 | Carga, replay sob pressão, retenção, observabilidade e runbooks | Funcionalidades prontas | Benchmark reproduzível e recuperação medida |
| F1-09 | Staging → piloto supervisionado → liberação operacional | Gates 0B e 1 + DO | Checklist assinado, rollback e responsabilidades |

App não é substituído por PWA sem decisão explícita. Publicação em loja não é presumida: distribuição de teste real é suficiente para o gate de funcionamento, enquanto publicação comercial exige autorização própria.

## 5. Fase 2 — somente após valor comprovado

Telemetria rica BGP/interface, BMP onde suportado, novos fabricantes, traceroute/MTR sob demanda e com limites, óptica/CPU/memória, correlação avançada, escalas completas, relatórios adicionais, integrações com ferramentas já usadas e HA quando justificada. Cada item exige caso de uso e aceite próprios.

Não implementar “preparação para SaaS”, IA de causa raiz, NMS completo ou automação de rede como parte oculta de uma refatoração do MVP.

## 6. Definição de concluído por fatia

Código e configuração versionados; testes unitários/contrato/integrados pertinentes; migração forward/backward ou recuperação documentada; logs/segredos revisados; métricas mínimas; documentação de execução; evidência com comando/ambiente/commit; backlog e status atualizados.

Um teste ignorado não conta como aprovado. Registro “não executado: host sem IPv6” é válido como bloqueio, não como aceite. Uma alteração de semântica exige atualizar 03, fixtures e testes no mesmo trabalho.

## 7. Gates de qualidade

| Gate | Condição |
|---|---|
| G-CORE | Motor determinístico, frescor, integridade e quórum corretos |
| G-DATA | Persistência/idempotência/replay/outbox sem corrupção ou tempestade lógica |
| G-SEC | Autorização e política local; sem SSRF ou credenciais expostas |
| G-UP | Prova real do caminho e testes positivos/negativos na topologia selecionada |
| G-WATCH | Canário e ausência do vigilante detectam falhas independentes |
| G-MOBILE | iPhone/Android reais; ação confirmada e cache honesto |
| G-OPS | Restore, limites de carga, retenção, responsáveis e rollback |

Gate da Fase 1 exige todos. Um “go parcial” de desenvolvimento pode registrar gates pendentes, mas não renomeá-los como concluídos.

## 8. Como tratar bloqueios

Cada bloqueio precisa de ID, item afetado, evidência, informação/ação requerida, responsável e tarefas que continuam livres. Exemplo: falta de acesso somente leitura ao roteador bloqueia G-UP, mas permite implementar adaptador simulado, contrato, interface, ingestão e testes do motor.

Não perguntar novamente por informação já presente no repositório/formulário. Não inventar valores produtivos. Decisões locais pequenas ficam em ADR/DE e seguem; alterações de segurança, escopo comercial ou produção exigem decisão apropriada.

## 9. Relatório de sessão do agente

Apresentar o que mudou e o que foi realmente executado, falhas encontradas, estado dos gates, pendências humanas e próxima fatia exata. Preferir links para evidências e commits locais às afirmações genéricas “tudo pronto”. Não encerrar cada subetapa solicitando autorização redundante; não prometer trabalho autônomo fora da sessão.
