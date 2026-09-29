# 02 — Arquitetura e stack

**Versão:** 1.0 · 28/09/2026  
**Natureza:** arquitetura base RC; stack e organização de código são DE-01/DE-02.

## 1. Componentes e responsabilidades

| Componente | Responsabilidade | Não é sua responsabilidade |
|---|---|---|
| Central | Configuração, ingestão, estado, incidentes, auditoria e API | Fazer medições diretamente na LAN como substituto da sonda |
| Coordenador da sonda | Política local, agenda, executor, normalização, spool e reenvio | Alterar roteamento/equipamentos |
| Executor local | Realizar um teste permitido e devolver métricas/erros | Agenda, quórum, incidentes ou buffer do produto |
| App | Consulta, push e atendimento | Executar sondagem contínua |
| Painel | Administração, configuração e investigação | Ser nova autoridade de incidentes |
| Vigilante | Verificar percurso sintético e emitir sinal externo condicionado | Depender dos workers que está verificando |
| Ausência de batimento | Alertar se o vigilante não completar sua verificação | Depender da central ou apenas do Telegram da central |

Fluxo textual: **configuração aprovada → sonda/política local → agenda → executor → spool → ingestão PostgreSQL → processamento → projeções/incidentização/outbox → APIs e canais**.

Fluxo independente: **vigilante externo → canário autenticado → mesmo percurso de ingestão/decisão/despacho técnico → resultado e recibo correlacionados observados → batimento condicionado → verificador de ausência**.

## 2. Direção inicial de implementação

| Camada | Decisão inicial |
|---|---|
| Central | Laravel, monólito modular, API versionada |
| Painel | Interface server-driven do ecossistema Laravel; não criar SPA independente apenas para o cadastro |
| Banco | PostgreSQL; medições particionadas e agregação explícita |
| Transporte de trabalho | Redis com workers; registro durável recuperável no PostgreSQL |
| Sonda | Coordenador Go, processo independente |
| Executor | Avaliar Blackbox Exporter local por HTTP; não tratá-lo automaticamente como biblioteca Go embutida |
| Spool da sonda | SQLite local ou arquivo transacional equivalente; escolha inicial SQLite, com teste de falha/reinício |
| Aplicativo | Flutter, iOS/Android |
| Infraestrutura | Debian e Docker Compose; configuração e versões reproduzíveis |

Nenhuma versão exata de framework, linguagem ou SO é fixada como “a atual” neste pacote. O agente deverá verificar suporte e compatibilidade, fixar versões/imagens e registrar um ADR curto. Não usar tags flutuantes `latest` na homologação.

A adequação do Blackbox precisa ser demonstrada para cada protocolo, IP efetivo, asserção e política de segurança. Se um caso não puder ser executado com destino revalidado e conexão presa ao IP autorizado, implementar adaptador seguro ou restringir a funcionalidade; não liberar SSRF para preservar a escolha do executor.

## 3. Modularização da central

Módulos sugeridos: Identidade e Acesso; Inventário; Sondas e Configuração; Ingestão; Políticas e Estado; Incidentes; Notificações; Histórico; Auditoria; Operação.

O motor de estado deve ser determinístico: recebe política versionada, observações/elegibilidade e instante de avaliação; devolve estado e transições. Testá-lo sem serviços externos. Consultas de rede e envio de push não entram nessa função.

Uma única autoridade decide incidentes. Não manter simultaneamente motor próprio e Alertmanager abrindo o mesmo evento independentemente. As ideias de agrupamento/inibição podem orientar o produto sem exigir implantação do Alertmanager.

## 4. Organização sugerida do monorepo

```text
apps/
  central/          # Laravel: API, painel, workers, domínio e testes
  mobile/           # Flutter
agents/
  probe/            # Go: coordenador e adaptadores
  watchdog/         # executor independente; simples e reutilizável
contracts/
  openapi/          # contrato real gerado/validado pelo agente
  schemas/          # resultados, configuração, eventos
infra/
  compose/
  lab/
  deployment/
docs/
  specification/    # este pacote
  adr/
  runbooks/
evidence/           # somente evidências sanitizadas
```

Estrutura é ponto de partida. Adaptar a repositório existente sem migração cosmética. Não compartilhar banco ou runtime com Sysadm e não reutilizar o nome ShvIA por causa do título dos debates.

## 5. Confiabilidade por fronteira

**Sonda → central:** entrega pelo menos uma vez. A confirmação é individual e só ocorre depois de persistência durável da observação e do registro de trabalho. A sonda não apaga o spool por receber um simples aceite em memória.

**Banco → worker:** trabalho recuperável no banco. Redis pode perder mensagens sem perda definitiva dos resultados: um reconciliador reenfileira itens pendentes. Duplicatas de execução devem ser seguras.

**Estado → notificação:** transição e intenção de envio gravadas na mesma transação por outbox. O envio usa retentativas e identidade estável; o provedor pode não oferecer exatamente uma vez.

**Cache → mobile:** snapshot contém `as_of`, validade e cobertura. Cache não é fonte de verdade. Reconhecimento/silêncio exigem confirmação do servidor.

**Configuração → sonda:** revisão imutável, validade e ativação atômica. A sonda continua com a última configuração válida dentro de seu prazo, mesmo durante falha central; não aceita ampliação de sua política local.

## 6. Implantação inicial proposta

Três sondas externas em origens diversas, uma interna, uma central externa e vigilância independente. Os números são meta inicial de piloto, não inventário constatado. Registrar provedor, ASN, região, rede de origem e dependências conhecidas.

Não publicar Blackbox Exporter, Redis, PostgreSQL ou interfaces de gerenciamento. Somente a API/reverse proxy da central precisa ser publicamente alcançável, conforme firewall. Sondas iniciam conexões de saída e não exigem porta administrativa aberta para a central.

A central única é ponto único de falha declarado. Estar fora da Blue3 não cria alta disponibilidade. O MVP aceita essa limitação com vigilância, backup e procedimento de recuperação; failover automático é evolução.

## 7. Caminho vertical antes de horizontalizar

A primeira fatia cobre todas as fronteiras com apenas HTTP/IPv4 em laboratório. As mesmas interfaces recebem novos protocolos e perspectivas depois. Não implementar o aplicativo completo antes de provar decisão, deduplicação, integridade e recuperação.

Jobs externos de heartbeat não são heartbeat da sonda nem o canário. Mesmo compartilhando infraestrutura de ingestão, terão tipos e políticas próprios.

## 8. Migração futura de armazenamento

Começar com PostgreSQL não estabelece que ele é suficiente para qualquer escala. Também não obriga sua substituição por volume diário abstrato. Medir escrita, leitura, tamanho por linha, índices, compressão possível, retenção e rajadas de replay. TimescaleDB ou outro armazenamento especializado só entra por ADR apoiado em ensaio; metadados e semântica de incidente permanecem independentes dessa escolha.

## 9. Contrato de comandos a implementar

| Comando-alvo | Resultado esperado |
|---|---|
| `make dev-up` | Laboratório local isolado e dependências com healthchecks |
| `make demo` | Cenário sintético identificado como laboratório |
| `make test` | Testes unitários e contratos sem rede de produção |
| `make verify` | Testes, análise estática, lint e validação de contratos |
| `make acceptance-lab` | Cenários automatizados do documento 10 aplicáveis ao laboratório |
| `make probe-doctor` | Diagnóstico de permissões, família IP, relógio, spool e destinos permitidos |
| `make smoke` | Verificação não destrutiva do ambiente selecionado |

Esses comandos ainda não existem: são entregáveis, não instruções prontas para executar sobre produção. Equivalentes justificados são aceitáveis se documentados.
