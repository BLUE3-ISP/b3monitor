# Prompt de execução para o agente

**Uso:** colar o texto abaixo na sessão que possui acesso ao repositório e disponibilizar todos os arquivos deste pacote.

---

Implemente uma plataforma independente de monitoramento distribuído para a Blue3, seguindo estes documentos. Quero implementação incremental com testes e evidências, não apenas mais planejamento.

Comece por `README.md`, `01_PRODUTO_E_ESCOPO.md`, `02_ARQUITETURA_E_STACK.md`, `03_ESTADOS_INCIDENTES_E_REPLAY.md`, `05_UPSTREAMS_E_PROVA_DE_CAMINHO.md`, `07_SEGURANCA.md` e `09_BACKLOG_E_PLANO_DE_ENTREGA.md`. Consulte os contratos e testes antes de programar cada módulo. Use o documento 13 para registrar progresso e pendências reais.

## 1. Antes de alterar

Inspecione o repositório, branch, alterações locais, instruções existentes, ferramentas disponíveis e documentação já implementada. Preserve trabalho existente. Não sobrescreva `AGENTS.md`, regras de projeto ou configurações sem analisar conflitos. Não invente nome final, domínio, IP, ASN, fabricante, versão de roteador ou credencial.

Em projeto vazio, use a direção inicial: central Laravel em monólito modular, PostgreSQL, Redis como transporte de trabalho, coordenador de sonda Go, Blackbox Exporter local avaliado como executor e Flutter para iOS/Android. Docker Compose para desenvolvimento e implantação inicial. Não introduza Kubernetes, microserviços desnecessários, SaaS multiempresa ou integração direta com banco do Sysadm.

Verifique versões suportadas e compatibilidade nos mantenedores oficiais, registre o que escolheu e fixe dependências. Divergência material de stack exige ADR curto, não uma reformulação geral do produto.

## 2. Ordem de execução

Faça primeiro a fatia `F0-01` do backlog: um alvo HTTP real de laboratório, sonda agendada e protegida por política local, persistência durável, decisão testada, incidente, notificação em canal de desenvolvimento e painel mínimo. Depois avance pelos itens desbloqueados da Fase 0.

Construa testes de contrato, motor de estados e falhas antes de ampliar as telas. Inclua upstream mínimo no piloto. Integração rica BGP/interfaces, BMP e novos fabricantes ficam fora da primeira entrega, salvo a leitura mínima indispensável a comprovar o caminho.

Os laboratórios podem usar simuladores e fixtures identificados como tais. Uma demonstração Docker na mesma máquina não comprova diversidade de rede. Um adaptador simulado não homologa upstream real. Ausência de acesso ao NOC bloqueia apenas a homologação dependente desse acesso, não todos os módulos de software.

## 3. Invariantes que não podem ser simplificadas

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

## 4. Autonomia e segurança

Continue nas tarefas desbloqueadas durante a sessão, sem parar a cada etapa apenas para pedir “posso continuar?”. Registre decisões locais razoáveis como DE/ADR. Pergunte apenas por dados, permissões ou escolhas materiais que realmente não possam ser resolvidos no repositório e nos documentos.

Não altere roteamento, filtros, anúncios, equipamentos, DNS ou serviços de produção sem autorização específica e plano de reversão. Não use credenciais produtivas nos testes. Não faça varredura de endereços arbitrários. Não dê push, merge, exclusão de branch, publicação em loja ou deploy real sem autorização de execução compatível com a tarefa e as regras do repositório. Não prometa executar em segundo plano fora da sessão.

## 5. Evidência e entrega

Para cada fatia, entregue código, migrações quando necessárias, testes, documentação operacional e demonstração reproduzível. Registre comando, ambiente, versão/commit, resultado e caminho dos artefatos de teste. Diga explicitamente quando um teste não pôde rodar, inclusive iOS sem ambiente Apple, IPv6 ausente ou equipamento não acessível.

Mantenha `13_CHECKLIST_DO_PILOTO_E_STATUS.md` atualizado sem marcar como validado o que foi apenas codificado. Se precisar encerrar a sessão, deixe o repositório coerente e informe: concluído com evidência, pendente, bloqueio humano concreto e próxima tarefa exata. Não declare o produto em produção apenas porque a aplicação compilou.

Comece agora pela inspeção e pela primeira fatia funcional.

---

## Critério de sucesso desta instrução

A resposta do agente deve estar acompanhada de alterações verificáveis e resultados de testes executados, ou de um bloqueio técnico específico demonstrado. Uma nova lista de intenções, sem tentativa de implementação, não constitui entrega.
