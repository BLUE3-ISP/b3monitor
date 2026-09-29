# 13 — Checklist do piloto e status

**Versão:** 1.0 · 28/09/2026  
**Uso:** arquivo vivo a ser atualizado pelo agente e pelo responsável operacional.  
**Estado inicial:** documentação criada; implementação, testes de aplicação e implantação ainda não executados neste pacote.

## 1. Situação inicial

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

## 2. Identidade e responsabilidades

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

## 3. Inventário de sondas

As linhas abaixo são posições propostas, não VMs existentes.

| Posição | Papel | Provedor/ASN/região | IPv4/IPv6 comprovados | Domínio/dependências | Status |
|---|---|---|---|---|---|
| EXT-A | Externa | PENDENTE | PENDENTE | PENDENTE | Não provisionada |
| EXT-B | Externa | PENDENTE | PENDENTE | PENDENTE | Não provisionada |
| EXT-C | Externa | PENDENTE | PENDENTE | PENDENTE | Não provisionada |
| INT-A | Interna | PENDENTE | PENDENTE | PENDENTE | Não provisionada |

Por sonda, registrar host/SO, versão coordenador/executor, identidade, revisão da política local, referência de credencial, disco/spool, NTP, redes permitidas, contatos de manutenção e evidência de `probe-doctor`. Informações de acesso sensíveis ficam em inventário protegido, não neste pacote distribuído ao agente sem necessidade.

## 4. Central e vigilância

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

## 5. Perfil de upstream — duplicar para cada caminho/família

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

## 6. Ativos do piloto

Selecionar aproximadamente 10–20 representativos; o número é proposta do escopo, não obrigação de preencher com alvos artificiais. Incluir aplicação pública, DNS, serviço dual-stack quando previsto, equipamento privado autorizado e caminhos upstream disponíveis.

| Ativo | Tipo/criticidade | Check/protocolo/família | Perspectivas/perfil | Critério/limites | Responsável |
|---|---|---|---|---|---|
| PENDENTE | PENDENTE | PENDENTE | PENDENTE | PENDENTE | PENDENTE |

Os destinos de controle não devem ser preenchidos automaticamente com serviços públicos sem considerar autorização, política e dependências. Levantar limites de latência/perda por contexto; não inventar um limite global para todos os links.

## 7. Mobile e canais

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

## 8. Quadro de gates

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

## 9. Registro de execução do agente

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

## 10. Registro de decisão operacional

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

## 11. Checklist de liberação

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
