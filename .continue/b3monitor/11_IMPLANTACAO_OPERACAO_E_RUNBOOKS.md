# 11 — Implantação, operação e runbooks

**Versão:** 1.0 · 28/09/2026  
**Natureza:** RC de independência, recuperação e vigilância; procedimentos detalhados como DE-11.

## 1. Ambientes

**Desenvolvimento:** Compose e alvos sintéticos, sem segredos produtivos. Pode simular várias sondas, sempre marcadas como laboratório e sem alegar diversidade real.

**Homologação:** central e sondas representativas, IPv4/IPv6 reais quando previstos, canais e dispositivos autorizados. Mudanças em roteador só em ambiente isolado/janela aprovada.

**Produção:** serviços, DNS, identidade, backups, segredos e canais próprios. Não compartilhar banco com Sysadm. Nomes/DNS/IPs serão informados no documento 13; não reutilizar domínios conhecidos de outros projetos sem decisão explícita.

Separar dados e tokens de push/Telegram de teste dos produtivos. Seed fictício não pode aparecer como ativo real. Publicação em loja e alteração de DNS são ações externas sujeitas a autorização.

## 2. Inventário de implantação

Central: reverse proxy/TLS, aplicação/API, workers, PostgreSQL, Redis, agenda de manutenção/expurgo e métricas. Sondas: coordenador, executor local, identidade, política local e spool persistente. Vigilância: checker externo e mecanismo de ausência de batimento que não dependa da mesma central.

Registrar dependências de provedor, ASN/origem, DNS, autenticação, armazenamento e canais. Dois serviços na mesma VPS não são independentes; provedores distintos também não garantem ausência de dependências comuns. Documentar limites do desenho.

A central única é SPOF conhecido. Não afirmar HA por usar Docker ou nuvem. Buffer não substitui central disponível e watchdog não recupera automaticamente banco.

## 3. Pré-flight

Verificar DNS/TLS; acesso por conexão alternativa à Blue3; relógio contra referência externa; disco/volumes; backup; acesso administrativo; regras de firewall; capacidades de protocolo; política local; destino real autorizado; credentials escopadas; versão de imagens/binários fixada; configuração válida e prazo.

Na sonda, provar conectividade IPv6 no executor, não só endereço no host. Demonstrar ICMP sem container privilegiado. Confirmar que a porta do executor não está pública. Validar healthchecks que não façam alterações em equipamentos/clientes.

Nenhum procedimento de produção deve depender de “usar senha default” ou de desativar validação TLS.

## 4. Vigilância independente

### 4.1 Canário de processamento

O checker gera ID/nonce novo e injeta execução sintética em escopo reservado. O canário atravessa ingestão durável, trabalho assíncrono, motor, registro esperado de decisão/outbox e despacho para um destino técnico reservado. Não vale endpoint que simplesmente ecoa a requisição antes dos workers.

O checker consulta evidência do mesmo nonce e valida conteúdo, horários, prazo e recibo do despacho técnico. O destino técnico pode integrar o próprio vigilante, com autenticação; o recibo deve ser observável independentemente do status de criação da outbox. Só envia batimento saudável ao mecanismo externo depois dessa comprovação. API 200, processo vivo e resultado antigo não bastam.

O canário não integra disponibilidade de cliente nem gera alarme de queda a cada execução. O destino técnico testa o worker de despacho sem acionar plantão. Na primeira fatia local esse destino pode ser simulado, mas G-WATCH exige percurso técnico real. A comprovação de fluxo até esse destino não é prova de entrega push ao aparelho; verificar canais externos separadamente por teste autorizado e registrar esse alcance.

### 4.2 Vigiar o checker

O mecanismo de ausência recebe batimentos condicionados e alerta quando não chegam no prazo. Deve ficar em domínio de falha distinto e possuir credenciais/canal que não dependam da central ou apenas do canal principal dela. Pode ser serviço próprio ou externo selecionado pela operação, sem escolha obrigatória de fornecedor neste pacote.

Definir `WATCHDOG_INTERVAL_S`, `CANARY_DEADLINE_S` e `DMS_GRACE_S` na homologação. O prazo de ausência deve acomodar intervalo, deadline, jitter e atraso tolerado, sem criar janela desconhecida infinita. Publicar tempo de detecção observado.

O checker não manda “estou vivo” antes de validar o canário. Se morrer, parar DNS, perder token ou perder conectividade, a ausência continua sendo detectável pelo mecanismo externo.

### 4.3 Manutenção

Janelas de ativos não podem silenciar canário/ausência do vigilante. Manutenção da própria central é comunicada por procedimento específico; não converter ausência prevista em sinal falso de sucesso. Qualquer tratamento de aviso planejado na vigilância independente exige prazo e auditoria próprios.

## 5. Métricas da plataforma

Acompanhar atraso de última observação, percentil de agenda, tamanho/idade do spool, descartes, heartbeats, offset, falha de controles, ingestão aceita/rejeitada, dedup/conflito, itens duráveis pendentes, atraso dos workers, replays pendentes, atraso de decisão, outbox pendente, tentativas/erros por canal, validade de path profiles, cobertura e integridade.

Métricas de banco incluem espaço, crescimento de partições, atraso de agregação, backups e restore recente. Não imprimir payloads sensíveis para conseguir observabilidade. Usar correlation IDs e códigos estáveis.

## 6. Backups e restauração

Backups da central precisam incluir banco, configurações relevantes, revisões, incidentes, auditoria, ledger e outbox dentro da política definida. Chaves para descriptografar segredos e credenciais de recuperação exigem proteção e cópia separada. Backup cifrado sem chave recuperável não fecha o requisito.

Definir retenção de backups, frequência, destino independente e responsabilidades. RPO/RTO são DO antes de produção: devem ser acordados e medidos, não inferidos do tamanho do buffer.

Testar restore em ambiente isolado, sem duplicar envios reais. Conferir usuários, perfis, snapshots recalculados, incidentes e referências. Registrar duração, ponto de dados recuperado e itens irrecuperáveis. Redis não é a única fonte de trabalho; reconstruir fila a partir do banco.

Após restore real, identificar período perdido e ressincronizar sondas. Histórico pode ficar incompleto além do buffer disponível. Outbox/ledger restaurados de um ponto antigo podem levar a repetição de intenções externas já enviadas; reconciliar com registros disponíveis e documentar risco, sem prometer recuperação perfeita.

## 7. Rotina de deploy

Validar branch/commit/autorização → testes e revisão de segredos → backup/ponto de recuperação → migrações compatíveis → central e workers → verificação de canário → atualização gradual de sondas → smoke → acompanhamento → registro de versão.

Não parar todas as sondas para atualizar a central. Compatibilidade de contratos deve permitir atualização gradual dentro da janela suportada. Nova geração de config tem vigência explícita e não invalida artificialmente histórico antigo.

Rollback deve cobrir aplicação, executor, coordenador e compatibilidade de schema. Uma migração irreversível exige plano de recuperação de dados, não apenas trocar imagem. Publicar release/deploy somente com autorização do fluxo do repositório.

## 8. Runbooks mínimos

### RB-01 — Central indisponível

Confirmar aviso externo e acesso por rede alternativa. Distinguir falha de DNS/TLS/API/banco/workers. Não reiniciar toda a plataforma sem evidência. Preservar logs e backups. Restaurar componente afetado ou seguir recuperação aprovada.

Sondas continuam até seus limites; acompanhar backlog e descarte. Na volta, confirmar canário e dados correntes, iniciar/reconciliar backlog e verificar que incidentes antigos não geram tempestade de queda atual.

### RB-02 — API responde e monitoramento não evolui

Consultar canário pelo nonce recente, `ingestion_work`, atraso dos workers e outbox. API 200 não encerra o problema. Reenfileirar trabalho durável com operação idempotente; não reinserir manualmente resultados como novos. Validar estados e alertas após recuperação.

### RB-03 — Sonda sem comunicação

Não declarar todos os alvos fora. Conferir heartbeat, conectividade administrativa autorizada, relógio, disco, processo, spool e controles. Se a sonda estava medindo offline, preservar IDs/revisões no reenvio. Identificar lacunas quando o buffer não cobriu toda a falha.

### RB-04 — Integridade de caminho comprometida

Manter upstream sem conclusão atual por aquela evidência; não usar sucesso do backup para verde. Conferir revisão/hash e validade do perfil, leitura de equipamento, destino e possível desvio. Acionar NOC para correção controlada; o app não altera a rota. Retirar quarentena só após sequência íntegra prevista, podendo o caminho continuar falhando.

### RB-05 — Tempestade/oscilação

Verificar se é falha real, perda de controles, duplicação de domínio, replay ou mudança de configuração. Usar silêncio temporário apenas no escopo adequado, preservando incidentes de plataforma. Corrigir dedup/anti-flap e testar recuperação antes de desativar toda a notificação.

### RB-06 — Provedor de notificação falha

Registrar falha/tentativas, confirmar credencial/limite/rede e usar canal independente previsto. Não marcar entregue por HTTP timeout nem por registro local. Não repetir manualmente centenas de mensagens antigas; reavaliar relevância atual e regras do documento 03.

### RB-07 — Credencial comprometida

Revogar identidade afetada, rotacionar segredo, avaliar acessos e configuração alterada e preservar auditoria. Em sonda offline, aplicar bloqueio local/rede quando necessário; revogação na central não impede execução offline já autorizada até expirar. Reativação exige identidade/configuração verificadas.

### RB-08 — Disco ou retenção fora do limite

Identificar dados/spool/log responsáveis, preservar reserva de diagnóstico e executar política documentada. Registrar períodos descartados. Não truncar tabelas de incidentes ou dedup para liberar espaço sem avaliar impacto. Validar partições, agregados e reinserção de resultados antigos após expurgo.

## 9. Passagem para operação

Responsáveis definidos, credenciais/canais entregues com segurança, inventário preenchido, gates aprovados, documentação de rollback/restore, parâmetros de alertas revisados e limitações conhecidas. Reunião/aceite do NOC registrado no documento 13.

A liberação inicial deve ser supervisionada. Não informar “tudo operacional” apenas porque containers estão `running`. O dado de saúde é válido somente se a medição, cobertura, integridade e percurso de processamento sustentarem a conclusão.
