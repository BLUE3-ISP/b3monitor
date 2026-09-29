# Blue3 — documentação consolidada de implementação

**Versão documental:** 1.0 · **Data:** 28/09/2026  
**Situação:** especificação para desenvolvimento; não representa software ou ambiente homologado.

Este arquivo reúne os mesmos 15 documentos do pacote modular. Quando receber apenas esta versão, o agente deve tratar cada seção nomeada como o documento correspondente e pode separá-la em arquivos no repositório. O prompt de execução está na seção 00; as pendências operacionais estão na seção 13.

## Índice dos documentos

- [README.md](#doc-readme)
- [00_PROMPT_PARA_O_AGENTE.md](#doc-00-prompt-para-o-agente)
- [01_PRODUTO_E_ESCOPO.md](#doc-01-produto-e-escopo)
- [02_ARQUITETURA_E_STACK.md](#doc-02-arquitetura-e-stack)
- [03_ESTADOS_INCIDENTES_E_REPLAY.md](#doc-03-estados-incidentes-e-replay)
- [04_SONDAS_E_EXECUCAO.md](#doc-04-sondas-e-execucao)
- [05_UPSTREAMS_E_PROVA_DE_CAMINHO.md](#doc-05-upstreams-e-prova-de-caminho)
- [06_MODELO_DE_DADOS_E_CONTRATOS.md](#doc-06-modelo-de-dados-e-contratos)
- [07_SEGURANCA.md](#doc-07-seguranca)
- [08_APP_PAINEL_E_ALERTAS.md](#doc-08-app-painel-e-alertas)
- [09_BACKLOG_E_PLANO_DE_ENTREGA.md](#doc-09-backlog-e-plano-de-entrega)
- [10_TESTES_E_CRITERIOS_DE_ACEITE.md](#doc-10-testes-e-criterios-de-aceite)
- [11_IMPLANTACAO_OPERACAO_E_RUNBOOKS.md](#doc-11-implantacao-operacao-e-runbooks)
- [12_DECISOES_PREMISSAS_E_FONTES.md](#doc-12-decisoes-premissas-e-fontes)
- [13_CHECKLIST_DO_PILOTO_E_STATUS.md](#doc-13-checklist-do-piloto-e-status)


---

<a id="doc-readme"></a>

**Arquivo de origem desta seção:** `README.md`

## Blue3 — Monitoramento distribuído

**Pacote de implementação 1.0 · 28/09/2026**  
**Situação:** especificação consolidada para iniciar desenvolvimento; não é software implementado nem implantação homologada.  
**Nome do produto:** ainda não definido. “Blue3 Monitoramento” é somente identificação de trabalho. Não confundir com ShvIA.

### Começar por aqui

Entregue este diretório ao agente no repositório destinado ao novo projeto e use [00_PROMPT_PARA_O_AGENTE.md](#doc-00-prompt-para-o-agente). O agente deve construir o produto incrementalmente, começar pelo ciclo real de medição e não parar na criação de outra proposta de arquitetura.

A aplicação será independente do Sysadm. O celular consulta, recebe alertas e permite atender incidentes. As VMs executam as medições continuamente. O piloto já inclui **upstream mínimo verificável**; telemetria rica de BGP/interfaces fica para evolução, exceto leituras indispensáveis à validação do caminho.

### Mapa de leitura

| Documento | O que resolve |
|---|---|
| [00 — Prompt para o agente](#doc-00-prompt-para-o-agente) | Ordem de trabalho, autonomia e limites operacionais. |
| [01 — Produto e escopo](#doc-01-produto-e-escopo) | Requisitos, entregas por fase e exclusões. |
| [02 — Arquitetura e stack](#doc-02-arquitetura-e-stack) | Componentes, responsabilidades e escolhas iniciais. |
| [03 — Estados, incidentes e replay](#doc-03-estados-incidentes-e-replay) | Fonte normativa das decisões de saúde, cobertura, integridade e notificações após reenvio. |
| [04 — Sondas e execução](#doc-04-sondas-e-execucao) | Agenda, identidade, controles, buffer, relógio e execução segura. |
| [05 — Upstreams e prova de caminho](#doc-05-upstreams-e-prova-de-caminho) | O que precisa ser provado e o que depende da topologia real. |
| [06 — Modelo de dados e contratos](#doc-06-modelo-de-dados-e-contratos) | Entidades, exemplos de payload, endpoints, persistência e idempotência. |
| [07 — Segurança](#doc-07-seguranca) | Autorização, credenciais, SSRF, políticas locais e trilha de auditoria. |
| [08 — App, painel e alertas](#doc-08-app-painel-e-alertas) | Experiência operacional, ações e canais de comunicação. |
| [09 — Backlog e entrega](#doc-09-backlog-e-plano-de-entrega) | Fatias implementáveis, dependências e definição de concluído. |
| [10 — Testes e aceite](#doc-10-testes-e-criterios-de-aceite) | Cenários positivos, falhas controladas e portões de homologação. |
| [11 — Implantação e operação](#doc-11-implantacao-operacao-e-runbooks) | Ambientes, backups, vigilância e procedimentos de recuperação. |
| [12 — Decisões, premissas e fontes](#doc-12-decisoes-premissas-e-fontes) | O que veio das discussões, o que foi detalhado aqui e o que permanece pendente. |
| [13 — Checklist e status](#doc-13-checklist-do-piloto-e-status) | Formulário de topologia, pendências humanas e registro de evidências. |

### Como interpretar este pacote

- **RC — requisito consolidado:** recorte e princípios extraídos do escopo, debates e revisão final da conversa.
- **DE — decisão inicial de engenharia:** detalhamento acrescentado neste pacote para torná-lo implementável. Pode ser ajustado por ADR com justificativa e testes; não deve ser apresentado como decisão histórica já aprovada pelo NOC.
- **DO — dependência operacional:** informação, credencial, autorização ou evidência do ambiente real. Não pode ser inventada nem substituída por um mock na homologação.

Os valores numéricos são parâmetros iniciais de homologação, não um SLA contratado. A stack é uma direção de implementação, não constatação sobre um repositório existente. Versões de dependências deverão ser verificadas e fixadas pelo agente no momento de implementar.

### Precedência e fonte de verdade

Este pacote consolida e substitui a proposta 0.1 para implementação. Os debates são fontes de ideias e objeções; não são instruções a executar integralmente. Em particular, **não são requisitos universais** um `/24` exclusivo, contador com delta exatamente igual a N, TimescaleDB obrigatório ou suporte universal a roteadores.

Em divergências internas, prevalecem: segurança e limites operacionais do documento 07; semântica de estados do 03; atribuição de upstream do 05; contratos do 06; fases do 09. Corrigir a documentação conflitante no mesmo trabalho, em vez de manter duas regras.

Não copiar números de parâmetros em vários lugares no código: centralizar os perfis versionados. A matriz de valores iniciais está no documento 03, seção 12.

### Primeira entrega esperada

Um caminho executável em laboratório: **alvo HTTP → sonda com política local → ingestão durável → motor de estado → incidente → notificação de desenvolvimento → painel mínimo**, com testes de sucesso, falha, ausência, reenvio e duplicação.

A seguir, ampliar para perspectivas independentes, IPv6, upstream mínimo e vigilância. O app iPhone/Android entra na fase operacional, sem ser cancelado ou substituído silenciosamente por uma PWA.

### O que ainda não existe

Este pacote não contém código da aplicação, infraestrutura provisionada, credenciais, inventário real, resultado de benchmark ou aceite do NOC. Os comandos e contratos descritos são entregáveis que o agente deverá implementar. Os campos em aberto estão reunidos no documento 13 e não impedem desenvolver o laboratório.


---

<a id="doc-00-prompt-para-o-agente"></a>

**Arquivo de origem desta seção:** `00_PROMPT_PARA_O_AGENTE.md`

## Prompt de execução para o agente

**Uso:** colar o texto abaixo na sessão que possui acesso ao repositório e disponibilizar todos os arquivos deste pacote.

---

Implemente uma plataforma independente de monitoramento distribuído para a Blue3, seguindo estes documentos. Quero implementação incremental com testes e evidências, não apenas mais planejamento.

Comece por `README.md`, `01_PRODUTO_E_ESCOPO.md`, `02_ARQUITETURA_E_STACK.md`, `03_ESTADOS_INCIDENTES_E_REPLAY.md`, `05_UPSTREAMS_E_PROVA_DE_CAMINHO.md`, `07_SEGURANCA.md` e `09_BACKLOG_E_PLANO_DE_ENTREGA.md`. Consulte os contratos e testes antes de programar cada módulo. Use o documento 13 para registrar progresso e pendências reais.

### 1. Antes de alterar

Inspecione o repositório, branch, alterações locais, instruções existentes, ferramentas disponíveis e documentação já implementada. Preserve trabalho existente. Não sobrescreva `AGENTS.md`, regras de projeto ou configurações sem analisar conflitos. Não invente nome final, domínio, IP, ASN, fabricante, versão de roteador ou credencial.

Em projeto vazio, use a direção inicial: central Laravel em monólito modular, PostgreSQL, Redis como transporte de trabalho, coordenador de sonda Go, Blackbox Exporter local avaliado como executor e Flutter para iOS/Android. Docker Compose para desenvolvimento e implantação inicial. Não introduza Kubernetes, microserviços desnecessários, SaaS multiempresa ou integração direta com banco do Sysadm.

Verifique versões suportadas e compatibilidade nos mantenedores oficiais, registre o que escolheu e fixe dependências. Divergência material de stack exige ADR curto, não uma reformulação geral do produto.

### 2. Ordem de execução

Faça primeiro a fatia `F0-01` do backlog: um alvo HTTP real de laboratório, sonda agendada e protegida por política local, persistência durável, decisão testada, incidente, notificação em canal de desenvolvimento e painel mínimo. Depois avance pelos itens desbloqueados da Fase 0.

Construa testes de contrato, motor de estados e falhas antes de ampliar as telas. Inclua upstream mínimo no piloto. Integração rica BGP/interfaces, BMP e novos fabricantes ficam fora da primeira entrega, salvo a leitura mínima indispensável a comprovar o caminho.

Os laboratórios podem usar simuladores e fixtures identificados como tais. Uma demonstração Docker na mesma máquina não comprova diversidade de rede. Um adaptador simulado não homologa upstream real. Ausência de acesso ao NOC bloqueia apenas a homologação dependente desse acesso, não todos os módulos de software.

### 3. Invariantes que não podem ser simplificadas

- Serviço, cobertura e integridade da medição são dimensões distintas.
- Sem amostra, amostra inválida, relógio inadequado ou dado antigo não significam saúde nem queda do alvo.
- IPv4 e IPv6 são verificações independentes, sem fallback entre famílias.
- Sonda perdida não derruba automaticamente os ativos; controles falhos não apagam evidências reais.
- Upstream não fica saudável pelo sucesso do backup, pelo IP devolvido por um eco ou por contador de regra de marcação.
- Reconhecimento, atribuição de responsável, silêncio e manutenção não resolvem falhas.
- Agenda e buffer ficam na sonda. Entrega é pelo menos uma vez; persistência e processamento têm idempotência.
- Replay recompõe histórico, mas somente evidência recente e elegível pode determinar estado atual. Notificações são reconciliadas, não disparadas cegamente por cada amostra reenviada.
- A política local de segurança não pode ser ampliada unilateralmente pela central.
- O vigilante usa canário de processamento e batimento condicionado em mecanismo independente.
- Estado antigo saudável nunca será apresentado como atual quando a medição perdeu confiabilidade.

### 4. Autonomia e segurança

Continue nas tarefas desbloqueadas durante a sessão, sem parar a cada etapa apenas para pedir “posso continuar?”. Registre decisões locais razoáveis como DE/ADR. Pergunte apenas por dados, permissões ou escolhas materiais que realmente não possam ser resolvidos no repositório e nos documentos.

Não altere roteamento, filtros, anúncios, equipamentos, DNS ou serviços de produção sem autorização específica e plano de reversão. Não use credenciais produtivas nos testes. Não faça varredura de endereços arbitrários. Não dê push, merge, exclusão de branch, publicação em loja ou deploy real sem autorização de execução compatível com a tarefa e as regras do repositório. Não prometa executar em segundo plano fora da sessão.

### 5. Evidência e entrega

Para cada fatia, entregue código, migrações quando necessárias, testes, documentação operacional e demonstração reproduzível. Registre comando, ambiente, versão/commit, resultado e caminho dos artefatos de teste. Diga explicitamente quando um teste não pôde rodar, inclusive iOS sem ambiente Apple, IPv6 ausente ou equipamento não acessível.

Mantenha `13_CHECKLIST_DO_PILOTO_E_STATUS.md` atualizado sem marcar como validado o que foi apenas codificado. Se precisar encerrar a sessão, deixe o repositório coerente e informe: concluído com evidência, pendente, bloqueio humano concreto e próxima tarefa exata. Não declare o produto em produção apenas porque a aplicação compilou.

Comece agora pela inspeção e pela primeira fatia funcional.

---

### Critério de sucesso desta instrução

A resposta do agente deve estar acompanhada de alterações verificáveis e resultados de testes executados, ou de um bloqueio técnico específico demonstrado. Uma nova lista de intenções, sem tentativa de implementação, não constitui entrega.


---

<a id="doc-01-produto-e-escopo"></a>

**Arquivo de origem desta seção:** `01_PRODUTO_E_ESCOPO.md`

## 01 — Produto e escopo

**Versão documental:** 1.0 · 28/09/2026  
**Natureza:** RC; detalhamentos de recorte identificados na matriz de fases.  
**Objetivo:** permitir que a operação saiba o que foi observado, de onde, com qual confiabilidade e qual atendimento está em andamento.

### 1. Problema a resolver

A Blue3 precisa acompanhar aplicações, equipamentos e conectividade por sondas em VMs externas e internas. Serviços podem continuar acessíveis quando um upstream falha, porque o backup assume o tráfego. Uma sonda também pode perder conectividade e produzir sintomas semelhantes a falha do alvo.

O produto deve distinguir essas situações sem prometer observação de toda a internet ou causa raiz automática. A central ficará fora da infraestrutura monitorada, com suas dependências e ponto único de falha declarados. Uma vigilância independente verificará o monitoramento.

### 2. Usuários e interfaces

| Papel | Necessidade |
|---|---|
| Gestor | Entender impacto, serviços afetados, cobertura e redundância. |
| Operador/NOC | Receber alerta, consultar evidências, reconhecer, assumir e registrar atendimento. |
| Administrador | Gerenciar sondas, ativos, políticas, usuários e destinos autorizados. |
| Leitura | Consultar apenas os recursos autorizados, sem ações operacionais. |

O app iPhone/Android é a interface de plantão e consulta rápida. O painel web é a interface de configuração e investigação; seu dashboard com atualização atende a visão inicial de NOC. Não haverá terceira aplicação dedicada a NOC no MVP.

### 3. Requisitos consolidados rastreáveis

| ID | Requisito |
|---|---|
| RC-01 | Verificações contínuas em sondas, sem depender do celular ou de ordem central por execução. |
| RC-02 | Registrar origem, domínio de falha conhecido, horário, protocolo, destino efetivo e família IP de cada observação. |
| RC-03 | Separar saúde/qualidade, cobertura e integridade; ausência não é sucesso nem falha confirmada. |
| RC-04 | IPv4/IPv6 independentes; distinguir família não contratada de capacidade indisponível da sonda. |
| RC-05 | Incidentes com evidência, abrangência limitada ao observado, confirmação e recuperação sustentadas. |
| RC-06 | Agenda, configuração versionada, buffer limitado, reenvio e processamento idempotente. |
| RC-07 | Replay reconstrói histórico sem verde antigo, incidentes duplicados ou tempestade de notificações. |
| RC-08 | Upstream mínimo no piloto, com vínculo de saída validado e ausência de fallback silencioso. |
| RC-09 | Estado dos upstreams separado do alcance público e da redundância. |
| RC-10 | Controles por sonda e integridade explícita, preservando evidências de falhas reais coincidentes. |
| RC-11 | Anti-flap, reconhecimento e silêncio com semânticas independentes do estado técnico. |
| RC-12 | Manutenção com término, autor e justificativa; coleta continua e relatório não mascara exclusões. |
| RC-13 | Canais de alerta, histórico de envio e vigilância independente de processamento. |
| RC-14 | Segurança local das sondas, somente leitura nos equipamentos e prevenção de abuso por destinos. |
| RC-15 | Histórico com retenção, agregação e cobertura visível; tempo desconhecido nunca é disponibilidade. |
| RC-16 | Ações mobile confirmadas pelo servidor e cache claramente desatualizado quando offline. |
| RC-17 | Produto independente do Sysadm, sem banco compartilhado nem dependência de execução da rede interna. |
| RC-18 | Testes reais de upstream, vigilância, IPv6 e mobile como portões de homologação, não substituídos por mocks. |

### 4. Matriz de escopo por fase

| Capacidade | Fase 0 — prova operacional | Fase 1 — operação mobile | Fase 2 |
|---|---|---|---|
| ICMP, TCP, HTTP/HTTPS, DNS | Básicos, fim a fim | Configuração completa e limites | Ampliações |
| Famílias IP | Separadas e demonstradas | Obrigatório | — |
| TLS | HTTPS com validação de certificado | Verificação periódica de vencimento e alerta | Políticas adicionais |
| Asserções de API | Status/conteúdo simples | JSON declarativo limitado | Jornadas multietapas |
| Heartbeat de sonda | Obrigatório | Obrigatório | — |
| Heartbeat de job externo | Não bloqueia piloto | Básico autenticado | Regras avançadas |
| Upstream por caminho | Obrigatório em laboratório e prova real para gate de upstream | Uma plataforma/topologia homologada | Novas plataformas |
| Interface/rota | Leitura mínima se necessária à prova | Somente o mínimo homologado | Telemetria rica |
| BGP detalhado/BMP | Fora | Fora | Conforme inventário |
| Estados, cobertura e integridade | Obrigatórios | Obrigatórios | Correlação avançada |
| Replay, controles e anti-flap | Núcleo testado | Completo no perfil MVP | Refinamentos |
| Painel web | Cadastro e visão mínimos | Administração e investigação | Relatórios avançados |
| App Flutter | Não bloqueia laboratório | iPhone e Android | Melhorias |
| Alertas | Canal de desenvolvimento; Telegram real no piloto | Push e Telegram; e-mail opcional | Escalas e integrações |
| Segurança/auditoria | Desde a primeira fatia | Completa para MVP | Expansões |
| Manutenção/silêncio | Mecanismo básico antes de mudanças reais | Interface e governança | Regras avançadas |
| Watchdog profundo | Obrigatório no gate real | Obrigatório | HA possível |
| Retenção/backup | Política e restauração do piloto | Operação comprovada | Conforme escala |

A separação TLS/API/heartbeat externo entre F0 e F1 é um recorte de engenharia deste pacote. Não cancela essas capacidades na primeira versão operacional.

### 5. Entidades do domínio

**Ativo:** serviço, aplicação, equipamento ou circuito. Contém nome amigável, descrição, tipo, POP/grupo, criticidade, responsável e etiquetas.

**Verificação:** aspecto observável de um ativo, com protocolo, destino, família IP explícita, critério de sucesso e perfil de medição. Um ativo pode possuir várias verificações.

**Assignment:** vínculo versionado entre verificação, sonda, agenda e caminho opcional. É a unidade executável. A separação evita tratar três medições de uma verificação como três ativos.

**Perspectiva:** combinação de origem/sonda, família IP e caminho aplicável. A diversidade registrada limita o quórum; quantidade de VMs não demonstra independência física absoluta.

**Resultado:** observação imutável com execução, integridade e evidências. Não confundir resultado com incidente nem ausência de resultado com timeout do alvo.

**Incidente:** episódio operacional com identidade estável, classe, gravidade, alcance, evolução e estado de atendimento.

**Caminho de upstream:** perfil de encaminhamento e evidência homologado pelo NOC, separado da simples configuração de destino.

### 6. Observações mínimas por tipo

| Tipo | O que pode afirmar | O que não pode afirmar sozinho |
|---|---|---|
| ICMP | Houve resposta/RTT; sucesso das amostras | Aplicação de negócio funcional |
| TCP | Conexão na porta | Resposta correta da aplicação |
| HTTP/API | Resposta e asserção configurada | Todas as jornadas de negócio funcionam |
| DNS | Resposta do servidor consultado | Todos os resolvedores respondem igual |
| TLS | Validação e vencimento observado | Disponibilidade integral da aplicação |
| Upstream | Observação do caminho definido | Causa comercial do defeito ou retorno simétrico não medido |

HTTP básico deve usar operações sem efeitos de negócio; nenhum teste executa compras, alterações de cadastro ou scripts livres. Endpoints públicos atrás de proxy/CDN e suas origens são verificações distintas.

### 7. Exemplos de produto

1. Serviço responde em IPv4, falha em IPv6: exibir dimensões separadas e degradação do serviço dual-stack, não saúde global.
2. Upstream A falha em caminho homologado, B permanece disponível e serviços públicos respondem: “serviços acessíveis; caminho A com falha; redundância reduzida”.
3. Evidência do caminho A fica inválida: “situação do caminho A não confirmada; integridade do monitoramento comprometida”. Sucesso pelo B não recupera A.
4. Duas de três sondas param de enviar: “cobertura insuficiente”; não declarar todos os alvos indisponíveis.
5. Operador reconhece incidente: atendimento registrado; o estado técnico continua sendo calculado.

### 8. Fora do MVP

SaaS multiempresa e cobrança; NMS completo; descoberta de toda a rede; coleta de todas as ONUs; análise integral BGP; NetFlow/sFlow; testes contínuos de velocidade; shell remoto; scripts arbitrários; reboot; remediação automática; alterações automáticas de BGP; chamados completos; página pública de status; IA como autoridade de causa raiz; Kubernetes; suporte universal a fabricantes.

Uma interface preparada para novos executores não é autorização para implementar todos agora. A aplicação deve admitir evolução sem antecipar toda a complexidade comercial.

### 9. Critério de valor da primeira versão

A primeira versão será útil quando um operador conseguir diferenciar falha real, alcance parcial, redução de redundância e incapacidade de medição, receber os alertas relevantes e registrar atendimento pelo celular. Quantidade de telas ou conectores não substitui essa comprovação.


---

<a id="doc-02-arquitetura-e-stack"></a>

**Arquivo de origem desta seção:** `02_ARQUITETURA_E_STACK.md`

## 02 — Arquitetura e stack

**Versão:** 1.0 · 28/09/2026  
**Natureza:** arquitetura base RC; stack e organização de código são DE-01/DE-02.

### 1. Componentes e responsabilidades

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

### 2. Direção inicial de implementação

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

### 3. Modularização da central

Módulos sugeridos: Identidade e Acesso; Inventário; Sondas e Configuração; Ingestão; Políticas e Estado; Incidentes; Notificações; Histórico; Auditoria; Operação.

O motor de estado deve ser determinístico: recebe política versionada, observações/elegibilidade e instante de avaliação; devolve estado e transições. Testá-lo sem serviços externos. Consultas de rede e envio de push não entram nessa função.

Uma única autoridade decide incidentes. Não manter simultaneamente motor próprio e Alertmanager abrindo o mesmo evento independentemente. As ideias de agrupamento/inibição podem orientar o produto sem exigir implantação do Alertmanager.

### 4. Organização sugerida do monorepo

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

### 5. Confiabilidade por fronteira

**Sonda → central:** entrega pelo menos uma vez. A confirmação é individual e só ocorre depois de persistência durável da observação e do registro de trabalho. A sonda não apaga o spool por receber um simples aceite em memória.

**Banco → worker:** trabalho recuperável no banco. Redis pode perder mensagens sem perda definitiva dos resultados: um reconciliador reenfileira itens pendentes. Duplicatas de execução devem ser seguras.

**Estado → notificação:** transição e intenção de envio gravadas na mesma transação por outbox. O envio usa retentativas e identidade estável; o provedor pode não oferecer exatamente uma vez.

**Cache → mobile:** snapshot contém `as_of`, validade e cobertura. Cache não é fonte de verdade. Reconhecimento/silêncio exigem confirmação do servidor.

**Configuração → sonda:** revisão imutável, validade e ativação atômica. A sonda continua com a última configuração válida dentro de seu prazo, mesmo durante falha central; não aceita ampliação de sua política local.

### 6. Implantação inicial proposta

Três sondas externas em origens diversas, uma interna, uma central externa e vigilância independente. Os números são meta inicial de piloto, não inventário constatado. Registrar provedor, ASN, região, rede de origem e dependências conhecidas.

Não publicar Blackbox Exporter, Redis, PostgreSQL ou interfaces de gerenciamento. Somente a API/reverse proxy da central precisa ser publicamente alcançável, conforme firewall. Sondas iniciam conexões de saída e não exigem porta administrativa aberta para a central.

A central única é ponto único de falha declarado. Estar fora da Blue3 não cria alta disponibilidade. O MVP aceita essa limitação com vigilância, backup e procedimento de recuperação; failover automático é evolução.

### 7. Caminho vertical antes de horizontalizar

A primeira fatia cobre todas as fronteiras com apenas HTTP/IPv4 em laboratório. As mesmas interfaces recebem novos protocolos e perspectivas depois. Não implementar o aplicativo completo antes de provar decisão, deduplicação, integridade e recuperação.

Jobs externos de heartbeat não são heartbeat da sonda nem o canário. Mesmo compartilhando infraestrutura de ingestão, terão tipos e políticas próprios.

### 8. Migração futura de armazenamento

Começar com PostgreSQL não estabelece que ele é suficiente para qualquer escala. Também não obriga sua substituição por volume diário abstrato. Medir escrita, leitura, tamanho por linha, índices, compressão possível, retenção e rajadas de replay. TimescaleDB ou outro armazenamento especializado só entra por ADR apoiado em ensaio; metadados e semântica de incidente permanecem independentes dessa escolha.

### 9. Contrato de comandos a implementar

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


---

<a id="doc-03-estados-incidentes-e-replay"></a>

**Arquivo de origem desta seção:** `03_ESTADOS_INCIDENTES_E_REPLAY.md`

## 03 — Estados, incidentes e replay

**Versão:** 1.0 · 28/09/2026  
**Natureza:** princípios RC; algoritmos, enums e parâmetros são detalhamento DE-03/DE-04.  
**Autoridade:** este documento define a semântica do motor. A interface não pode reinterpretá-la.

### 1. Dimensões independentes

| Dimensão | Valores iniciais | Significado |
|---|---|---|
| Saúde observada | `up`, `degraded`, `down`, `unknown` | Conclusão limitada às observações válidas e ao escopo. |
| Abrangência | `none`, `partial`, `all_observed`, `unknown` | Onde a falha foi observada; `all_observed` não significa toda a internet. |
| Cobertura | `full`, `reduced`, `insufficient` | Quantidade/diversidade de perspectivas elegíveis comparada ao plano. |
| Integridade | `valid`, `suspect`, `invalid`, `unknown` | Confiabilidade da medição, não saúde do alvo. |
| Estabilidade | `stable`, `unstable` | Oscilação confirmada e sua recuperação. |
| Administração | pausa e janelas de manutenção | Condições registradas que não substituem o estado técnico. |
| Atendimento | reconhecido, responsável e silêncio | Ações humanas que não mudam a medição. |

Mostrar também `as_of`, última medição válida, resultado mais recente, contagens de perspectivas e motivos. O último estado confiável pode aparecer no histórico, mas não representar a situação presente quando sua elegibilidade desapareceu.

### 2. Tipos de resultado e ausência

| Resultado (`outcome`) | Interpretação |
|---|---|
| `pass` | Teste executado, critério funcional satisfeito. Ainda depende da integridade e do contexto. |
| `fail` | Teste executado, critério não satisfeito: timeout do alvo, status inesperado, asserção falha etc. |
| `executor_error` | Executor não conseguiu medir; não classificar como falha do alvo. |
| `config_error` | Configuração inválida ou incompatível. |
| `policy_denied` | Destino/protocolo/operação bloqueado pela política local. |
| `capability_unavailable` | Capacidade necessária indisponível, como IPv6 ou permissão de ICMP. |
| `skipped` | Slot não executado por limite, conflito de agenda ou validade expirada. |

**Ausência de amostra** é detectada pela central a partir da agenda. Não fabricar um `fail` no lugar da amostra ausente. Não executar um teste atrasado e carimbá-lo com horário do passado para preencher lacunas.

Uma resposta HTTP correta com prova de caminho inválida não é um `pass` elegível para upstream. Preservar a resposta e a falha de integridade no mesmo pacote de evidência.

### 3. Elegibilidade da observação

Uma amostra só alimenta a saúde corrente se todas as condições pertinentes forem satisfeitas:

1. Identidade autenticada e assignment autorizado; schema e limites válidos.
2. Slot, geração e configuração compatíveis; geração antiga serve apenas ao histórico daquele período.
3. Horário confiável e ausência de futuro indevido; duração medida monotonicamente.
4. Medição recente, dentro de `freshness_s`, com execução no slot permitido.
5. Família IP e capacidade correspondentes; sem fallback entre famílias.
6. Outcome `pass`/`fail`, e integridade `valid` no escopo que está sendo avaliado.
7. Para upstream, validade do perfil/evidência de caminho conforme documento 05.

Resultados não elegíveis continuam armazenados com motivo. Eles não somam sucesso, falha, recuperação ou flap. A expiração deve ser avaliada por tempo, mesmo quando não chegam novas amostras; não depender de outra ingestão para retirar um verde antigo.

`invalid` exige evidência positiva de violação, como saída observada diferente da prevista. `unknown` indica falta de evidência necessária. `suspect` indica que controles/contexto tornam a conclusão insegura sem provar violação. Falha de consulta a um contador não equivale a valor zero.

### 4. Confirmação por perspectiva

A unidade de estado é a verificação em uma perspectiva e geração do assignment. A revisão do pacote de configuração da sonda não reinicia assignments inalterados. Ordenar por instante agendado, não por ordem de recebimento.

- Inicialização: estado `unknown` até a sequência inicial de confirmações válidas.
- `failure_count` falhas válidas em slots consecutivos confirmam `down`.
- `recovery_count` sucessos válidos consecutivos confirmam `up` ou recuperação.
- Amostra ausente/inválida ou troca de geração interrompe a sequência. Não contar três falhas espaçadas como três consecutivas.
- Antes da confirmação, manter o último estado confirmado somente se ainda elegível e expor `pending_failure`/`pending_recovery`. A UI deve escrever “falha em confirmação”/“recuperação em confirmação”, nunca apenas um verde.
- Erro de integridade ou incapacidade de medir torna a perspectiva corrente `unknown`; o último estado confirmado permanece histórico.
- Perda de dados não resolve um incidente já aberto.

Degradação de desempenho usa limites explicitamente configurados para o par verificação/perspectiva e sequência de confirmação própria. Não criar limite universal de latência. Percentual de falhas HTTP não é perda de pacotes. Perda ICMP só será calculada com contagens reais e amostragem mínima publicada; na ausência dela, mostrar “amostragem insuficiente”.

### 5. Cobertura, diversidade e quórum

Cada assignment pertence a um domínio de observação configurado. Não criar votos adicionais por reiniciar uma sonda ou duplicar uma VM no mesmo domínio. O cadastro registra provedor, ASN, região e dependências conhecidas; isso é diversidade documentada, não prova de independência física total.

Política externa inicial: três perspectivas previstas e mínimo de dois domínios elegíveis para uma conclusão agregada. Política interna/de caminho pode ter mínimo de um, explicitamente restrita àquela observação. Não aplicar quórum externo de três ao teste de um único caminho interno.

Se duas sondas no mesmo domínio discordarem, preservar as duas observações; esse domínio não fornece voto unânime de sucesso/falha. Mostrar conflito e não usá-lo para completar um quórum de queda abrangente. Não reduzir automaticamente o mínimo quando sondas desaparecem.

**Cobertura completa:** todas as perspectivas previstas possuem evidência recente e elegível.  
**Reduzida:** parte está ausente/inapta, mas o mínimo configurado permanece satisfeito.  
**Insuficiente:** o mínimo não é satisfeito.

Além das contagens de sondas, exibir domínios válidos/previstos. Comparar confirmações em janelas temporais sobrepostas: uma confirmação antiga e outra atual não criam consenso. A evidência da confirmação deve guardar início/fim da sequência; o avaliador só usa intervalos de sustentação atuais e sobrepostos, com tolerância de agenda explicitamente limitada pelo perfil.

#### 5.1 Tabela de decisão externa

Cenário com três perspectivas previstas de domínios distintos, após confirmação por perspectiva:

| Evidência corrente | Saúde/abrangência agregada | Cobertura |
|---|---|---|
| 3 sucessos | `up / none` | `full` |
| 2 sucessos + 1 falha | `degraded / partial` | `full` |
| 1 sucesso + 2 falhas | `degraded / partial` | `full` |
| 3 falhas | `down / all_observed` | `full` |
| 2 sucessos + 1 ausente | `up / none`, limitada às 2 observadas | `reduced` |
| 2 falhas + 1 ausente | `down / all_observed`, explicitamente “2 de 3” | `reduced` |
| 1 sucesso + 1 falha + 1 ausente | `degraded / partial` | `reduced` |
| 1 resultado elegível + 2 ausentes/inválidos | `unknown / unknown`; mostrar o resultado individual | `insufficient` |
| 0 resultados elegíveis | `unknown / unknown` | `insufficient` |

Uma falha isolada confirmada pode gerar alerta de alcance parcial; não precisa ser ignorada por não haver duas falhas. O quórum limita conclusões abrangentes, não a visibilidade das evidências.

IPv4 e IPv6 são agregados separadamente. O resumo de um serviço dual-stack não deve dizer “operacional” sem ressalva se uma família obrigatória falhou. Checks obrigatórios/auxiliares terão papel explícito: falha de certificado futuro gera risco, não a mesma classificação de uma conexão recusada. Na dúvida, mostrar dimensões e não reduzir tudo a um semáforo.

### 6. Controles das sondas e integridade

Cada sonda mede controles autorizados e independentes dos ativos Blue3: pelo menos um por IP literal e um por nome; quando viável, dois provedores/destinos diferentes. A saúde desses controles também tem frescor e sua própria evidência.

- Controle por nome falha e por IP funciona: suspeita de resolução; não invalidar automaticamente todos os testes por IP literal.
- Todos os alvos e controles de conectividade de uma sonda falham: reduzir confiança daquela perspectiva, preservar todas as falhas e abrir problema de monitoramento/origem. Não declarar o provedor inteiro fora apenas por essa coincidência.
- Controles respondem e alvos falham: evidência fortalece o diagnóstico de falha nos alvos, sem demonstrar causa raiz.
- Um controle falha em diversas origens, outros controles funcionam: tratar possível falha do controle; não invalidar toda a plataforma.

Não existe condição circular “o alvo caiu, então a sonda é inválida”. A inelegibilidade precisa de motivo independente identificável e ser aplicada somente às medições afetadas. Controles sem evidência suficiente tornam a confiança `unknown`/`suspect`, não comprovam que a VM morreu.

### 7. Incidentes e anti-flap

Classes iniciais: `service`, `upstream_path`, `monitoring_coverage`, `monitoring_integrity`, `platform` e `job_heartbeat`. Uma classe pode agrupar eventos correlacionados sem apagar evidências de outras classes.

O incidente contém `observed_start_at`, `confirmed_at`, escopo/família, gravidade, estado técnico, reconhecimento, responsável, fim observado, fim confirmado e linha do tempo. Início observado e instante de detecção são diferentes.

A chave de correlação usa escopo, regra/família e classe, não só o alvo IP. Eventos consecutivos do mesmo episódio reutilizam identidade. Reconhecimento não muda o estado técnico. Não permitir “fechar como resolvido” uma falha ainda confirmada sem que permaneça claramente sinalizada; no MVP a recuperação técnica é automática por evidência.

#### 7.1 Política de oscilação inicial

- Recaída dentro de `reopen_window_s` reabre o mesmo episódio e registra nova transição.
- Quatro transições técnicas confirmadas em dez minutos marcam `unstable` no perfil inicial.
- A entrada em instabilidade gera notificação própria deduplicada; não é silêncio permanente.
- Saída da instabilidade exige `stable_recovery_count` sucessos válidos consecutivos, maior que a recuperação comum.
- Instabilidade não mascara queda: saúde `down` e estabilidade `unstable` podem coexistir.
- Lacunas/medições inválidas não alimentam contagem de flap nem estabilidade.

A correlação por dependência só suprime avisos secundários quando a regra é explícita e auditável. O MVP inclui grupos simples de redundância e agrupamento; não inferir causa por IA ou por coincidência temporal.

#### 7.2 Integridade persistente e quarentena lógica

A primeira contradição comprovada invalida imediatamente aquela observação e fica visível. Após a sequência configurada, a verificação pode entrar em quarentena lógica para impedir votos/alertas de saúde baseados no instrumento defeituoso.

A coleta continua. O incidente de integridade permanece visível e seus avisos são deduplicados; não silenciar o defeito junto com o ruído de saúde. Não manter último verde como situação atual.

A saída exige três execuções consecutivas com integridade válida. Essas execuções podem ter outcome `fail`: instrumento consertado não significa link recuperado. Após recuperar integridade, reavaliar a sequência recente de resultados válidos; só resultados `pass` contam para saúde/estabilidade positiva. Um contador bom isolado não retira a quarentena.

### 8. Redundância

Grupos simples definem membros, quantidade mínima requerida e quantidade desejada de caminhos válidos. Não derivar saúde do upstream pelo resultado de serviços externos.

Exemplo A/B com mínimo 1 e desejado 2: A falha de forma válida, B funciona → redundância reduzida. A desconhecido e B funciona → redundância não confirmada, com conectividade observada via B. Ambos falham de forma válida → caminhos observados indisponíveis. Não inferir impacto em todos os clientes sem medições desses serviços.

A classificação de redundância é condicionada ao que foi realmente cadastrado e medido; não inventar um circuito de backup a partir de resposta pública.

### 9. Replay: histórico, atualidade e alertas

#### 9.1 Identidade e reordenação

Chave natural de resultado agendado: `(probe_id, assignment_id, config_generation, scheduled_at)`. Cada assignment fixa família e caminho. O resultado possui também `result_id` estável, gerado antes da execução e persistido localmente.

Reenvio do mesmo conteúdo retorna duplicado aceito. Mesma chave com conteúdo incompatível gera conflito auditável e não sobrescreve a observação original. Tentativas internas de uma execução não criam resultados conflitantes: formam evidências do mesmo resultado final. Testes manuais têm identidade/tipo distintos e não entram por padrão nas sequências agendadas.

#### 9.2 Três janelas diferentes

1. **Buffer local:** quanto a sonda consegue reter sem central.
2. **Aceitação histórica:** idade máxima de resultado que a central aceita e pode reconciliar.
3. **Frescor:** idade máxima para determinar a situação presente.

Elas não são iguais. Um resultado de uma hora pode reconstruir uma falha histórica, mas não confirmar o estado atual de um teste de 30 segundos. Uma medição recente que veio no mesmo replay pode ser elegível para o presente. O buffer de duas horas não impõe frescor de duas horas.

#### 9.3 Processamento da reconexão

Separar envio corrente de backlog; priorizar dados atuais para não bloquear a situação presente por horas de histórico. O backlog usa sessão/manifesto de replay com faixa e quantidade esperada. Persistir lotes sem disparar avisos intermediários das observações históricas.

Após receber uma faixa completa, reordenar, recompor incidentes afetados e reconciliar com o snapshot corrente. Usar políticas e configurações vigentes no horário observado, não aplicar retroativamente uma regra nova.

Sessão incompleta não segura os dados correntes indefinidamente: após timeout, marcar histórico pendente/lacuna, manter alertas baseados na faixa corrente completa e continuar reconciliação histórica quando o restante chegar. Nunca abrir e fechar alerta crítico artificialmente por páginas intermediárias do replay.

Eventos que se sobrepõem a um episódio já conhecido mantêm sua identidade. Se a reconstrução unir episódios, conservar um ID canônico e aliases auditáveis; não apagar reconhecimentos, comentários nem tentativas de envio.

#### 9.4 Decisão de notificação após reconciliação

| Situação após ordenar/reconciliar | Comportamento |
|---|---|
| Incidente ocorreu e terminou inteiramente no passado | Histórico/resumo informativo; não push crítico de queda atual |
| Incidente continua aberto, com evidência recente, nunca avisado | Uma intenção consolidada de alerta |
| Mesmo problema já avisado e sem mudança material | Atualizar linha do tempo; não repaginar |
| Problema já avisado, mas agravamento atual relevante | Um aviso consolidado de agravamento |
| Só há evidência antiga e nenhuma cobertura atual | Mostrar situação desconhecida; alertar falta de cobertura, não inventar queda atual |
| Recuperação atual confirmada de incidente previamente avisado | Uma intenção de recuperação, conforme política |

Persistir mudança de estado, versão da decisão e outbox na mesma transação. `last_notified_state` isolado não resolve: registrar estado/abrangência/gravidade já decididos e tentativas/aceites por canal. Usar unicidade de intenção `(incident_id, decision_version, reason, channel, recipient)`.

O envio externo é pelo menos uma vez quando o provedor não oferece idempotência. Um timeout após aceite remoto pode ocasionar duplicata de entrega; registrar essa limitação em vez de prometer “exatamente uma vez”. O produto garante decisão lógica deduplicada e retentativas controladas.

### 10. Manutenção, silêncio e relatórios

Manutenção exige início, término, autor, justificativa e escopo. A coleta continua. A política de notificação consulta a janela; a decisão técnica não é reescrita. Não permitir excluir canário/vigilante por manutenção de ativos.

No MVP, não disponibilizar edição retroativa para melhorar indicadores. Correção histórica excepcional exige registro separado, justificativa, aprovador distinto e preservação da visão original; pode ser tratada por procedimento controlado antes de existir UI específica.

Relatórios devem publicar dois eixos: condição administrativa (dentro/fora de manutenção) e qualidade da observação (elegível, sem cobertura suficiente ou integridade comprometida). Dentro do tempo elegível, mostrar duração `up`, `degraded`, `down`. Assim manutenção e falta de cobertura não são somadas duas vezes inadvertidamente.

Indicador técnico estrito sugerido: `duração up / duração elegível não excluída`. Mostrar ao lado fração elegível sobre período total, tempo em manutenção, desconhecido e comprometido, inclusive suas interseções quando existirem. Degradação não é automaticamente contada como saúde estrita. Denominador zero resulta “sem observação suficiente”, não 100%. Não vender o indicador como SLA contratual.

### 11. Relógio e mudanças de configuração

Persistir em UTC e apresentar inicialmente em America/Sao_Paulo. Durações usam relógio monotônico. Sonda e central reportam sincronização contra referência externa configurada; concordar entre si não basta.

Resultados com offset fora da tolerância, sincronização desconhecida ou timestamp futuro indevido são preservados, mas não têm voto temporal corrente até validação. Não corrigir timestamps históricos silenciosamente. Mudança de política/caminho/agenda gera revisão com vigência; não misturar sequências de gerações incompatíveis.

### 12. Parâmetros iniciais de homologação — DE-03

| Parâmetro | Inicial | Observação |
|---|---|---|
| Intervalo crítico | 30 s | DNS/HTTP/ICMP/TCP conforme capacidade |
| Intervalo comum | 60 s | Não é frequência universal |
| Timeout crítico | 5 s | Deve ser menor que intervalo |
| Falhas / recuperações | 3 / 3 | Slots consecutivos válidos |
| Frescor crítico | 90 s | Referência: horário agendado confiável |
| Frescor de outros perfis | 3 × intervalo | Ajustar explicitamente por tipo |
| Jitter | Até ±10% do intervalo | Offset determinístico do assignment, sem deslocar identidade do slot |
| Tolerância de início | Até 20% do intervalo | Slot fora disso é skipped; documentar motivos |
| Heartbeat sonda / perda de comunicação | 15 s / 60 s | Não prova VM desligada |
| Offset máximo | 2 s | Ponto de partida a validar com NTP e carga |
| Mínimo externo de domínios | 2 | Não diminuir automaticamente |
| Buffer local alvo | 2 h | Também limitado em bytes; homologar tamanho |
| Validade da configuração offline | 24 h | Revogação offline só alcança a sonda ao reconectar ou expirar |
| Aceitação histórica máxima | 7 dias | Não equivale ao frescor nem à retenção de incidentes |
| Deduplicação mínima | 14 dias | Sempre maior que aceitação histórica e retries autorizados |
| Timeout inicial de reconciliação incompleta | 60 s | Não bloquear faixa corrente |
| Reabertura do mesmo episódio | 10 min | Maior que confirmação normal |
| Entrada em instabilidade | 4 transições em 10 min | Confirmadas e válidas |
| Saída de instabilidade | 6 sucessos | Válidos e consecutivos |
| Quarentena lógica / saída | 3 inválidas / 3 íntegras | Última inválida já não pode produzir verde |
| Verificação de vencimento TLS | 24 h | HTTPS continua validando TLS em cada execução |
| Alertas TLS | 30, 14 e 7 dias | Pontos de partida; vencido é condição distinta |

Validade da prova de caminho, limite de disco, limites de qualidade e heartbeat de jobs são definidos pelo perfil operacional, não por número universal. Campos sem valor seguro devem impedir ativação daquele check, não impedir desenvolvimento dos demais.


---

<a id="doc-04-sondas-e-execucao"></a>

**Arquivo de origem desta seção:** `04_SONDAS_E_EXECUCAO.md`

## 04 — Sondas e execução

**Versão:** 1.0 · 28/09/2026  
**Natureza:** RC-01/02/04/06/10/14; mecanismo de agenda/spool é DE-05.

### 1. Ciclo da sonda

Inicializar identidade e política local → verificar capacidades → carregar última configuração válida → reconciliar agenda → persistir execução planejada → executar teste permitido → persistir resultado final → transmitir → remover do spool somente após confirmação durável/decisão terminal registrada.

O coordenador não exige uma chamada da central a cada teste. A central não acessa um shell da sonda. O processo deve continuar medindo durante uma indisponibilidade temporária da central, dentro da validade e dos limites locais.

### 2. Identidade e ativação

Cada instalação recebe identidade individual. O cadastro depende de aprovação. Credenciais de bootstrap são de uso único e expiram; credencial permanente fica vinculada àquela sonda e aos endpoints necessários. Um clone de disco deve receber nova identidade, não apresentar-se simultaneamente como a mesma sonda.

Configuração remota é versionada, autenticada, validada e aplicada atomicamente. Registrar `bundle_revision` do conjunto, `config_generation` por assignment, hash, recebimento, vigência e expiração. Manter a geração de assignments que não foram alterados. Um erro não destrói a última configuração válida; ela permanece até seu prazo. Nunca reativar automaticamente uma revisão expirada.

Identidade, spool e configuração persistem em reinícios. Processo deve possuir exclusão mútua para impedir dois coordenadores executarem a mesma agenda com a mesma identidade. Detectar e reportar identidade duplicada/conflitos na central.

### 3. Agenda

A agenda usa slots absolutos `schedule_epoch + n × interval`. O slot integra a chave natural da medição. O offset de jitter é determinístico por assignment e geração; o horário real de início é registrado separadamente. Quando houver jitter negativo, a antecipação prevista não é erro de relógio: validar `scheduled_at` contra o slot da configuração e `started_at` contra a tolerância do jitter/relógio. Rejeitar timestamps futuros além desses limites, sem rejeitar a antecipação autorizada.

Timeout, tolerância de início, concorrência global e por destino/caminho são limitados. Perder um slot não autoriza executar todos os testes perdidos em rajada nem fingir que foram executados no passado. Registrar `skipped`/lacuna e seguir para o próximo slot válido.

Reinício entre agendamento e conclusão requer reconciliação local. Se não é possível saber se a execução terminou, emitir erro de execução com a identidade original, sem fabricar sucesso. Um resultado já finalizado é imutável e nunca é reexecutado com o mesmo ID.

Evitar sobreposição da mesma verificação. Perfis de caminho com mecanismos sensíveis a concorrência exigem isolamento próprio; não serializar toda a sonda por conveniência. Retentativas de transporte não repetem o probe. Retentativas internas do protocolo devem ser explicitadas no resultado, respeitar timeout total e não inflar as sequências de confirmação.

### 4. Contrato com o executor

O coordenador passa uma configuração normalizada e destino validado a um executor local. O retorno precisa distinguir resultado do alvo, erro de execução, capacidade indisponível e erro de configuração.

Ao usar Blackbox Exporter, o coordenador o consulta localmente, coleta/normaliza a resposta e associa à execução original. Blackbox não faz agenda, buffer, incidentização nem envio de resultados do produto. Não publicar sua porta na rede externa; configuração e módulos locais não podem permitir uma segunda via para sondar destinos proibidos.

O adaptador deve preservar família IP, destino efetivamente conectado, Host/SNI quando aplicável, tempos, status/asserção e erro seguro. Se o executor não expõe a evidência necessária, o contrato exige instrumentação complementar ou classifica a dimensão como não observada. Não preencher o IP efetivo com o primeiro resultado de DNS por aproximação.

### 5. Protocolos do MVP

| Protocolo | Campos e cuidados mínimos |
|---|---|
| HTTP/HTTPS | Método seguro, URL permitida, status aceito, asserção limitada, Host/SNI e conexão ao IP autorizado; redirects desativados inicialmente |
| API JSON | Caminho de campo e comparação declarativos; sem `eval`, scripts ou código remoto |
| TCP | Host/IP, porta autorizada, família, conexão e tempo |
| ICMP | Quantidade enviada/recebida, RTT e capacidade verificada; não chamar uma falha de uma amostra de “perda média” |
| DNS | Servidor explícito, transporte/endereço da consulta, tipo do registro e resposta esperada |
| TLS | Cadeia/hostname/validade e vencimento; não desabilitar validação para um resultado passar |
| Heartbeat externo | Tipo de ingestão do job, não executor de rede e não confundido com a sonda |

A família de transporte de uma consulta DNS e o tipo de registro são dimensões distintas: consultar AAAA por IPv4 não demonstra conectividade IPv6. Quando necessário, criar verificações separadas para os dois transportes.

Serviço sem IPv6 previsto é não aplicável. Sonda sem IPv6 para um check que exige IPv6 é incapacidade/cobertura; é proibido cair para IPv4. IPv6 deve funcionar no host, no ambiente do executor e até destinos autorizados, não apenas estar habilitado em um arquivo.

### 6. Spool persistente e backpressure

Guardar envelope, resultado, hash, estado de envio e tentativas em SQLite local, ou substituto transacional justificado. Testar encerramento abrupto, reinício e falta de disco. Reservar espaço para eventos de perda/saúde; logs não podem consumir toda a área do spool.

O limite é simultaneamente temporal e em bytes. A meta inicial é reter duas horas na carga homologada; o tamanho em disco será medido. Ao atingir limite, priorizar entrega de dados correntes e eliminar os mais antigos somente por política explícita. Registrar faixa, quantidade descartada e motivo. Descarte não vira sucesso nem “sem falhas”.

Enviar lotes limitados com backoff exponencial e jitter. Respeitar `Retry-After`; não sobrecarregar uma central recém-recuperada. Uma rejeição definitiva deve ficar em diagnóstico/quarentena local com limite, para não bloquear o restante da fila indefinidamente.

Estados por item: `accepted`, `duplicate`, `retryable`, `rejected_terminal`, `conflict`. Confirmar por item, inclusive em lote parcialmente aceito. HTTP 200 do lote não autoriza apagar todos os itens. A aceitação exige commit durável na central.

### 7. Reenvio organizado

A sonda distingue fluxo corrente de backlog e comunica sessão/manifesto do backlog. O cursor de replay é persistente e retomável. Faixa completa e quantidade esperada permitem à central reconciliar sem disparar por páginas intermediárias.

A central pode solicitar retentativa, mas não mudar o conteúdo de um resultado já finalizado. O histórico tem política própria de aceitação; expurgo da tabela de deduplicação não pode permitir reintroduzir resultados muito antigos como atuais.

Mudança de configuração durante desconexão preserva a geração de cada resultado. Resultados da geração antiga continuam ligados a ela; nunca são reinterpretados como execução da nova configuração.

### 8. Autodiagnóstico e controles

O heartbeat da sonda informa versão/build, revisão aplicada, hash da política local, uptime, última execução, atraso de agenda, tamanho/idade do spool, descartes, disco disponível, capacidades IPv4/IPv6/ICMP e estado do relógio.

Controle de conectividade deve incluir IP literal e nome, em destinos autorizados que não dependam todos da mesma rede da sonda. Manter estado por família e por dependência (DNS, conexão e aplicação). Falha de controle isolado não invalida tudo automaticamente.

O horário é aferido contra referência externa configurada, não apenas contra a central. Registrar método, sincronização e offset; desconhecido não pode aparecer como zero. Medir duração por relógio monotônico.

### 9. Segurança aplicada na borda

A política local define redes, protocolos, portas, tipos de teste, redirecionamentos e referências de segredo permitidos. A configuração recebida é a interseção dessa política com as atribuições da central, nunca sua substituição.

Destinos devem ser resolvidos e validados antes de conectar; a conexão precisa usar o endereço autorizado, sem segunda resolução não controlada pelo executor. Revalidar quando houver mudança de DNS, novo destino ou redirect permitido. HTTP conserva hostname/SNI necessário sem mudar a seleção de IP validado.

Destinos privados de gerenciamento são permitidos somente por configuração local específica. Bloquear metadata, loopback e link-local não autorizados. Um executor local não pode ser acionado por usuários externos para contornar o coordenador. Detalhes no documento 07.

### 10. Privilégios e atualização

Rodar sem root quando possível e sem container privilegiado. A estratégia de ICMP deve ser testada: sockets não privilegiados ou capacidade estritamente necessária, conforme executor/host. Não conceder `NET_ADMIN` ou socket Docker para resolver atalhos de configuração.

Atualizações são controladas, versionadas e reversíveis. O MVP não precisa de auto-update comandado pela central. Binário/imagem e política local só mudam pelo mecanismo administrativo da operação. Não aceitar plugins, scripts ou binários arbitrários no payload de configuração.

### 11. Pronto para homologar a sonda

Demonstrar agendamento local sem central; perda e recuperação de conexão; rejeição de destino proibido; destino efetivo auditável; IPv6 real; erro do executor distinto de falha do alvo; spool após crash; descarte explícito ao esgotar disco; rotação/revogação; replay sem duplicação; capacidade de retornar à versão anterior.

Não marcar como homologada uma capacidade que só foi implementada em mock ou que o host de testes não suporta.


---

<a id="doc-05-upstreams-e-prova-de-caminho"></a>

**Arquivo de origem desta seção:** `05_UPSTREAMS_E_PROVA_DE_CAMINHO.md`

## 05 — Upstreams e prova de caminho

**Versão:** 1.0 · 28/09/2026  
**Natureza:** RC-08/09/18; mecanismo concreto é DO-NOC, não decisão fechada pelos debates.

### 1. O que o MVP deve entregar

Identificar falha no caminho do upstream A mesmo quando B mantém os serviços públicos acessíveis. A conclusão será sobre o **caminho medido**, com origem, família IP, destinos, validade da evidência e limitações explícitas.

O MVP não precisa de telemetria rica de BGP nem suporte universal a equipamentos. Pode precisar de leitura mínima de rota/interface/política para sustentar sua prova. Essa leitura não deve ser artificialmente adiada se for indispensável.

Sem validação real, a aplicação continua útil para acessibilidade externa, mas o caminho aparece “não homologado”. Um resultado de laboratório não pode mudar o status de validação produtivo.

### 2. Perfil obrigatório por caminho

| Grupo | Informação exigida |
|---|---|
| Identificação | Nome do circuito/upstream, POP, responsável e família IP |
| Origem | Sonda, interface/namespace/VRF se aplicável, IP de origem e eventual NAT |
| Borda | Fabricante, modelo, versão real e acesso somente leitura autorizado |
| Encaminhamento | Quem impõe a saída, regra/tabela/VRF, gateway e interface esperada |
| Isolamento | Como se impede consulta/fallback para o backup, inclusive rotas recursivas/default |
| Destinos | Pelo menos dois alvos autorizados em redes distintas e como verificar sua própria saúde |
| Evidência | Método, escopo, limitações, intervalo de revalidação e prazo de validade |
| Mudança | Revisão/hash da configuração pertinente, data e responsável pela homologação |
| Segurança | Segredos referenciados localmente e operações permitidas |
| Reversão | Plano para desfazer o arranjo de teste sem afetar clientes |

Não pressupor existência de VRF, NAT específico, endereços dedicados ou plataforma MikroTik. Esses campos precisam ser preenchidos pelo inventário real.

Estados do perfil: `draft`, `lab_verified`, `noc_validated`, `suspended`. A homologação é por topologia, versão e família IP; validar IPv4 não homologa IPv6 automaticamente.

### 3. Exigência de evidência, não receita universal

O perfil deve demonstrar duas propriedades: **seleção do caminho esperado** e **ausência de fallback silencioso**. A implementação precisa manter evidência suficiente de que essas propriedades ainda valem; um teste aprovado no primeiro dia não certifica configurações futuras.

Podem ser usados, conforme o ambiente, isolamento de rede, política de encaminhamento restrita, leitura de configuração/rota, observação do encaminhamento efetivo ou captura limitada em janela. O agente deve propor o menor mecanismo confiável suportado pelo equipamento real e submetê-lo ao teste positivo e aos testes de falha.

Se não for possível observar continuamente todo pacote, documentar o que é atestado em cada medição e o que é revalidado periodicamente. O produto exibirá esse alcance e a validade da evidência. Evidência vencida, contraditória ou insuficiente impede confirmação de saúde corrente daquele caminho.

#### 3.1 Evidências insuficientes isoladamente

- IP de origem devolvido por endpoint de eco: identifica a origem vista, não comprova interface de saída.
- Incremento de regra que apenas marca pacotes: pode ocorrer antes da seleção efetiva da rota.
- BGP estabelecido: não substitui o teste do plano de dados.
- Ping por rota padrão: pode ter saído pelo backup.
- AS-path ou traceroute isolado: evidência complementar com limites, não prova absoluta de todo encaminhamento físico.
- “API do roteador respondeu”: não comprova a política em vigor.

#### 3.2 Contadores, quando escolhidos

Não transformar `delta == N` em requisito universal do produto. Se forem usados contadores, o adaptador precisa demonstrar unidade (pacotes/bytes), ponto de coleta, exclusividade, correlação com a execução, comportamento com concorrência, reset/wrap, fragmentação, offload/FastTrack e ausência de leitura.

O controle positivo deve demonstrar que o método vê tráfego legítimo. Uma leitura vazia/ausente não é delta zero. Um contador compartilhado não identifica sozinho o fluxo. Não criar regras dinâmicas por teste em produção como atalho, pois isso viola o caráter somente leitura do agente.

Qualquer método baseado em contador só é aprovado para a topologia em que foi ensaiado. O backend recebe classificação/evidência tipada do adaptador; não codifica uma regra matemática única para todos os fabricantes.

### 4. Separar vínculo de caminho, encaminhamento e resposta

O resultado deve distinguir:

1. **Vínculo válido:** há evidência suficiente de que a execução está restrita ao caminho declarado, sem fallback.
2. **Encaminhamento observado:** pacote visto na saída esperada, bloqueado pela política esperada, saída divergente ou dimensão não observada.
3. **Resultado de alcance:** destino respondeu/não respondeu e qual critério foi aplicado.

| Vínculo/evidência | Resposta | Interpretação |
|---|---|---|
| Válido e sem contradição | Sucesso | Sucesso do caminho para aquele destino; não comprova todos os destinos |
| Válido e encaminhamento esperado observado | Falha | Falha de alcance pelo caminho; preservar possível falha do destino/retorno |
| Válido, isolamento impede saída porque a rota esperada está indisponível | Falha | Caminho indisponível nesse ponto, com evidência do bloqueio; não inventar que pacotes saíram |
| Saída por outro upstream comprovada | Qualquer | Integridade inválida; não concluir saúde/queda do upstream original |
| Evidência necessária ausente/vencida | Qualquer | Dimensão não observada, saúde do caminho desconhecida |
| Executor falhou antes de medir | Ausente | Erro de execução, não falha do link |

Uma falha do caminho não identifica automaticamente a responsabilidade do fornecedor. O retorno, os destinos e a própria borda continuam sendo possíveis fatores. A mensagem deve ser “falha observada no caminho A”, não “operadora A culpada”.

### 5. Destinos de referência

Não usar um único eco como oráculo de todos os upstreams. Cadastrar pelo menos dois destinos controlados/autorizados em redes distintas, com verificação de sua saúde por outras perspectivas.

O sucesso em um e a falha em outro indicam alcance parcial, não queda total do upstream. Falha de um destino demonstrada por perspectivas independentes abre problema do destino/controle e reduz a cobertura do teste de caminho; não apaga os resultados originais.

Se os dois destinos falharem sem evidência de que estejam saudáveis por outros caminhos, não há base suficiente para atribuição abrangente ao upstream. Mostrar falha observada para os destinos e a limitação. Quando a evidência das referências é indisponível, não manter um verde antigo.

A agregação de destinos é uma política explícita do perfil, separada do quórum externo de sondas. Registrar quantidade de destinos elegíveis/previstos.

### 6. Ida e volta

O escopo mínimo exige saída definida sem fallback. O retorno pode ser assimétrico e isso precisa acompanhar a evidência. Controlar o retorno é uma evolução especializada, não condição universal do MVP.

Não exigir `/24` exclusivo, anúncio BGP de teste, ROA/IRR ou retirada de prefixo para todos os pilotos. Caso o NOC escolha um experimento que dependa disso, elaborar plano específico, verificar dependências reais e obter autorização separada. Não fazer essas mudanças com base em um debate textual.

### 7. Ensaios obrigatórios de Fase 0

Executar um cenário por vez, começando com instrumento e destinos saudáveis. Registrar estado anterior, ação, evidências, resultado esperado e reversão. Usar laboratório/ambiente isolado ou janela autorizada.

| ID | Ensaio | Evidência exigida |
|---|---|---|
| UP-01 | Controle positivo A e B | Método reconhece o caminho correto e teste real passa |
| UP-02 | Falha de A com B mantendo serviço | A falha ou fica sem alcance conforme evidência; não obtém sucesso silencioso via B; serviço público pode continuar normal |
| UP-03 | Política principal de A sem rota válida | Não consulta B como alternativa; falha de alcance/bloqueio coerente |
| UP-04 | Desvio da seleção para tabela/saída padrão de backup | Em laboratório, sucesso pela saída errada é detectado como integridade inválida, nunca saúde de A |
| UP-05 | Um destino de referência falha | Problema da referência/alcance parcial, não queda automática de todos os upstreams |
| UP-06 | Evidência de encaminhamento não chega | Dimensão desconhecida, não contador zero nem verde |
| UP-07 | Mudança posterior relevante de configuração | Perfil é invalidado ou revalidado por evidência; sem certificado eterno |
| UP-08 | Recuperação do instrumento | Exige sequência íntegra; resultado do link continua podendo ser falha |
| UP-09 | Mesmos casos na outra família IP prevista | Sem mascaramento por fallback IPv4/IPv6 |

Se o desenho torna um modo de falha impossível por construção, demonstrar essa propriedade e ensaiar o bypass equivalente no laboratório. Não provocar defeitos no tráfego de clientes para cumprir literalmente o cenário.

### 8. Critério de liberação

Um caminho só pode ser mostrado como monitoramento homologado quando: inventário preenchido; desenho revisado pelo NOC; mecanismo de evidência implementado; testes relevantes aprovados; limitações registradas; validade/revalidação operantes; credenciais somente leitura; procedimento de reversão demonstrado.

Sem isso, registrar bloqueio DO no documento 13. O resto do aplicativo pode seguir em desenvolvimento. O rótulo de produção não pode ser liberado por fixture, contador simulado ou resposta de eco isolada.


---

<a id="doc-06-modelo-de-dados-e-contratos"></a>

**Arquivo de origem desta seção:** `06_MODELO_DE_DADOS_E_CONTRATOS.md`

## 06 — Modelo de dados e contratos

**Versão:** 1.0 · 28/09/2026  
**Natureza:** DE-06; desenho inicial necessário à implementação. Não é descrição de API já existente.

### 1. Convenções

API HTTP em `/api/v1`; JSON UTF-8; timestamps RFC 3339 em UTC; durações em milissegundos; percentuais de 0 a 100 quando nomeados como porcentagem; IDs opacos. O backend determina `received_at` e `processed_at`. Campos sensíveis não entram em snapshots públicos/mobile.

Exemplos abaixo são fictícios: `.example` e endereços de documentação não são destinos produtivos. O agente deve gerar schemas executáveis e OpenAPI real em `contracts/`, testados contra os produtores e consumidores. Os exemplos deste arquivo não substituem validação formal.

Resultados são imutáveis. Projeções de estado e incidentes são derivados versionados. Um atributo não observado usa `null` ou status explícito; nunca valor zero inventado.

### 2. Entidades e relações mínimas

| Entidade | Campos/invariantes principais |
|---|---|
| `users`, `roles`, `sessions` | Papéis, 2FA, revogação; autenticação distinta da sonda |
| `asset_groups`, `locations`, `assets` | Identidade amigável, tipo, criticidade, responsável, etiquetas |
| `checks`, `check_revisions` | Protocolo, destino, família IP, asserções, papel obrigatório/auxiliar, vigência |
| `probes`, `probe_credentials` | Identidade, origem, capacidades, domínio de observação, status e rotação |
| `assignments`, `assignment_generations` | Check + probe + agenda + perfil + caminho; revisão imutável e vigência |
| `state_policies`, `policy_revisions` | Confirmação, frescor, quórum, limites e anti-flap |
| `path_profiles`, `path_validations` | Desenho, revisão, método de evidência, validade, aceite do NOC |
| `results` | Payload normalizado imutável, chave natural, horários e origem |
| `result_receipts` | Ledger de idempotência global com hash, chave natural e retenção explícita |
| `ingestion_work` | Trabalho durável pendente/feito; reconciliação após perda de Redis |
| `replay_sessions`, `replay_parts` | Manifesto, faixas, quantidade, progresso e reconciliação |
| `current_states`, `state_events` | Snapshot atual e transições com política/evidência |
| `incidents`, `incident_events` | Episódio canônico, escopo, gravidade, recuperação, reconhecimento e aliases |
| `notification_outbox`, `notification_attempts` | Intenção transacional, dedup, tentativas e aceite externo |
| `maintenance_windows`, `silences` | Início/fim, autor, razão, escopo; sem término nulo |
| `devices` | Token push, usuário, plataforma e revogação |
| `audit_events` | Autor, ação, recurso, horário, diff sanitizado e correlação |
| `measurement_aggregates` | Durações por estado/cobertura/integridade e contagens; não só média de médias |
| `job_heartbeats` | Identidade do job, sequência, janela e evento; escopo separado da sonda |

O domínio inicial é uma única organização. Não adicionar tenancy comercial, billing ou catálogo SaaS. Preparar autorização por recurso sem construir outro produto.

### 3. Identidade, partição e aceitação durável

Chave natural de resultado agendado: `(probe_id, assignment_id, config_generation, scheduled_at)`. O ID de resultado também deve ser único. A família IP pertence ao assignment; alterar família exige nova geração/identidade compatível com o contrato.

Para garantir idempotência mesmo com `results` particionada por data, usar ledger de receipts com unicidade global e retenção própria. Não pressupor que um índice criado em uma partição deduplica todas as demais. Persistir receipt, resultado e item de trabalho na mesma transação.

`accepted` só é devolvido após commit. Se a conexão cair depois do commit, o reenvio recebe `duplicate`, sem novo incidente. Hash de conteúdo exclui metadados de transporte mutáveis e inclui os campos imutáveis da observação. Mesmo ID com outro conteúdo, ou mesma chave com outro resultado, retorna conflito e gera auditoria.

Resultados mais velhos que a janela de aceitação histórica são rejeitados de forma explícita. O ledger vive além dessa janela, incluindo margem para retries. Após expurgo, a regra de idade continua impedindo que resultados antigos sejam aceitos novamente. Configurações de retenção devem validar essa relação.

Eventos, auditoria e evidências resumidas de incidentes têm retenção independente das amostras. Guardar em cada incidente uma síntese suficiente para explicar a decisão mesmo depois do expurgo bruto.

### 4. Configuração enviada à sonda

```json
{
  "schema_version": "1.0",
  "probe_id": "33333333-3333-4333-8333-333333333333",
  "bundle_revision": 3,
  "issued_at": "2026-09-28T14:00:00Z",
  "expires_at": "2026-09-29T14:00:00Z",
  "assignments": [
    {
      "assignment_id": "44444444-4444-4444-8444-444444444444",
      "config_generation": 3,
      "check_id": "22222222-2222-4222-8222-222222222222",
      "kind": "http",
      "ip_family": "ipv4",
      "target": {
        "url": "https://portal.monitor.example/health",
        "method": "GET",
        "allowed_status_codes": [200],
        "follow_redirects": false
      },
      "schedule": {
        "epoch": "2026-09-28T14:00:00Z",
        "interval_s": 30,
        "jitter_offset_ms": 2000,
        "start_tolerance_ms": 6000,
        "timeout_ms": 5000
      },
      "policy_revision": "critical-v1",
      "path_profile_id": null,
      "credential_ref": null
    }
  ]
}
```

O `bundle_revision` versiona o conjunto entregue à sonda; `config_generation` versiona cada assignment. Alterar um check não deve reiniciar sequências dos assignments inalterados. A sonda calcula o hash da configuração validada e informa o aplicado. A configuração não inclui uma nova política local de redes: ela só seleciona operações já autorizadas. Segredos usam referências a um store local ou entrega cifrada restrita, nunca texto aberto em logs/exports.

### 5. Resultado normalizado

```json
{
  "schema_version": "1.0",
  "result_id": "55555555-5555-4555-8555-555555555555",
  "probe_id": "33333333-3333-4333-8333-333333333333",
  "assignment_id": "44444444-4444-4444-8444-444444444444",
  "config_generation": 3,
  "policy_revision": "critical-v1",
  "execution_kind": "scheduled",
  "scheduled_at": "2026-09-28T15:00:00Z",
  "started_at": "2026-09-28T15:00:02Z",
  "finished_at": "2026-09-28T15:00:02.120Z",
  "duration_ms": 120,
  "ip_family": "ipv4",
  "outcome": "pass",
  "reason_code": "http_assertions_passed",
  "actual_destination": {
    "ip": "203.0.113.10",
    "port": 443,
    "hostname": "portal.monitor.example"
  },
  "metrics": {
    "http_status": 200,
    "total_duration_ms": 120,
    "icmp_sent": null,
    "icmp_received": null
  },
  "clock": {
    "status": "synchronized",
    "offset_ms": 12,
    "reference_kind": "external_ntp"
  },
  "integrity": {
    "probe_assessment": "valid",
    "reason_codes": [],
    "local_policy_revision": "local-v1"
  },
  "path_evidence": {
    "status": "not_required"
  },
  "executor": {
    "name": "blackbox-adapter",
    "build": "lab-fixture"
  }
}
```

`probe_assessment` é relato da sonda; a central faz sua avaliação final de frescor, configuração, controles e autorização. Não confiar cegamente em `valid` recebido. O resultado não contém corpo HTTP completo por padrão, headers secretos nem credenciais. Asserções armazenam esperado/observado sanitizados e limitados.

Para caminho, `path_evidence` inclui perfil/revisão, método, `binding_status`, instante de aferição, validade, `forwarding_observation` e referências de evidência. O método concreto só será ativado após o documento 05. Não aceitar `not_required` em assignment que exige prova de upstream.

Os códigos de erro devem ser estáveis e distintos: `target_timeout`, `unexpected_status`, `assertion_failed`, `dns_unexpected_answer`, `executor_unavailable`, `family_unavailable`, `policy_denied`, `clock_untrusted`, `path_evidence_expired`, `path_mismatch`, `schedule_missed`. Nunca incluir mensagem de exceção com segredo no retorno comum.

### 6. Ingestão em lote

```json
{
  "batch_id": "66666666-6666-4666-8666-666666666666",
  "mode": "live",
  "replay_session_id": null,
  "results": []
}
```

O array vazio acima demonstra o envelope; envio produtivo exige de 1 a 500 resultados, respeitando também limite inicial de 4 MiB por requisição. Esses limites são DE ajustáveis por ensaio, não tamanho ilimitado. Não confiar em tamanho comprimido para prevenir expansão abusiva.

Resposta por item:

```json
{
  "batch_id": "66666666-6666-4666-8666-666666666666",
  "items": [
    {
      "result_id": "55555555-5555-4555-8555-555555555555",
      "status": "accepted",
      "received_at": "2026-09-28T15:00:03Z",
      "durable": true
    }
  ]
}
```

Status de item: `accepted`, `duplicate`, `retryable`, `rejected_terminal`, `conflict`, com `reason_code` quando necessário. Transporte pode responder 400/401/403/413/429/5xx antes de processar itens. O cliente não assume aceitação de itens sem confirmação. `Retry-After` orienta retentativa.

Replay usa manifesto com ID de sessão, probe, faixas por assignment/geração, contagem e hash de partes. Um endpoint de conclusão verifica integridade/completude, não apenas um booleano do cliente. Os detalhes do schema devem ser publicados antes de codificar os dois lados.

### 7. Endpoints a implementar

| Método e caminho | Principal autorizado | Função |
|---|---|---|
| `POST /auth/login`, `/auth/refresh`, `/auth/logout` | Usuário/sessão | Sessões e revogação; 2FA no fluxo correspondente |
| `GET /dashboard` | Usuário | Snapshot agregado com validade |
| `GET /assets`, `GET /assets/{id}` | Usuário | Consulta autorizada e paginada |
| `GET /assets/{id}/measurements` | Usuário | Histórico limitado e agregado |
| `GET /incidents`, `GET /incidents/{id}` | Usuário | Episódios/evidências sanitizadas |
| `POST /incidents/{id}/acknowledgements` | Operador | Reconhecimento idempotente |
| `POST /incidents/{id}/assignments` | Operador | Atribuição de responsável |
| `POST /incidents/{id}/comments` | Operador | Comentário auditado |
| `POST /silences`, `DELETE /silences/{id}` | Operador autorizado | Silêncio com término e justificativa |
| `POST /maintenance-windows` | Operador autorizado | Janela futura/presente com término |
| `POST /checks/{id}/runs` | Operador autorizado | Nova execução de check cadastrado, com limite |
| `POST /devices`, `DELETE /devices/{id}` | Dono do dispositivo | Registrar/remover token push |
| `GET /probe/config` | Sonda | Apenas sua configuração |
| `POST /probe/heartbeat` | Sonda | Capacidade/saúde, não resultado de alvo |
| `POST /probe/results` | Sonda | Ingestão durável de resultados |
| `POST /probe/replay-sessions` | Sonda | Abrir sessão e manifesto |
| `POST /probe/replay-sessions/{id}/complete` | Sonda dona | Validar completude e iniciar reconciliação |
| `POST /jobs/{id}/heartbeat` | Credencial do job | Batimento limitado daquele job |
| `POST /canary/runs`, `GET /canary/runs/{id}` | Vigilante | Percurso sintético e consulta correlacionada |
| CRUD administrativo de ativos/checks/sondas/políticas | Administrador | Configuração versionada e auditoria |

O prefixo `/api/v1` aplica-se à tabela. O painel pode usar endpoints internos próprios, preservando regras de domínio e autorização. Paginação por cursor para histórico e incidentes; filtros allowlisted e limites máximos.

Credencial de sonda não acessa APIs humanas nem altera ativos. Credencial de canário não pode injetar resultados arbitrários de sondas reais. Usuário de leitura não obtém tokens de equipamento.

### 8. Snapshot para mobile

Campos mínimos: ID/nome do ativo; `health`, `scope`, `coverage`, `integrity`, `stability`; `pending_failure`/`pending_recovery`; `as_of`; `last_valid_observation_at`; `valid_until`; contagens de perspectivas/domínios; verificações por família; incidente ativo; motivos; manutenção/silêncio; permissões das ações.

Snapshot foi calculado agora não significa medição feita agora. Exibir ambos. O cliente não substitui estados desconhecidos por `up` nem infere recuperação ao esvaziar uma lista de incidentes por erro de rede.

Toda ação mutável sensível suporta `Idempotency-Key`, vinculada ao usuário, rota e hash do payload. Mesma chave com payload diferente gera conflito. Guardar resposta idempotente ao menos durante a janela de retentativa do cliente. Usar versão/ETag para conflitos de edição; não sobrescrever silenciosamente mudança de outro operador.

### 9. Histórico e retenção

Baseline: amostras 7 dias; agregados, incidentes e auditoria 12 meses; ledger de dedup pelo menos 14 dias. Eventos de incidente guardam evidência resumida além da retenção bruta. Uma medição atrasada deve invalidar/recalcular os agregados das janelas afetadas antes de publicá-los como completos.

Partições temporais preparadas antecipadamente; rotina de expurgo auditável. Monitorar atraso de agregação e falha de criação de partição. Queries de detalhe usam índice por assignment/tempo e paginação; dashboard consulta projeção, não varre toda a tabela bruta.

Agregados devem preservar contagens e durações. Não calcular percentis corretos por média de percentis nem uma média global não ponderada de médias de origens. Na ausência de estrutura adequada, omitir a métrica em vez de apresentá-la com precisão falsa.

### 10. Eventos e outbox

Eventos internos versionados sugeridos: `result.accepted.v1`, `state.changed.v1`, `incident.opened.v1`, `incident.updated.v1`, `incident.recovered.v1`, `notification.requested.v1`. Não exige message broker novo nem event sourcing integral.

A transação que altera incidente grava evento e intenção. Worker de outbox faz claim/lock com expiração, registra tentativa e retenta com backoff. Falha entre envio e registro pode repetir entrega externa; reutilizar chave no provedor quando suportado. `accepted_by_provider` não significa entregue ao dispositivo nem lido por humano.

### 11. Contratos de validação obrigatórios

Validar IDs vinculados à credencial, enum, família/endereço, duração não negativa, limites de strings/corpos, horário tolerado, geração permitida, path evidence exigida e autorização por recurso. Não permitir payload conter URL de callback arbitrária ou instrução executável.

Schemas reais e testes de compatibilidade devem acompanhar mudanças. Alteração incompatível de contrato não é liberada sem estratégia de transição de sondas/mobile; a central deve informar revisão mínima suportada e rejeitar de forma diagnosticável clientes incompatíveis.

### 12. Execuções manuais e heartbeat de job

Execução manual possui `execution_kind=manual`, `manual_request_id` obrigatório e `scheduled_at=null`. Sua chave natural é `(probe_id, assignment_id, config_generation, manual_request_id)`, além do `result_id` estável. A API de reexecução deduplica a solicitação do usuário; o resultado fica fora das sequências agendadas salvo política explícita futura. Não reutilizar a chave de um slot agendado.

Heartbeat de job é evento autenticado com identidade própria, ID/seq estável, instante observado e situação declarada. Registrar também o horário de recebimento da central. Cada job define periodicidade/deadline e tolerância; ausência só sustenta incidente do job quando a própria ingestão tem cobertura confiável para o período. Durante perda da central, mostrar limitação de observação e reconciliar eventos reenviados, sem presumir que o job parou. Batimento recebido prova apenas a declaração configurada; não comprova o sucesso de toda a tarefa de negócio. Não inferir êxito de job por heartbeat de sonda nem pelo canário.

O expurgo de partições deve respeitar o horário de corte da aceitação histórica: só remover partições inteiramente anteriores ao corte. Uma partição diária na borda da janela pode precisar permanecer além de sete dias de calendário para não descartar uma amostra ainda aceitável.


---

<a id="doc-07-seguranca"></a>

**Arquivo de origem desta seção:** `07_SEGURANCA.md`

## 07 — Segurança

**Versão:** 1.0 · 28/09/2026  
**Natureza:** RC-14; controles de implementação detalhados como DE-07.  
**Limite operacional:** monitorar e diagnosticar; não alterar equipamentos automaticamente.

### 1. Modelo de ameaça mínimo

Proteger segredos de gerenciamento, rede privada, integridade das medições, contas operacionais e capacidade de alerta. Considerar central comprometida, usuário abusando de cadastro/manual run, sonda comprometida, origem falsificando resultados, destino malicioso/redirecionamento, replay conflitante e vazamento por logs/push.

Este escopo não promete resistência absoluta a uma sonda ou roteador totalmente comprometido. O sistema limita danos, mantém proveniência, reduz confiança em evidências contraditórias e registra incidentes de integridade. Não confundir TLS com prova da honestidade do conteúdo recebido.

### 2. Limites de confiança

A central distribui tarefas, mas não amplia a política de egress local. Uma sonda só executa na interseção entre atribuições autenticadas e sua autorização local. O mobile jamais possui credenciais administrativas de equipamentos. Leitura de interface/rota utiliza credencial restrita; o serviço não tem permissão de escrita de configuração.

O endpoint de executor local constitui uma interface privilegiada. Deve aceitar chamadas somente do coordenador e permanecer inacessível pela rede pública e pela rede de usuários. Credencial de sonda não permite acessar recursos de outras sondas nem funções administrativas da central.

### 3. Identidade e autorização

Perfis: administrador, operador e leitura. Autorizar no servidor por ação e recurso; esconder botão não é autorização. Operações sensíveis têm sessão revogável, auditoria e proteção contra repetição/conflito.

2FA obrigatório para administradores antes do piloto conectado ao ambiente real. Guardar códigos de recuperação de forma apropriada, com procedimento de recuperação auditado. Credenciais de desenvolvimento não podem sobreviver automaticamente à promoção para produção.

Sondas usam credenciais exclusivas com escopo reduzido, rotação e revogação. Bootstrap de uso único, TTL e aprovação. Tokens não devem aparecer em query string. Comunicações por TLS com validação habilitada; autenticação mTLS pode ser adotada por ADR, mas não é pré-condição universal deste pacote.

Registrar e bloquear tentativas de IDOR, atribuição de resultado a outra sonda, acesso a incidente fora do escopo e uso de token de device/job como usuário. Rate limits por identidade e endpoint; tratar 401/403 diferentemente de falha temporária de rede.

A revogação impede novas chamadas à central imediatamente, mas não interrompe magicamente uma sonda offline. O risco residual é limitado pela validade de configuração/credencial local e por políticas de rede; documentar esse limite e oferecer revogação local pela operação.

### 4. Política local de sonda

Arquivo/manifesto mantido por administrador da VM, protegido contra alteração pelo processo de medição. Conteúdo mínimo: revisão; redes/destinos autorizados; protocolos/portas; nomes permitidos quando necessários; redirects; referências de segredo; limites de frequência/corpo/concorrência.

A central recebe apenas revisão/hash e capacidades derivadas. Pode reduzir/suspender tarefas; não acrescentar rede privada ou segredo que o administrador local não autorizou. Aceitar nova política local exige ação explícita da operação, não download silencioso da API.

Segredos de equipamento preferencialmente permanecem no store local da sonda. A central guarda referência e metadata, não precisa receber a senha de gerenciamento para cadastrar um perfil. Se houver entrega remota de segredo, exigir criptografia, escopo e trilha específicos por ADR.

### 5. SSRF e escolha real de destino

1. Permitir somente esquemas/tipos de teste previstos; nada de `file:`, URLs de socket, proxy arbitrário ou execução de comando.
2. Canonicalizar nomes, IPs e portas; tratar IPv4 mapeado em IPv6, formatos alternativos e zone identifiers sem bypass.
3. Resolver com mecanismo definido; validar todos os endereços candidatos contra a política e selecionar somente autorizado na família pedida.
4. Conectar ao IP validado sem nova resolução oculta. Preservar Host/SNI sem reabrir seleção de destino.
5. Desativar redirects inicialmente. Se habilitados, limitar saltos e revalidar cada destino; remover credenciais ao mudar origem.
6. Bloquear metadata de nuvem, loopback, link-local e redes não autorizadas. A sonda interna pode acessar redes privadas explicitamente permitidas; não aplicar bloqueio cego que inviabilize seu objetivo.
7. Aplicar defesa também no firewall de egress. Não confiar só no formulário web.
8. Limitar bytes recebidos, duração, resolução DNS, profundidade de JSON/regex e recursos do executor. Não baixar arquivos grandes para testar uma URL.

Resolução validada no coordenador não basta se o executor resolve novamente e conecta a outro endereço. Esse caso precisa de teste de rebinding real em laboratório e é bloqueador de liberação do executor correspondente.

Destinos de controle, canário e APIs administrativas têm autorização separada dos alvos de medição. Não permitir que um check público use uma exceção de loopback destinada ao Blackbox local.

### 6. Credenciais de equipamento e HTTP

Preferir interfaces autenticadas e protegidas suportadas pelo equipamento real, sempre somente leitura. Legado que exija exceção precisa de inventário, isolamento, ACL de origem e aceite de risco; não prometer compatibilidade sem teste.

Headers de autenticação HTTP são segredos; não devolvê-los no painel, auditoria, erro ou push. TLS valida hostname e cadeia. Exceção para certificado interno exige CA autorizada ou política explícita, não `skip_verify` global.

Não manter segredos em Git, compose versionado, imagens, fixtures, exemplos, capturas ou artefatos de CI. `.env.example` contém somente placeholders seguros. O banco armazena segredo cifrado quando realmente necessário e a chave fica fora dele, com backup protegido separado.

### 7. Mobile e painel

Tokens mobile em armazenamento seguro da plataforma. Logout revoga sessão e associação do token push ao usuário. Troca de usuário no mesmo aparelho não pode continuar recebendo incidentes da conta anterior.

Deep links só levam a recurso após autenticação e autorização. O push carrega referência opaca e texto mínimo; não carregar topologia sensível na tela bloqueada por padrão. Ao abrir, consultar estado atual.

Painel protege sessão, CSRF, conteúdo exibido e ações administrativas. Sanitizar comentários e campos vindos de alvos para impedir XSS. CORS restrito aos clientes necessários; não usar origem curinga junto com credenciais. Exibir erro operacional sem stacktrace nem segredo.

### 8. Integridade do pipeline

Validar relações entre identidade, assignment, geração e resultado. Rejeitar timestamps absurdos, família incoerente, path evidence faltante e payload excessivo. Conflito de idempotência gera registro separado e nunca altera a amostra original.

Separar canário de resultado real por identidade/escopo. Uma credencial do vigilante não pode afirmar saúde de clientes. Falha de replay não modifica política ou prova do caminho. Auditoria e ledger sobrevivem à reexecução do worker.

### 9. Auditoria mínima

Registrar login relevante, falha de autorização, criação/alteração de ativo/check/política, cadastro/revogação de sonda, alteração de perfil de caminho, manutenção/silêncio, reconhecimento, responsável, correção histórica, mudança de canal e exclusão/expurgo administrativo.

Evento contém autor/identidade, recurso, horário, correlation ID e alteração sanitizada. Não guardar senha antiga/nova nem tokens. Não permitir apagar auditoria pela interface comum. Expurgo por retenção é operação controlada e rastreável, não edição de evento.

### 10. Dependências, containers e execução

Executar como usuário não privilegiado; filesystem somente leitura onde possível; volumes persistentes restritos; nada de socket Docker ou `privileged: true`. ICMP recebe somente a capacidade/mecanismo demonstrado como necessário. Não usar `NET_ADMIN` para contornar falta de planejamento de rede.

Fixar dependências, verificar origem, licenças e vulnerabilidades no momento de implementar. Atualização do coordenador/executor é operação controlada; configuração remota não pode baixar plugins/binários arbitrários.

### 11. Bloqueadores de promoção

Credencial compartilhada sem escopo; segredo em Git/log/push; check acessando destino fora da allowlist; re-resolução que permita rebinding; executor público; escrita em roteador; app usando credencial de equipamento; canário falsificável por usuário comum; 2FA administrativo ausente; backup sem proteção de segredos; incidentes baseados em medição comprovadamente inválida.

As exceções devem ser específicas, temporárias, registradas e aprovadas; não usar “é MVP” para dispensar as fronteiras de segurança centrais.


---

<a id="doc-08-app-painel-e-alertas"></a>

**Arquivo de origem desta seção:** `08_APP_PAINEL_E_ALERTAS.md`

## 08 — App, painel e alertas

**Versão:** 1.0 · 28/09/2026  
**Natureza:** experiência consolidada RC; campos/interações detalhados como DE-08.

### 1. Objetivo operacional

Abrir o celular e entender situação, evidência, cobertura e atendimento sem precisar interpretar dezenas de métricas. O dashboard não pode ocultar medição inválida atrás de uma cor verde.

Idioma inicial pt-BR; fuso inicial America/Sao_Paulo com horários UTC no backend. Texto, ícone e cor comunicam estado em conjunto. Usar contraste adequado e não depender de distinguir tons próximos. Não se exige identidade visual final para o piloto.

### 2. Telas mobile da Fase 1

| Tela | Conteúdo/ação mínima |
|---|---|
| Acesso | Login, 2FA quando exigido, expiração e logout |
| Visão geral | Incidentes críticos, serviços afetados, upstreams/redundância, cobertura, falhas de integridade, última medição e atualização do snapshot |
| Ativos | Busca, filtros por POP/grupo/tipo/criticidade, favoritos locais do usuário |
| Ativo | Saúde, cobertura e integridade; checks por família e origem; evidência; histórico e gráfico básico |
| Upstream | Perfil homologado ou pendente, evidência/validade, destinos, caminho, impacto externo separado e limitações de retorno |
| Incidentes | Abertos/históricos, gravidade, abrangência e atendimento |
| Detalhe do incidente | Linha do tempo, evidências, responsável, reconhecer, assumir, comentário e silêncio temporário |
| Sondas | Comunicação, capacidades, controles, relógio, atraso e spool; sem segredos |
| Preferências | Permissões de notificação, canais do usuário, dispositivo e fuso permitido |

Não implementar edição complexa de roteamento/sondas no celular. Não há terminal remoto. “Testar novamente” solicita apenas um check cadastrado permitido; não aceita URL ou comando livre.

#### 2.1 Cartão de serviço

Mostrar nome amigável, saúde com abrangência, IPv4/IPv6 quando previstos, contagem de perspectivas válidas/previstas e idade da medição. Integridade comprometida ou cobertura insuficiente deve ter destaque comparável ao de indisponibilidade.

Exemplo de texto correto: “Indisponível nas 2 origens válidas; cobertura 2/3”. Texto incorreto: “Internet fora”. Exemplo de teste suspeito: “Falha em confirmação”; não apenas verde do último estado.

#### 2.2 Cartão de upstream

Separar “serviços públicos acessíveis” de “caminho A validado”. Campos que não foram implementados, como BGP detalhado, aparecem como “não coletado” quando relevantes; não preencher `Established`, tráfego zero ou ícone saudável por padrão.

Se a medição de A perde integridade: “Caminho A sem avaliação confiável; última evidência válida às ...”. Um check externo via backup não pode recuperar esse cartão.

### 3. Ações e concorrência

Reconhecer registra usuário/horário; assumir altera responsável; comentar acrescenta evento; silêncio interrompe avisos no escopo e prazo permitido; manutenção é janela administrativa. Nenhuma dessas ações melhora a saúde técnica.

Enviar ação com chave de idempotência e versão quando apropriado. Enquanto aguarda servidor, exibir “enviando”, não “concluído”. Timeout pode significar ação efetivada: consultar pelo identificador antes de repetir com nova chave. Conflito de edição exige atualização da tela e mensagem clara.

Perfil de leitura nunca realiza essas ações. Limitar frequência de reexecução manual, respeitar política local e concorrência da sonda. Execução manual fica marcada e fora do contador automático de falhas/recuperação por padrão.

### 4. Cache e uso offline

Guardar snapshot mínimo autorizado. Exibir faixa persistente “sem conexão — dados de ...”. Não usar horário em que a tela abriu como horário da medição. Snapshot vencido perde aparência de estado atual mesmo que o aplicativo ainda consiga abrir.

No MVP, não enfileirar silenciosamente reconhecimento, silêncio ou manutenção para executar horas depois. A ação só é apresentada como efetivada após confirmação. Ao reconectar, atualizar snapshot e incidentes antes de oferecer ações sensíveis.

O monitoramento continua nas sondas com o app fechado, aparelho desligado ou sem rede. Não depender de tarefa de fundo mobile para decidir incidentes.

### 5. Painel web

Cadastro de ativos e checks, sondas, domínios de observação, políticas, perfis de caminho, destinatários, usuários e janelas. Revisões têm diff sanitizado e vigência. Adicionar dashboard com atualização e filtros, sem criar aplicação NOC separada.

Sonda cadastrada ainda não homologada deve exibir esse estado. Formulário de path profile deve tornar visível cada dependência DO. Sucesso de uma requisição de cadastro não equivale a execução bem-sucedida nem a prova do caminho.

Histórico oferece gráficos básicos por perspectiva/família e tabela de incidentes. Relatórios exibem cobertura e integridade junto da disponibilidade. Exportação sanitizada opcional, sem abrir escopo de relatórios comerciais.

### 6. Canais de notificação

Fase 0: canal de desenvolvimento sem envio externo automático, depois Telegram configurado e autorizado. Fase 1: push do app e Telegram. E-mail é opcional e não bloqueia o núcleo; escalas completas de plantão ficam na Fase 2.

Para criticidade alta, permitir dois canais simultâneos. Não tentar inferir falha de push pela ausência de leitura para só então enviar o outro canal. Registrar tentativas, aceite pelo provedor, erro, retentativa, cancelamento por política e motivo de supressão.

Push não oferece garantia do recebimento no aparelho. Separar as métricas de decisão, tentativa e aceite do provedor da leitura humana. O reconhecimento explícito é sinal de atendimento, não o retorno da API do provedor de push.

### 7. Eventos notificáveis

Abertura confirmada, agravamento material atual, recuperação confirmada, entrada em instabilidade, perda de cobertura relevante, integridade comprometida e problema da plataforma. Reabertura dentro da janela mantém episódio e produz evento próprio conforme política; não gerar um novo incidente só para alterar o texto.

O replay segue integralmente o documento 03. Incidente antigo encerrado não desperta plantão como queda atual; um agravamento atual ainda relevante pode gerar aviso consolidado. Não duplicar aviso já decidido por reinício de worker.

Agrupamento é por incidente/escopo explícito. Silêncio de um serviço não apaga incidente de integridade da plataforma. Manutenção de ativo não silencia canário ou ausência do vigilante.

### 8. Payload e abertura do app

Payload externo contém título amigável, gravidade, referência opaca de incidente e mensagem mínima. Topologia, IPs internos, credenciais, corpo completo de resposta e detalhes sensíveis ficam na API autorizada. Política de privacidade de conteúdo deve permitir mensagem genérica em tela bloqueada.

Ao abrir, autenticar e consultar a situação atual. Uma notificação antiga pode levar a incidente recuperado; o app deve mostrar isso, não reaplicar o estado antigo do payload. Usuário sem acesso recebe resposta adequada, sem vazamento de existência/detalhes.

### 9. Escalonamento mínimo

Proposta herdada do escopo: segundo responsável após cinco minutos sem reconhecimento em incidente crítico. Só ativar quando os destinatários e a política estiverem configurados e testados. O prazo é parâmetro de operação, não regra imutável.

Não confundir esse escalonamento simples com gestão completa de turnos/plantões. Reconhecimento pode interromper escalonamento de não atendimento, mas não impede alerta de agravamento conforme política. Ausência de destinatário válido gera problema operacional visível; não fingir que o alerta foi enviado.

### 10. Manutenção e silêncio

Obrigatórios início/fim, autor, justificativa e escopo. Extensão é alteração auditada. Ao terminar, recalcular a política sobre o estado atual: falha persistente e ainda relevante pode notificar uma vez; não despejar todas as mensagens suprimidas.

Notificações de recuperação durante silêncio/manutenção seguem política declarada. Estados e eventos continuam registrados. Não permitir janela sem término nem edição retroativa silenciosa para alterar indicador.

### 11. Aceite mobile real

Testar em iPhone e Android reais: app em primeiro plano, fechado, retorno por push, permissão negada, aparelho sem conexão, token rotacionado, logout/troca de usuário, ação com resposta perdida e incidente recuperado antes da abertura.

Builds distribuídos para teste devem ter ambiente explícito e não misturar tokens de sandbox/produção. Assinatura, contas de distribuição, credenciais push e autorização de publicação são DO. Sem esses acessos, registrar “não homologado”; build/emulador não comprova recebimento real.


---

<a id="doc-09-backlog-e-plano-de-entrega"></a>

**Arquivo de origem desta seção:** `09_BACKLOG_E_PLANO_DE_ENTREGA.md`

## 09 — Backlog e plano de entrega

**Versão:** 1.0 · 28/09/2026  
**Natureza:** fases RC; decomposição e sequência DE-09.  
**Regra:** implementar fatias verificáveis; não condicionar todo o software à disponibilidade do roteador real.

### 1. Estados de trabalho

`not_started`, `in_progress`, `implemented`, `verified_lab`, `verified_real`, `blocked`. Código pronto não implica teste aprovado. Um item pode estar implementado e bloqueado apenas na homologação real; registrar a distinção.

Não existem prazos ou estimativas de esforço aprovados neste pacote. O agente dimensionará depois da inspeção, sem presumir equipe, hardware ou velocidade de execução.

### 2. Primeira fatia vertical — F0-01

Entregar laboratório com uma aplicação HTTP controlada, coordenador Go e política local, agenda persistente, ingestão durável, estado por sequência válida, incidente, outbox com canal de desenvolvimento e painel mínimo. Persistir identidade/revisão/família desde o início, mesmo antes de todos os protocolos.

Provar sucesso, falha, ausência, duplicata, resposta de ingestão perdida após commit e recuperação. A notificação de desenvolvimento deve estar identificada como simulada; não usar token real para cada execução de CI.

Esse caminho é a primeira demonstração. Não gastar a primeira etapa em dezenas de CRUDs, design mobile, todos os conectores e uma arquitetura que ainda não executa um teste.

### 3. Backlog da Fase 0

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

#### Gate 0A — software/laboratório

Todos os casos obrigatórios de motor, contrato, segurança e resiliência executáveis em laboratório passam. A demonstração é reproduzível, usa dados fictícios identificados e não depende de cliente produtivo. É permitido continuar desenvolvimento F1 quando apenas a validação real está bloqueada.

#### Gate 0B — piloto real

Sondas/destinos/IPv6 efetivos; diversidade registrada; canal de alerta autorizado; vigilância independente; rota/caminho aprovado; ensaios upstream e backup/restore aplicáveis demonstrados. Sem 0B, não anunciar upstream homologado ou produção confiável.

### 4. Backlog da Fase 1 — operação mobile

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

### 5. Fase 2 — somente após valor comprovado

Telemetria rica BGP/interface, BMP onde suportado, novos fabricantes, traceroute/MTR sob demanda e com limites, óptica/CPU/memória, correlação avançada, escalas completas, relatórios adicionais, integrações com ferramentas já usadas e HA quando justificada. Cada item exige caso de uso e aceite próprios.

Não implementar “preparação para SaaS”, IA de causa raiz, NMS completo ou automação de rede como parte oculta de uma refatoração do MVP.

### 6. Definição de concluído por fatia

Código e configuração versionados; testes unitários/contrato/integrados pertinentes; migração forward/backward ou recuperação documentada; logs/segredos revisados; métricas mínimas; documentação de execução; evidência com comando/ambiente/commit; backlog e status atualizados.

Um teste ignorado não conta como aprovado. Registro “não executado: host sem IPv6” é válido como bloqueio, não como aceite. Uma alteração de semântica exige atualizar 03, fixtures e testes no mesmo trabalho.

### 7. Gates de qualidade

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

### 8. Como tratar bloqueios

Cada bloqueio precisa de ID, item afetado, evidência, informação/ação requerida, responsável e tarefas que continuam livres. Exemplo: falta de acesso somente leitura ao roteador bloqueia G-UP, mas permite implementar adaptador simulado, contrato, interface, ingestão e testes do motor.

Não perguntar novamente por informação já presente no repositório/formulário. Não inventar valores produtivos. Decisões locais pequenas ficam em ADR/DE e seguem; alterações de segurança, escopo comercial ou produção exigem decisão apropriada.

### 9. Relatório de sessão do agente

Apresentar o que mudou e o que foi realmente executado, falhas encontradas, estado dos gates, pendências humanas e próxima fatia exata. Preferir links para evidências e commits locais às afirmações genéricas “tudo pronto”. Não encerrar cada subetapa solicitando autorização redundante; não prometer trabalho autônomo fora da sessão.


---

<a id="doc-10-testes-e-criterios-de-aceite"></a>

**Arquivo de origem desta seção:** `10_TESTES_E_CRITERIOS_DE_ACEITE.md`

## 10 — Testes e critérios de aceite

**Versão:** 1.0 · 28/09/2026  
**Situação:** plano de verificação. Nenhum teste de aplicativo ou infraestrutura foi executado pela criação destes documentos.  
**Natureza:** critérios RC; cenários e rastreabilidade detalhados como DE-10.

### 1. Camadas de teste

**Unitário:** motor de estado e política com relógio controlado, sem serviços externos.  
**Contrato:** schemas reais entre Go, Laravel e Flutter; permissões e idempotência.  
**Integração:** PostgreSQL, filas, spool, executor e aplicação-alvo de laboratório.  
**Falha controlada:** desligamento, atraso, perda de resposta, disco, relógio e caminho.  
**Homologação real:** diversidade, IPv6, roteador, upstream, vigilância e mobile em equipamentos reais.

Identificar sempre `unit`, `contract`, `lab` ou `real`. Um resultado mock não comprova encaminhamento real. Toda falha de rede produtiva exige autorização e reversão, conforme documento 05.

### 2. Regra de evidência

Para cada execução registrar ID do caso, horário, tipo de ambiente, commit/versões, configuração/política, pré-condições, ação/comando utilizado, resultado esperado/obtido e artefato sanitizado. Informar `passed`, `failed`, `not_run` ou `blocked`; nunca converter ignorado em aprovado.

Artefatos sugeridos: relatório automatizado, JSON de resultados/snapshots, logs correlacionados, evidência de rede somente do fluxo autorizado e captura mobile sem segredos. Evidência deve mostrar a cronologia, não só um screenshot verde no final.

### 3. Estados e perspectivas — G-CORE

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

### 4. Família IP, controles e integridade — G-CORE

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

### 5. Incidentes, replay e outbox — G-DATA

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

### 6. Sondas, limites e segurança — G-SEC/G-DATA

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

### 7. Upstreams — G-UP

Executar UP-01 a UP-09 do documento 05. Os principais são controle positivo, falha de A com B vivo, ausência de rota sem fallback, desvio indevido para saída padrão, falha de referência, evidência ausente e mudança posterior da política.

Evidência deve mostrar a revisão homologada, família, destino, método de vínculo e resultado. Contagem em regra de marcação, IP de eco ou captura de tela do painel, isoladamente, não fecham o gate.

Uma topologia sem capacidade de comprovação permanece não homologada. Homologação IPv4 não libera automaticamente IPv6. Testes destrutivos não podem ocorrer fora de laboratório/janela autorizada.

### 8. Vigilância e recuperação — G-WATCH/G-OPS

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

### 9. Mobile e notificações — G-MOBILE

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

### 10. Fixtures iniciais do motor

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

### 11. Ensaio de carga

Perfil de referência herdado do escopo: 500 verificações lógicas, cada uma com família definida, em cinco sondas a cada 60 s. São 2.500 execuções/minuto, cerca de 41,7 resultados/s e 3,6 milhões/dia, sem heartbeats, controles ou novas famílias/checks.

Medir taxa sustentada, atraso de agenda, ingestão e decisão, latência de API, espaço por amostra, crescimento dos índices, recuperação de backlog, outbox e expurgo. Acrescentar cenário de replay de todas as sondas com carga corrente mantida; não validar só médias sem rajadas.

Metas iniciais do escopo: API de leitura p95 até 2 s no perfil homologado; falha persistente no perfil crítico de 30 s confirmada e primeira tentativa de aviso em até 2 min com central/sondas saudáveis. Registrar definição do instante de início da falha, percentis e exceções. Entrega ao aparelho não integra essa meta.

Não assumir capacidade de VM sem ensaio. Publicar configuração de hardware, versões, duração, massa, taxas e erros. Se reprovar, corrigir ou ajustar dimensionamento e documentar; não trocar a meta silenciosamente.

### 12. Rastreabilidade resumida

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


---

<a id="doc-11-implantacao-operacao-e-runbooks"></a>

**Arquivo de origem desta seção:** `11_IMPLANTACAO_OPERACAO_E_RUNBOOKS.md`

## 11 — Implantação, operação e runbooks

**Versão:** 1.0 · 28/09/2026  
**Natureza:** RC de independência, recuperação e vigilância; procedimentos detalhados como DE-11.

### 1. Ambientes

**Desenvolvimento:** Compose e alvos sintéticos, sem segredos produtivos. Pode simular várias sondas, sempre marcadas como laboratório e sem alegar diversidade real.

**Homologação:** central e sondas representativas, IPv4/IPv6 reais quando previstos, canais e dispositivos autorizados. Mudanças em roteador só em ambiente isolado/janela aprovada.

**Produção:** serviços, DNS, identidade, backups, segredos e canais próprios. Não compartilhar banco com Sysadm. Nomes/DNS/IPs serão informados no documento 13; não reutilizar domínios conhecidos de outros projetos sem decisão explícita.

Separar dados e tokens de push/Telegram de teste dos produtivos. Seed fictício não pode aparecer como ativo real. Publicação em loja e alteração de DNS são ações externas sujeitas a autorização.

### 2. Inventário de implantação

Central: reverse proxy/TLS, aplicação/API, workers, PostgreSQL, Redis, agenda de manutenção/expurgo e métricas. Sondas: coordenador, executor local, identidade, política local e spool persistente. Vigilância: checker externo e mecanismo de ausência de batimento que não dependa da mesma central.

Registrar dependências de provedor, ASN/origem, DNS, autenticação, armazenamento e canais. Dois serviços na mesma VPS não são independentes; provedores distintos também não garantem ausência de dependências comuns. Documentar limites do desenho.

A central única é SPOF conhecido. Não afirmar HA por usar Docker ou nuvem. Buffer não substitui central disponível e watchdog não recupera automaticamente banco.

### 3. Pré-flight

Verificar DNS/TLS; acesso por conexão alternativa à Blue3; relógio contra referência externa; disco/volumes; backup; acesso administrativo; regras de firewall; capacidades de protocolo; política local; destino real autorizado; credentials escopadas; versão de imagens/binários fixada; configuração válida e prazo.

Na sonda, provar conectividade IPv6 no executor, não só endereço no host. Demonstrar ICMP sem container privilegiado. Confirmar que a porta do executor não está pública. Validar healthchecks que não façam alterações em equipamentos/clientes.

Nenhum procedimento de produção deve depender de “usar senha default” ou de desativar validação TLS.

### 4. Vigilância independente

#### 4.1 Canário de processamento

O checker gera ID/nonce novo e injeta execução sintética em escopo reservado. O canário atravessa ingestão durável, trabalho assíncrono, motor, registro esperado de decisão/outbox e despacho para um destino técnico reservado. Não vale endpoint que simplesmente ecoa a requisição antes dos workers.

O checker consulta evidência do mesmo nonce e valida conteúdo, horários, prazo e recibo do despacho técnico. O destino técnico pode integrar o próprio vigilante, com autenticação; o recibo deve ser observável independentemente do status de criação da outbox. Só envia batimento saudável ao mecanismo externo depois dessa comprovação. API 200, processo vivo e resultado antigo não bastam.

O canário não integra disponibilidade de cliente nem gera alarme de queda a cada execução. O destino técnico testa o worker de despacho sem acionar plantão. Na primeira fatia local esse destino pode ser simulado, mas G-WATCH exige percurso técnico real. A comprovação de fluxo até esse destino não é prova de entrega push ao aparelho; verificar canais externos separadamente por teste autorizado e registrar esse alcance.

#### 4.2 Vigiar o checker

O mecanismo de ausência recebe batimentos condicionados e alerta quando não chegam no prazo. Deve ficar em domínio de falha distinto e possuir credenciais/canal que não dependam da central ou apenas do canal principal dela. Pode ser serviço próprio ou externo selecionado pela operação, sem escolha obrigatória de fornecedor neste pacote.

Definir `WATCHDOG_INTERVAL_S`, `CANARY_DEADLINE_S` e `DMS_GRACE_S` na homologação. O prazo de ausência deve acomodar intervalo, deadline, jitter e atraso tolerado, sem criar janela desconhecida infinita. Publicar tempo de detecção observado.

O checker não manda “estou vivo” antes de validar o canário. Se morrer, parar DNS, perder token ou perder conectividade, a ausência continua sendo detectável pelo mecanismo externo.

#### 4.3 Manutenção

Janelas de ativos não podem silenciar canário/ausência do vigilante. Manutenção da própria central é comunicada por procedimento específico; não converter ausência prevista em sinal falso de sucesso. Qualquer tratamento de aviso planejado na vigilância independente exige prazo e auditoria próprios.

### 5. Métricas da plataforma

Acompanhar atraso de última observação, percentil de agenda, tamanho/idade do spool, descartes, heartbeats, offset, falha de controles, ingestão aceita/rejeitada, dedup/conflito, itens duráveis pendentes, atraso dos workers, replays pendentes, atraso de decisão, outbox pendente, tentativas/erros por canal, validade de path profiles, cobertura e integridade.

Métricas de banco incluem espaço, crescimento de partições, atraso de agregação, backups e restore recente. Não imprimir payloads sensíveis para conseguir observabilidade. Usar correlation IDs e códigos estáveis.

### 6. Backups e restauração

Backups da central precisam incluir banco, configurações relevantes, revisões, incidentes, auditoria, ledger e outbox dentro da política definida. Chaves para descriptografar segredos e credenciais de recuperação exigem proteção e cópia separada. Backup cifrado sem chave recuperável não fecha o requisito.

Definir retenção de backups, frequência, destino independente e responsabilidades. RPO/RTO são DO antes de produção: devem ser acordados e medidos, não inferidos do tamanho do buffer.

Testar restore em ambiente isolado, sem duplicar envios reais. Conferir usuários, perfis, snapshots recalculados, incidentes e referências. Registrar duração, ponto de dados recuperado e itens irrecuperáveis. Redis não é a única fonte de trabalho; reconstruir fila a partir do banco.

Após restore real, identificar período perdido e ressincronizar sondas. Histórico pode ficar incompleto além do buffer disponível. Outbox/ledger restaurados de um ponto antigo podem levar a repetição de intenções externas já enviadas; reconciliar com registros disponíveis e documentar risco, sem prometer recuperação perfeita.

### 7. Rotina de deploy

Validar branch/commit/autorização → testes e revisão de segredos → backup/ponto de recuperação → migrações compatíveis → central e workers → verificação de canário → atualização gradual de sondas → smoke → acompanhamento → registro de versão.

Não parar todas as sondas para atualizar a central. Compatibilidade de contratos deve permitir atualização gradual dentro da janela suportada. Nova geração de config tem vigência explícita e não invalida artificialmente histórico antigo.

Rollback deve cobrir aplicação, executor, coordenador e compatibilidade de schema. Uma migração irreversível exige plano de recuperação de dados, não apenas trocar imagem. Publicar release/deploy somente com autorização do fluxo do repositório.

### 8. Runbooks mínimos

#### RB-01 — Central indisponível

Confirmar aviso externo e acesso por rede alternativa. Distinguir falha de DNS/TLS/API/banco/workers. Não reiniciar toda a plataforma sem evidência. Preservar logs e backups. Restaurar componente afetado ou seguir recuperação aprovada.

Sondas continuam até seus limites; acompanhar backlog e descarte. Na volta, confirmar canário e dados correntes, iniciar/reconciliar backlog e verificar que incidentes antigos não geram tempestade de queda atual.

#### RB-02 — API responde e monitoramento não evolui

Consultar canário pelo nonce recente, `ingestion_work`, atraso dos workers e outbox. API 200 não encerra o problema. Reenfileirar trabalho durável com operação idempotente; não reinserir manualmente resultados como novos. Validar estados e alertas após recuperação.

#### RB-03 — Sonda sem comunicação

Não declarar todos os alvos fora. Conferir heartbeat, conectividade administrativa autorizada, relógio, disco, processo, spool e controles. Se a sonda estava medindo offline, preservar IDs/revisões no reenvio. Identificar lacunas quando o buffer não cobriu toda a falha.

#### RB-04 — Integridade de caminho comprometida

Manter upstream sem conclusão atual por aquela evidência; não usar sucesso do backup para verde. Conferir revisão/hash e validade do perfil, leitura de equipamento, destino e possível desvio. Acionar NOC para correção controlada; o app não altera a rota. Retirar quarentena só após sequência íntegra prevista, podendo o caminho continuar falhando.

#### RB-05 — Tempestade/oscilação

Verificar se é falha real, perda de controles, duplicação de domínio, replay ou mudança de configuração. Usar silêncio temporário apenas no escopo adequado, preservando incidentes de plataforma. Corrigir dedup/anti-flap e testar recuperação antes de desativar toda a notificação.

#### RB-06 — Provedor de notificação falha

Registrar falha/tentativas, confirmar credencial/limite/rede e usar canal independente previsto. Não marcar entregue por HTTP timeout nem por registro local. Não repetir manualmente centenas de mensagens antigas; reavaliar relevância atual e regras do documento 03.

#### RB-07 — Credencial comprometida

Revogar identidade afetada, rotacionar segredo, avaliar acessos e configuração alterada e preservar auditoria. Em sonda offline, aplicar bloqueio local/rede quando necessário; revogação na central não impede execução offline já autorizada até expirar. Reativação exige identidade/configuração verificadas.

#### RB-08 — Disco ou retenção fora do limite

Identificar dados/spool/log responsáveis, preservar reserva de diagnóstico e executar política documentada. Registrar períodos descartados. Não truncar tabelas de incidentes ou dedup para liberar espaço sem avaliar impacto. Validar partições, agregados e reinserção de resultados antigos após expurgo.

### 9. Passagem para operação

Responsáveis definidos, credenciais/canais entregues com segurança, inventário preenchido, gates aprovados, documentação de rollback/restore, parâmetros de alertas revisados e limitações conhecidas. Reunião/aceite do NOC registrado no documento 13.

A liberação inicial deve ser supervisionada. Não informar “tudo operacional” apenas porque containers estão `running`. O dado de saúde é válido somente se a medição, cobertura, integridade e percurso de processamento sustentarem a conclusão.


---

<a id="doc-12-decisoes-premissas-e-fontes"></a>

**Arquivo de origem desta seção:** `12_DECISOES_PREMISSAS_E_FONTES.md`

## 12 — Decisões, premissas e fontes

**Versão:** 1.0 · 28/09/2026  
**Objetivo:** distinguir consenso de produto, detalhamento de engenharia e informação ainda ausente.  
**Limite:** não foi feita nova pesquisa externa nesta consolidação. Referências técnicas herdadas são pontos de consulta; versões/capacidades deverão ser verificadas pelo agente na implementação.

### 1. Material de origem

- **E0:** escopo inicial versão 0.1, produzido nesta conversa.
- **B1:** primeiro debate anexado, com convergência em MVP menor sem retirar upstream mínimo.
- **B2:** segundo debate anexado, com controles, replay, vigilância, integridade e objeções às provas de caminho. O texto termina com divergências técnicas; não é uma solução universal fechada.
- **R1:** avaliação posterior nesta conversa, aceita pelo pedido de consolidar documentos: aproveitar o recorte e proteções, sem obrigar `/24`, delta exato de contador ou migração de banco sem ensaio.
- **U1:** pedido atual de arquivos Markdown para o agente implementar.

Os três arquivos de origem foram lidos a partir dos anexos disponíveis. Abaixo estão seus nomes e hashes para rastreabilidade. Não é necessário enviar novamente os debates ao agente para começar: este pacote é autocontido e normativo para o desenvolvimento.

| Origem | Arquivo original | SHA-256 |
|---|---|---|
| E0 | `escopo_monitoramento_blue3_v0.1.md` | `1d9e5d4e25fdffae85705f9a1311b423f23ff217ce1d5929e31ebfd0c82c6c4d` |
| B1 | `debate-samir-para-a-blue3-eu-estruturaria-isso-como-uma-plataforma.md` | `83576278f9f8fd1a0ef24afe42f10b63f376c7946a6fe2e4a4a693b43f0666d7` |
| B2 | `debate-samir-para-a-blue3-eu-estruturaria-isso-como-uma-plataforma-v2.md` | `3de31e7eb126efebc316ae08c646f7fe17f0b9ac5e8fe71b65cbecdb8a4d3766` |

R1 e U1 são mensagens da conversa, não arquivos com hash. Não foram inventados anexos para essas mensagens.

### 2. Decisões consolidadas e origem

| Tema | Decisão para o pacote | Origem |
|---|---|---|
| Produto | Monitoramento distribuído; app para alerta/atendimento; painel para configuração | E0 §§1–2/8; B1 “App mobile” |
| Implantação | Central externa, sondas externas/interna e dependências explicitadas | E0 §2; B1 arquitetura |
| IPv4/IPv6 | Estados/testes separados e sem fallback | E0 §4.2; B1 consenso |
| MVP | Upstream mínimo na Fase 0; não empurrar hipótese central para o final | B1 “MVP menor, mas não sem upstream”; R1 |
| Telemetria rica | BGP/interface/BMP e novos fabricantes depois, salvo leitura mínima necessária | B1 posição revisada; R1 |
| Integridade | Dimensão separada; medição inválida não conserva verde atual | B2 rodadas finais; R1 |
| Caminho | Exigir vínculo/ausência de fallback, sem universalizar eco, contador ou prefixo | E0 §5; B2 objeções; R1 |
| Controles | IP literal e nome, com tratamento de falhas coincidentes e referência | B2 controles; R1 |
| Sonda | Coordenador agenda e envia; Blackbox é executor | E0 §§6/12; B1 contrato explicitado |
| Idempotência/replay | Resultado estável, reconstrução por evento e alertas reconciliados | B2 replay; R1 |
| Anti-flap | Reutilizar episódio, marcar instabilidade e exigir estabilidade sustentada | B2 anti-flap; R1 |
| Segurança | Política também local; central não ganha poder irrestrito sobre rede | E0 §10; B1 segurança; R1 |
| Watchdog | Canário processado e batimento condicionado; vigiar ausência do vigilante | B2 watchdog; R1 |
| Histórico | Cobertura e integridade visíveis; retenção e agregação desde o núcleo | E0 §11; B1 dados; R1 |
| Banco | PostgreSQL inicial condicionado a ensaio; solução especializada não obrigatória | E0 §12; R1 |
| Mobile | Piloto técnico antes do app, sem cancelar iPhone/Android na Fase 1 | B1/B2 recorte; R1 |
| Manutenção | Término e autoria; não mascarar indicador nem vigilância | E0 §9; B2 manutenção; R1 |

### 3. Propostas rejeitadas, limitadas ou adiadas

| Proposta da discussão | Tratamento |
|---|---|
| Adiar todos os upstreams para outra versão | Rejeitada: upstream mínimo é parte do valor inicial. |
| Eco de IP de origem como prova de saída | Rejeitada como prova isolada. |
| Contador da regra de marca como prova universal de encaminhamento | Rejeitada. |
| `delta == N` por pacote/fluxo como regra obrigatória do produto | Não incorporada como universal; método só mediante validação específica. |
| `/24` exclusivo e retirada de anúncio como pré-requisito para todo MVP | Não incorporada; apenas experimento específico autorizado, quando cabível. |
| Último verde preservado como atual durante quarentena | Rejeitada. |
| Amarração do frescor à retenção do buffer | Rejeitada; são janelas diferentes. |
| Alertar a cada item do replay ou reavisar tudo que ainda está aberto | Rejeitada; exige reconciliação/ledger de decisões e agravamento relevante. |
| TimescaleDB obrigatório porque há milhões de linhas mensais | Não incorporada; decisão baseada em carga/consultas/retenção. |
| “PWA não funciona no iOS” como justificativa genérica | Não adotada. O app continua por requisito do produto, não por essa alegação. |
| Quarentena que interrompe a coleta | Rejeitada: impediria revalidação e esconderia estado atual. |
| Deduplicação/entrega externa exatamente uma vez garantida | Não prometida; outbox deduplica intenção, transporte pode repetir entrega. |
| Terceira aplicação de telas NOC | Adiada; dashboard web cobre o início. |
| Atlas, looking glasses e IA no caminho crítico | Adiados como integrações complementares; não substituem quórum contínuo ou prova interna. |

### 4. Detalhamentos acrescentados neste pacote — DE

Estes itens são decisões iniciais para implementação, não citações literais dos debates nem homologações já realizadas.

| ID | Decisão inicial | Como alterar |
|---|---|---|
| DE-01 | Laravel, Go, Flutter, PostgreSQL, Redis e Compose como direção inicial | ADR e compatibilidade com repositório; não reabrir produto inteiro |
| DE-02 | Monorepo e módulos, painel server-driven inicialmente | Ajuste local justificado, preservando fronteiras |
| DE-03 | Enums, sequências, frescor e parâmetros da seção 12 do doc 03 | Perfil versionado + fixtures + evidência |
| DE-04 | Manifesto de replay, faixa corrente separada e outbox transacional | ADR + testes temporais/dedup equivalentes |
| DE-05 | Spool SQLite e slots absolutos com geração | Teste de crash/idempotência equivalente |
| DE-06 | Entidades, endpoints, schemas e ledger global de receipts | Contrato versionado e testes dos consumidores |
| DE-07 | Controles de autorização/SSRF/segredos detalhados | Apenas equivalente ou mais restritivo; exceção explícita |
| DE-08 | Telas, cache, payload mínimo e interação idempotente | Ajuste de UX sem mudar semântica técnica |
| DE-09 | IDs e sequência das fatias F0/F1 | Backlog justificado preservando gates e upstream mínimo |
| DE-10 | Casos T-001 a T-074 e gates | Acrescentar/adaptar mantendo cobertura das invariantes |
| DE-11 | Pré-flight, canário, runbooks e procedimentos | Ajustar ao ambiente real e registrar aceite |

Semânticas e limites de segurança não podem ser relaxados silenciosamente sob pretexto de trocar tecnologia. Um ADR deve conter contexto, decisão, alternativas relevantes, consequências e teste de verificação; não precisa virar tratado.

### 5. Dependências operacionais ainda não fornecidas — DO

Nome final, repositório/branch alvo, IPs/domínios/ASNs reais, provedores das VMs, hardware, plataforma/versão da borda, acessos de leitura, desenho de encaminhamento, destinos autorizados, limites de qualidade, capacidade IPv6, credenciais de canais, destinatários, ambiente Apple/Android, método de distribuição, backup/RPO/RTO e autorizações de produção.

Essas lacunas não foram preenchidas por conhecimento genérico ou por dados de outros projetos. O formulário está no documento 13. Laboratório pode prosseguir com dados fictícios, desde que não sejam apresentados como infraestrutura real.

### 6. Referências técnicas herdadas para consulta

Estas URLs são referências textuais transferíveis em Markdown, não marcadores internos de chat. Não significam verificação de versão atual nesta etapa.

| Tema | Referência oficial indicada |
|---|---|
| Exporter multi-target | https://prometheus.io/docs/guides/multi-target-exporter/ |
| Blackbox Exporter | https://github.com/prometheus/blackbox_exporter |
| Configuração do Blackbox | https://github.com/prometheus/blackbox_exporter/blob/master/CONFIGURATION.md |
| Agrupamento/inibição | https://prometheus.io/docs/alerting/latest/alertmanager/ |
| Policy Routing | https://help.mikrotik.com/docs/spaces/ROS/pages/59965508/Policy+Routing |
| Connection tracking/FastTrack | https://help.mikrotik.com/docs/spaces/ROS/pages/130220087/Connection+tracking |
| RTT e interpretação | https://www.rfc-editor.org/rfc/rfc2681.html |
| BMP, evolução | https://www.rfc-editor.org/rfc/rfc7854.html |
| SSRF | https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html |
| Flutter | https://docs.flutter.dev/reference/supported-platforms |
| Laravel queues | https://laravel.com/docs/queues |
| PostgreSQL/particionamento | https://www.postgresql.org/docs/current/ddl-partitioning.html |
| Outbox | https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html |
| Android/push | https://firebase.google.com/docs/cloud-messaging/android-message-priority |
| Apple/push — contexto arquivado, não guia de API atual | https://developer.apple.com/library/archive/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/CreatingtheNotificationPayload.html |

A implementação mobile deverá consultar a documentação vigente de notificações/autenticação/distribuição dos mantenedores; o material arquivado da Apple serve apenas ao contexto da conversa. Não copiar APIs antigas porque aparecem na fonte histórica.

### 7. Alterações em relação ao escopo 0.1

O pacote retém produto e arquitetura, reduz a exigência inicial de BGP/interfaces ricos, aprofunda integridade/controles/replay, separa validade atual e buffer, condiciona upstream à topologia e mantém banco especializado opcional. Acrescenta backlog, contratos, testes e procedimentos, explicitamente como detalhamento de engenharia.

Essas alterações são a consolidação pedida; não é transcrição integral dos debates. Os originais permanecem como registro histórico, mas não prevalecem sobre a interpretação crítica e os recortes deste pacote.


---

<a id="doc-13-checklist-do-piloto-e-status"></a>

**Arquivo de origem desta seção:** `13_CHECKLIST_DO_PILOTO_E_STATUS.md`

## 13 — Checklist do piloto e status

**Versão:** 1.0 · 28/09/2026  
**Uso:** arquivo vivo a ser atualizado pelo agente e pelo responsável operacional.  
**Estado inicial:** documentação criada; implementação, testes de aplicação e implantação ainda não executados neste pacote.

### 1. Situação inicial

| Item | Estado | Evidência |
|---|---|---|
| Pacote de especificação | Preparado | Arquivos 00–13 e README |
| Repositório alvo | Não informado | Preencher após inspeção |
| Código da central/sonda/app | Não implementado por este pacote | Agente deverá registrar estado real do repo |
| Testes de aplicativo | Não executados | Plano no documento 10 |
| Infraestrutura real | Não inventariada | Formulários abaixo |
| Upstreams reais | Não homologados | Depende de desenho, leitura e aceite NOC |
| Mobile em dispositivos reais | Não homologado | Depende de implementação e acessos |
| Liberação operacional | Não autorizada nem realizada | Depende dos gates |

### 2. Identidade e responsabilidades

| Campo | Valor |
|---|---|
| Nome final do produto | PENDENTE — “Blue3 Monitoramento” é identificação de trabalho |
| Repositório e diretório de implementação | PENDENTE |
| Branch e regras locais | PENDENTE — inspecionar, não presumir main/master/dev |
| Responsável técnico pelo software | PENDENTE |
| Responsável NOC e autor de mudança | PENDENTE |
| Operadores e contatos de escalonamento | PENDENTE |
| Responsável por central/backups | PENDENTE |
| Responsável por distribuição mobile | PENDENTE |
| Local seguro para credenciais | PENDENTE — nunca colar segredos neste arquivo |

### 3. Inventário de sondas

As linhas abaixo são posições propostas, não VMs existentes.

| Posição | Papel | Provedor/ASN/região | IPv4/IPv6 comprovados | Domínio/dependências | Status |
|---|---|---|---|---|---|
| EXT-A | Externa | PENDENTE | PENDENTE | PENDENTE | Não provisionada |
| EXT-B | Externa | PENDENTE | PENDENTE | PENDENTE | Não provisionada |
| EXT-C | Externa | PENDENTE | PENDENTE | PENDENTE | Não provisionada |
| INT-A | Interna | PENDENTE | PENDENTE | PENDENTE | Não provisionada |

Por sonda, registrar host/SO, versão coordenador/executor, identidade, revisão da política local, referência de credencial, disco/spool, NTP, redes permitidas, contatos de manutenção e evidência de `probe-doctor`. Informações de acesso sensíveis ficam em inventário protegido, não neste pacote distribuído ao agente sem necessidade.

### 4. Central e vigilância

| Campo | Valor |
|---|---|
| Local/provedor da central | PENDENTE |
| FQDN da API/painel | PENDENTE |
| DNS/TLS e acesso administrativo alternativo | PENDENTE |
| Banco/armazenamento e hardware | PENDENTE |
| Checker: local/domínio de falha | PENDENTE |
| Mecanismo de ausência: local/domínio de falha | PENDENTE |
| Canal principal e canal independente | PENDENTE |
| WATCHDOG_INTERVAL_S / CANARY_DEADLINE_S / DMS_GRACE_S | PENDENTE — homologar relação e tempo observado |
| Backup: frequência/retenção/destino | PENDENTE |
| RPO / RTO acordados | PENDENTE |
| Restore medido e referência de evidência | PENDENTE |

### 5. Perfil de upstream — duplicar para cada caminho/família

| Campo | Valor |
|---|---|
| Identificador amigável do caminho | PENDENTE |
| Circuito/upstream e POP | PENDENTE |
| Família IP | PENDENTE |
| Sonda/interface/origem e NAT, se houver | PENDENTE |
| Equipamento/modelo/versão real | PENDENTE |
| Referência do acesso somente leitura | PENDENTE — não informar senha/token |
| Quem impõe a saída e como | PENDENTE |
| Tabela/VRF/política/gateway/interface esperada | PENDENTE |
| Como se impede fallback e resolução via backup | PENDENTE |
| Destino de teste 1 e domínio de falha | PENDENTE |
| Destino de teste 2 e domínio de falha | PENDENTE |
| Como a saúde dos destinos é corroborada | PENDENTE |
| Método de evidência e limitações | PENDENTE |
| Intervalo/prazo de validade da evidência | PENDENTE |
| Hash/revisão da configuração pertinente | PENDENTE |
| Tratamento de mudança posterior | PENDENTE |
| Limites conhecidos do retorno | PENDENTE |
| Janela/autorização e plano de reversão | PENDENTE |
| Resultados UP-01 a UP-09 pertinentes | Não executados |
| Revisor NOC/data/evidência de aceite | PENDENTE |
| Estado do perfil | draft |

### 6. Ativos do piloto

Selecionar aproximadamente 10–20 representativos; o número é proposta do escopo, não obrigação de preencher com alvos artificiais. Incluir aplicação pública, DNS, serviço dual-stack quando previsto, equipamento privado autorizado e caminhos upstream disponíveis.

| Ativo | Tipo/criticidade | Check/protocolo/família | Perspectivas/perfil | Critério/limites | Responsável |
|---|---|---|---|---|---|
| PENDENTE | PENDENTE | PENDENTE | PENDENTE | PENDENTE | PENDENTE |

Os destinos de controle não devem ser preenchidos automaticamente com serviços públicos sem considerar autorização, política e dependências. Levantar limites de latência/perda por contexto; não inventar um limite global para todos os links.

### 7. Mobile e canais

| Campo | Valor |
|---|---|
| Ambiente de build iOS e credenciais de assinatura | PENDENTE — somente referência segura |
| Ambiente de build Android | PENDENTE |
| iPhone e Android reais para homologação | PENDENTE |
| Identificadores de app e método de distribuição | PENDENTE |
| Configuração push de teste/produção | PENDENTE |
| Destino Telegram de piloto e autorização | PENDENTE |
| Canal independente da vigilância | PENDENTE |
| Destinatário primário/secundário por criticidade | PENDENTE |
| Prazo de escalonamento aprovado | PENDENTE — referência inicial: 5 minutos |
| Conteúdo permitido na tela bloqueada | PENDENTE |

### 8. Quadro de gates

| Gate | Estado inicial | Responsável | Evidência/pendência |
|---|---|---|---|
| G-CORE | not_started | PENDENTE | Testes T-001 a 026 |
| G-DATA | not_started | PENDENTE | Idempotência/replay/outbox/spool |
| G-SEC | not_started | PENDENTE | Política local, acesso, segredo e SSRF |
| G-UP | blocked | NOC a definir | Inventário/topologia/acesso ainda não fornecidos |
| G-WATCH | not_started | PENDENTE | Checker/canário/ausência independente |
| G-MOBILE | not_started | PENDENTE | App e dispositivos reais |
| G-OPS | not_started | PENDENTE | Carga, restore, operação e rollback |

O bloqueio de G-UP não impede F0-00/F0-01 nem a implementação do laboratório. Não alterar para `passed` apenas porque existe um mock.

### 9. Registro de execução do agente

Copiar a seção por sessão/fatia:

```text
Data e sessão:
Repositório / branch / commit:
Fatia do backlog:
Arquivos/componentes alterados:
Decisões DE/ADR tomadas:
Comandos de teste realmente executados:
Resultados e artefatos sanitizados:
Teste não executado e motivo:
Diferença entre laboratório e ambiente real:
Riscos/regressões encontrados:
Bloqueio humano concreto, responsável e ação necessária:
Tarefas que podem continuar sem o bloqueio:
Próxima fatia exata:
Estado atualizado dos gates:
```

### 10. Registro de decisão operacional

```text
ID DO/ADR:
Questão:
Informação ou alternativa validada:
Evidência consultada:
Decisão e responsável:
Recursos afetados:
Autorização de execução real, quando necessária:
Prazo/validade/revisão:
Plano de reversão:
Teste exigido para considerar concluído:
```

### 11. Checklist de liberação

- [ ] Inventário real preenchido e responsabilidade definida.
- [ ] Central independente do Sysadm e do domínio de falha monitorado, com limitações registradas.
- [ ] Sondas homologadas nas capacidades efetivamente anunciadas.
- [ ] Políticas locais, credenciais e controles de acesso testados.
- [ ] Estados desconhecidos/comprometidos não exibem verde antigo.
- [ ] IPv4/IPv6 separados e cobertura correta.
- [ ] Replay, dedup, outbox e recuperação de fila comprovados.
- [ ] Upstream mínimo homologado pelo NOC no(s) caminho(s) liberado(s).
- [ ] Canário processado e ausência do vigilante testados.
- [ ] App em iPhone/Android reais e canais autorizados testados.
- [ ] Manutenção/silêncio/escalonamento e conteúdo de alertas revisados.
- [ ] Benchmark, retenção, backup/restore e rollback registrados.
- [ ] RPO/RTO, limites e pendências residuais aceitos explicitamente.
- [ ] Autorização específica de deploy/distribuição obtida.
- [ ] Período de operação supervisionada e responsáveis definidos.

A assinatura deste checklist é um evento operacional futuro. Este arquivo não representa autorização antecipada para alterar rede, publicar aplicativos ou implantar em produção.
