# Blue3 — Monitoramento distribuído (slug de trabalho: `b3mon`)

**Pacote de especificação 2.0 · 28/09/2026 · em elaboração**
**Situação:** especificação para iniciar desenvolvimento. Não é software implementado nem implantação homologada. Todo documento carrega a marca **Planejamento — não implementado** até haver código.
**Nome do produto:** pendente. `b3mon` é slug de trabalho para diretórios, units e tabelas. Não confundir com ShvIA nem com o `sys` (intranet Blue3).

## Começar por aqui

Entregue este diretório ao agente no repositório do novo projeto e use [00 — Prompt para o agente](00_PROMPT_PARA_O_AGENTE.md). O agente constrói o produto incrementalmente, começa pelo ciclo real de medição e não para na criação de outra proposta de arquitetura.

A central fica fora da infraestrutura monitorada e é independente do `sys`. As sondas medem continuamente; o celular consulta, recebe alertas e registra atendimento. O piloto inclui **upstream mínimo verificável**; telemetria rica de BGP/interfaces fica para evolução, exceto a leitura indispensável à validação do caminho.

## Ordem de leitura

1. Este README e o [14 — Glossário e convenções](14_GLOSSARIO_E_CONVENCOES.md): vocabulário, enums, palavras normativas.
2. [01](01_PRODUTO_E_ESCOPO.md) → [02](02_ARQUITETURA_E_STACK.md) → [03](03_ESTADOS_INCIDENTES_E_REPLAY.md) → [06](06_MODELO_DE_DADOS_E_CONTRATOS.md): o que é, como se organiza, como decide, como fala.
3. [04](04_SONDAS_E_EXECUCAO.md) → [05](05_UPSTREAMS_E_PROVA_DE_CAMINHO.md) → [07](07_SEGURANCA.md): sonda, caminho, limites.
4. [09](09_BACKLOG_E_PLANO_DE_ENTREGA.md) → [10](10_TESTES_E_CRITERIOS_DE_ACEITE.md) → [13](13_CHECKLIST_DO_PILOTO_E_STATUS.md): o que fazer, como provar, o que ainda falta.
5. [08](08_APP_PAINEL_E_ALERTAS.md), [11](11_IMPLANTACAO_OPERACAO_E_RUNBOOKS.md) e [12](12_DECISOES_PREMISSAS_E_FONTES.md) conforme a fatia em andamento.

## Mapa

| Documento | O que resolve |
|---|---|
| [00 — Prompt para o agente](00_PROMPT_PARA_O_AGENTE.md) | Ordem de trabalho, autonomia, limites operacionais, invariantes (única repetição autorizada) |
| [01 — Produto e escopo](01_PRODUTO_E_ESCOPO.md) | Requisitos RC, invariantes INV, fases, exclusões, fronteira com o `sys` |
| [02 — Arquitetura e stack](02_ARQUITETURA_E_STACK.md) | Componentes, stack fixada, repositório, fronteiras de confiabilidade, comandos |
| [03 — Estados, incidentes e replay](03_ESTADOS_INCIDENTES_E_REPLAY.md) | Fonte normativa de saúde, cobertura, integridade, anti-flap e notificação após replay |
| [04 — Sondas e execução](04_SONDAS_E_EXECUCAO.md) | Agenda, identidade, executor nativo, namespaces, spool, relógio, privilégios |
| [05 — Upstreams e prova de caminho](05_UPSTREAMS_E_PROVA_DE_CAMINHO.md) | O que precisa ser provado, menu de mecanismos, o que depende da topologia real |
| [06 — Modelo de dados e contratos](06_MODELO_DE_DADOS_E_CONTRATOS.md) | Entidades, payloads, endpoints, idempotência, retenção |
| [07 — Segurança](07_SEGURANCA.md) | Autorização, credenciais, SSRF, política local, endurecimento systemd, auditoria |
| [08 — App, painel e alertas](08_APP_PAINEL_E_ALERTAS.md) | Módulo mobile, painel CoreUI, ações, canais |
| [09 — Backlog e entrega](09_BACKLOG_E_PLANO_DE_ENTREGA.md) | Fatias, dependências, gates, definição de concluído |
| [10 — Testes e aceite](10_TESTES_E_CRITERIOS_DE_ACEITE.md) | Casos T-*, ensaios UP-*, carga, rastreabilidade INV → T |
| [11 — Implantação e operação](11_IMPLANTACAO_OPERACAO_E_RUNBOOKS.md) | Ambientes, systemd, vigilância, backups, runbooks RB-* |
| [12 — Decisões, premissas e fontes](12_DECISOES_PREMISSAS_E_FONTES.md) | Origem das decisões, DE, DO, referências |
| [13 — Checklist e status](13_CHECKLIST_DO_PILOTO_E_STATUS.md) | Inventário real, quadro de gates, checklist de liberação |
| [14 — Glossário e convenções](14_GLOSSARIO_E_CONVENCOES.md) | Prefixos, termos, enums canônicos, escrita, repositório |
| `CHANGELOG.md` | Mudanças entre versões do pacote |
| `docs/status/SESSOES.md` | Registro de sessões do agente (append-only) |
| `docs/adr/` | Decisões de arquitetura no formato de [14 §5] |

## Como interpretar

- **RC — requisito consolidado:** o que o produto precisa fazer. Muda só por decisão de produto.
- **INV — invariante:** regra que nenhuma implementação pode relaxar. Cada teste cita as INV que cobre.
- **DE — decisão inicial de engenharia:** detalhamento para tornar implementável. Ajustável por ADR com justificativa e teste.
- **DO — dependência operacional:** informação, credencial, autorização ou evidência do ambiente real. Não se inventa nem se substitui por mock na homologação.
- **D — decisão de revisão:** escolha fechada na passagem 1.0 → 2.0 (registrada em [12 §4] e como ADR no repositório).

Valores numéricos são parâmetros iniciais de homologação, não SLA. Versões de dependências são fixadas pelo agente em F0-00 e registradas em ADR.

## Precedência

Em divergência interna prevalecem, nesta ordem: limites de segurança do 07; semântica de estados do 03; prova de caminho do 05; contratos do 06; fases do 09. O glossário 14 define os enums; um documento que use valor fora do 14 está errado, não o 14. Corrigir o documento conflitante no mesmo trabalho.

Parâmetros ficam em um lugar só: a matriz do [03 §12], espelhada em perfis versionados no código. Nenhum número copiado em outro documento é fonte.

## Primeira entrega esperada

Um caminho executável em laboratório: **alvo HTTP → sonda com política local → ingestão durável → motor de estado → incidente → notificação em canal `dev` → painel mínimo**, com testes de sucesso, falha, ausência, reenvio e duplicação. Depois: perspectivas independentes, IPv6, upstream mínimo e vigilância. O módulo mobile entra na Fase 1.

## O que ainda não existe

Código, infraestrutura provisionada, credenciais, inventário real, benchmark, aceite do NOC. Os comandos e contratos descritos são entregáveis. Os campos em aberto estão no 13 e não impedem o laboratório.

## Consolidado

O arquivo único é gerado por `make docs-bundle` em `dist/`, para colar em um chat. Não é fonte de verdade e não é versionado. O agente lê os arquivos modulares.
