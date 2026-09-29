# 10 — Testes e critérios de aceite

**Versão:** 1.0 · 28/09/2026  
**Situação:** plano de verificação. Nenhum teste de aplicativo ou infraestrutura foi executado pela criação destes documentos.  
**Natureza:** critérios RC; cenários e rastreabilidade detalhados como DE-10.

## 1. Camadas de teste

**Unitário:** motor de estado e política com relógio controlado, sem serviços externos.  
**Contrato:** schemas reais entre Go, Laravel e Flutter; permissões e idempotência.  
**Integração:** PostgreSQL, filas, spool, executor e aplicação-alvo de laboratório.  
**Falha controlada:** desligamento, atraso, perda de resposta, disco, relógio e caminho.  
**Homologação real:** diversidade, IPv6, roteador, upstream, vigilância e mobile em equipamentos reais.

Identificar sempre `unit`, `contract`, `lab` ou `real`. Um resultado mock não comprova encaminhamento real. Toda falha de rede produtiva exige autorização e reversão, conforme documento 05.

## 2. Regra de evidência

Para cada execução registrar ID do caso, horário, tipo de ambiente, commit/versões, configuração/política, pré-condições, ação/comando utilizado, resultado esperado/obtido e artefato sanitizado. Informar `passed`, `failed`, `not_run` ou `blocked`; nunca converter ignorado em aprovado.

Artefatos sugeridos: relatório automatizado, JSON de resultados/snapshots, logs correlacionados, evidência de rede somente do fluxo autorizado e captura mobile sem segredos. Evidência deve mostrar a cronologia, não só um screenshot verde no final.

## 3. Estados e perspectivas — G-CORE

Perfil dos casos básicos: três origens distintas A/B/C, uma família por check, 30 s, três confirmações e frescor de 90 s.

| ID | Cenário | Resultado obrigatório |
|---|---|---|
| T-001 | Três sucessos consecutivos nas três origens | `up`, cobertura completa e contagens corretas |
| T-002 | Uma falha antes de completar sequência | Suspeita/pendência visível; sem confirmação prematura de queda |
| T-003 | Três falhas nas três origens | Um incidente canônico; `down / all_observed`, não “toda a internet” |
| T-004 | Apenas A falha persistentemente | `degraded / partial`; evidência de A preservada |
| T-005 | A/B falham e C funciona | Alcance parcial, não indisponibilidade total |
| T-006 | A some e B/C funcionam | Cobertura reduzida; ausência de A não vira queda de alvos |
| T-007 | Só A fornece evidência | Agregado desconhecido com cobertura insuficiente; exibir A individualmente |
| T-008 | Todas as amostras expiram sem nova ingestão | Snapshot fica desconhecido pelo relógio do motor; nenhum verde eterno |
| T-009 | Incidente aberto perde toda observação | Incidente não se resolve por ausência; estado corrente desconhecido |
| T-010 | Falhas de A antigas e de B atuais, sem sobreposição válida | Não compor falso quórum temporal |
| T-011 | Duas VMs no mesmo domínio + outra origem | Duplicação não cria dois votos independentes; conflitos explícitos |
| T-012 | Falha, lacuna, falha, falha | Lacuna interrompe sequência; não confirmar com três falhas espaçadas |
| T-013 | Mudança de geração durante sequência | Não misturar slots de configurações incompatíveis |
| T-014 | Latência excede limite configurado, resposta funcional passa | Degradação de desempenho distinta de indisponibilidade; sem limite universal |
| T-015 | Sem amostras suficientes para perda/percentil | Métrica indisponível, não percentual inventado |

## 4. Família IP, controles e integridade — G-CORE

| ID | Cenário | Resultado obrigatório |
|---|---|---|
| T-016 | IPv4 passa e IPv6 falha | Estados separados e serviço dual-stack não fica irrestritamente saudável |
| T-017 | Sonda sem capacidade IPv6 para assignment IPv6 | `capability_unavailable`; não cai para IPv4 |
| T-018 | Serviço explicitamente sem IPv6 | Não aplicável, não falha de serviço |
| T-019 | Consulta AAAA via IPv4 | Registrar família de transporte IPv4; não declarar teste de conectividade IPv6 |
| T-020 | DNS de controle falha e IP literal funciona | Suspeita de DNS; não invalidar testes por IP indiscriminadamente |
| T-021 | Uma sonda falha em todos os alvos e controles | Cobertura/confiança afetadas; falhas originais preservadas |
| T-022 | Um controle cai em várias sondas e os demais funcionam | Incidente/limitação da referência; não invalidar toda a plataforma |
| T-023 | Resposta válida com evidência de caminho contraditória | Integridade inválida; nunca confirma saúde/recuperação |
| T-024 | Último estado era up e ocorre integridade inválida | Estado presente desconhecido/comprometido, último verde somente histórico |
| T-025 | Quarentena do instrumento, depois três medições íntegras com falha de alvo | Sai da quarentena e conserva/confirma falha de caminho; não vira saudável |
| T-026 | Clock offset alto, ausente ou timestamp futuro indevido | Preservar resultado, negar voto temporal; zero não substitui desconhecido |

## 5. Incidentes, replay e outbox — G-DATA

| ID | Cenário | Resultado obrigatório |
|---|---|---|
| T-027 | Mesmo resultado enviado várias vezes | Uma observação lógica e nenhum incidente/aviso adicional |
| T-028 | Commit concluído, resposta HTTP perdida | Retry retorna duplicado aceito e spool pode confirmar sem perda |
| T-029 | Mesma chave com payload diferente | Conflito auditado; original imutável |
| T-030 | Central fora, incidente abre e fecha inteiro no backlog | Histórico reconstruído; não push crítico de problema já encerrado |
| T-031 | Replay contém problema ainda atual nunca notificado | Uma decisão consolidada de alerta após reconciliação relevante |
| T-032 | Replay repete problema já notificado | Atualiza timeline sem repaginação |
| T-033 | Replay demonstra agravamento atual de parcial para todas observadas | Um aviso consolidado de agravamento, não suprimido como repetição |
| T-034 | Backlog chega fora de ordem, paginado e duplicado | Resultado final igual ao processamento ordenado; nenhum alerta transitório por página |
| T-035 | Replay incompleto e fluxo corrente continua | Histórico marcado pendente; corrente não bloqueia indefinidamente |
| T-036 | Resultado histórico antigo chega depois de resultado atual | Corrige somente histórico pertinente; não sobrescreve estado atual com passado |
| T-037 | Resultado supera janela de aceitação e dedup antiga já expirou | Rejeição explícita por idade; não reaparece como novo |
| T-038 | Worker cai antes/depois de alterar incidente/outbox | Consistência transacional; intenção lógica única e recuperável |
| T-039 | Redis perde fila após ingestão confirmada | Reconciliador recupera trabalho durável, sem perda de observação |
| T-040 | Provedor aceita aviso, mas resposta se perde | Retry controlado; possibilidade externa de duplicata registrada, sem promessa falsa |
| T-041 | Flap dentro de janela de reabertura | Episódio reutilizado, instabilidade visível e alerta deduplicado |
| T-042 | Uma amostra íntegra/pass após instabilidade ou quarentena | Não basta para recuperação sustentada |
| T-043 | Reconhecer/assumir/silenciar incidente ainda falhando | Atendimento muda; saúde técnica não muda |
| T-044 | Manutenção termina enquanto falha persiste | Reavalia estado e emite no máximo intenção atual pertinente; não despeja backlog suprimido |

## 6. Sondas, limites e segurança — G-SEC/G-DATA

| ID | Cenário | Resultado obrigatório |
|---|---|---|
| T-045 | Central inacessível com configuração ainda válida | Agenda continua e spool persiste |
| T-046 | Configuração expira offline | Checks afetados deixam de executar; lacuna/capacidade visível, sem renovar autorização fictícia |
| T-047 | Processo reinicia no meio de teste e envio | Identidade/resultado/spool coerentes; sem reexecução conflitante |
| T-048 | Concorrência/timeout excede capacidade | Slot skipped/erro apropriado, sem fila infinita nem rajada de compensação |
| T-049 | Disco/spool atinge limite | Política de descarte explícita e faixa perdida registrada; preserva diagnóstico |
| T-050 | Central tenta acrescentar rede não autorizada localmente | Sonda bloqueia; política local permanece intacta |
| T-051 | URL/redirect para metadata, loopback ou rede privada não autorizada | Nenhuma conexão proibida, bloqueio auditado |
| T-052 | DNS rebinding e segunda resolução do executor | Conexão fica presa ao IP autorizado ou check é bloqueado |
| T-053 | IPv4 mapeado, porta alternativa e formato ambíguo tentam bypass | Normalização/política negam o acesso indevido |
| T-054 | Usuário leitura ou token de outra sonda tenta ação/ingestão | 403/negação sem vazamento nem alteração |
| T-055 | Payload enorme, conteúdo sensível e exceção do executor | Limite aplicado; logs/API/push sem segredo |
| T-056 | Token revogado, clone de identidade ou conflito de assignment | Detecção/rejeição coerente; sem confiança silenciosa |
| T-057 | Blackbox acessado fora do coordenador | Endpoint não está exposto; tentativa não contorna allowlist |

## 7. Upstreams — G-UP

Executar UP-01 a UP-09 do documento 05. Os principais são controle positivo, falha de A com B vivo, ausência de rota sem fallback, desvio indevido para saída padrão, falha de referência, evidência ausente e mudança posterior da política.

Evidência deve mostrar a revisão homologada, família, destino, método de vínculo e resultado. Contagem em regra de marcação, IP de eco ou captura de tela do painel, isoladamente, não fecham o gate.

Uma topologia sem capacidade de comprovação permanece não homologada. Homologação IPv4 não libera automaticamente IPv6. Testes destrutivos não podem ocorrer fora de laboratório/janela autorizada.

## 8. Vigilância e recuperação — G-WATCH/G-OPS

| ID | Cenário | Resultado obrigatório |
|---|---|---|
| T-058 | API retorna 200 mas worker/motor ou dispatcher para | Canário não completa; vigilante não envia batimento saudável |
| T-059 | Vigilante morre completamente | Mecanismo externo detecta ausência e alerta por caminho independente |
| T-060 | Canário tenta retornar resultado de execução antiga | Nonce/ID e prazo impedem falso sucesso |
| T-061 | Manutenção de ativos tenta silenciar canário | Não consegue mascarar a vigilância |
| T-062 | Restauração de backup em ambiente isolado | Configuração, segredos recuperáveis, incidentes e auditoria íntegros; RPO/RTO medidos |
| T-063 | Expurgo e agregação com resultado tardio | Período afetado recomposto; denominadores e cobertura corretos |
| T-064 | Período inteiro sem cobertura ou comprometido | Não resulta em 100% de disponibilidade nem divisão por zero |
| T-065 | Job deixa de enviar heartbeat com ingestão central saudável | Incidente do job após janela configurada |
| T-066 | Heartbeat de job ausente durante perda da ingestão | Limitação de observação explícita; não atribuir cegamente falha ao job |

## 9. Mobile e notificações — G-MOBILE

| ID | Cenário | Resultado obrigatório |
|---|---|---|
| T-067 | Push com app fechado, em iPhone e Android reais | Tentativa/aceite registrados; abertura carrega incidente atual autorizado |
| T-068 | Usuário nega notificação | Estado da permissão visível; outros canais continuam independentes |
| T-069 | App offline e snapshot vencido | Cache identificado; sem falsa medição recente nem ação concluída |
| T-070 | Reconhecimento efetivado, resposta perdida | Retry idempotente/consulta confirma uma única ação |
| T-071 | Logout e login de outra pessoa no mesmo aparelho | Associação push anterior revogada; nenhum vazamento entre contas |
| T-072 | Push antigo de incidente já recuperado | App consulta API e mostra recuperação atual |
| T-073 | Dois operadores alteram responsável/janela simultaneamente | Conflito tratado, sem sobrescrita silenciosa |
| T-074 | Usuário solicita teste manual | Somente check cadastrado/autorizado; fora do contador agendado por padrão |

## 10. Fixtures iniciais do motor

Usar eventos separados por slot com relógio de avaliação fixo; os três elementos de cada lista representam três slots consecutivos válidos e recentes. Esta fixture é um exemplo a transformar em testes, não motor executado.

```json
[
  {
    "case_id": "T-003",
    "inputs": {"A": ["fail", "fail", "fail"], "B": ["fail", "fail", "fail"], "C": ["fail", "fail", "fail"]},
    "expected": {"health": "down", "scope": "all_observed", "coverage": "full"}
  },
  {
    "case_id": "T-005",
    "inputs": {"A": ["fail", "fail", "fail"], "B": ["fail", "fail", "fail"], "C": ["pass", "pass", "pass"]},
    "expected": {"health": "degraded", "scope": "partial", "coverage": "full"}
  },
  {
    "case_id": "T-007",
    "inputs": {"A": ["pass", "pass", "pass"], "B": ["missing", "missing", "missing"], "C": ["missing", "missing", "missing"]},
    "expected": {"health": "unknown", "scope": "unknown", "coverage": "insufficient"}
  }
]
```

`missing` é símbolo da fixture, não outcome que a sonda transporta. Datas, domínios, offsets, gerações e perfis devem ser explicitados nas fixtures executáveis. Incluir permutações de ordem de chegada para testar determinismo.

## 11. Ensaio de carga

Perfil de referência herdado do escopo: 500 verificações lógicas, cada uma com família definida, em cinco sondas a cada 60 s. São 2.500 execuções/minuto, cerca de 41,7 resultados/s e 3,6 milhões/dia, sem heartbeats, controles ou novas famílias/checks.

Medir taxa sustentada, atraso de agenda, ingestão e decisão, latência de API, espaço por amostra, crescimento dos índices, recuperação de backlog, outbox e expurgo. Acrescentar cenário de replay de todas as sondas com carga corrente mantida; não validar só médias sem rajadas.

Metas iniciais do escopo: API de leitura p95 até 2 s no perfil homologado; falha persistente no perfil crítico de 30 s confirmada e primeira tentativa de aviso em até 2 min com central/sondas saudáveis. Registrar definição do instante de início da falha, percentis e exceções. Entrega ao aparelho não integra essa meta.

Não assumir capacidade de VM sem ensaio. Publicar configuração de hardware, versões, duração, massa, taxas e erros. Se reprovar, corrigir ou ajustar dimensionamento e documentar; não trocar a meta silenciosamente.

## 12. Rastreabilidade resumida

| Requisitos | Casos/gates principais |
|---|---|
| RC-01/02/06 | T-027 a 039, T-045 a 049; G-DATA |
| RC-03/04/05/10 | T-001 a 026; G-CORE |
| RC-07/11 | T-030 a 044; G-DATA |
| RC-08/09/18 | UP-01 a 09; G-UP |
| RC-12/15 | T-044, T-063/064; G-OPS |
| RC-13 | T-038/040, T-058 a 061, T-067/068; G-WATCH |
| RC-14 | T-050 a 057; G-SEC |
| RC-16 | T-067 a 074; G-MOBILE |
| RC-17 | Inspeção da implantação e restauração independente; G-OPS |

Liberação operacional depende dos gates, não da simples contagem de testes. Nenhum defeito que gere falso verde por dado inválido, desvio de caminho, credencial indevida ou recuperação fictícia pode ser aceito como cosmético.
