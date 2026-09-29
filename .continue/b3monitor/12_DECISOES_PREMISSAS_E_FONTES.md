# 12 — Decisões, premissas e fontes

**Versão:** 1.0 · 28/09/2026  
**Objetivo:** distinguir consenso de produto, detalhamento de engenharia e informação ainda ausente.  
**Limite:** não foi feita nova pesquisa externa nesta consolidação. Referências técnicas herdadas são pontos de consulta; versões/capacidades deverão ser verificadas pelo agente na implementação.

## 1. Material de origem

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

## 2. Decisões consolidadas e origem

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

## 3. Propostas rejeitadas, limitadas ou adiadas

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

## 4. Detalhamentos acrescentados neste pacote — DE

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

## 5. Dependências operacionais ainda não fornecidas — DO

Nome final, repositório/branch alvo, IPs/domínios/ASNs reais, provedores das VMs, hardware, plataforma/versão da borda, acessos de leitura, desenho de encaminhamento, destinos autorizados, limites de qualidade, capacidade IPv6, credenciais de canais, destinatários, ambiente Apple/Android, método de distribuição, backup/RPO/RTO e autorizações de produção.

Essas lacunas não foram preenchidas por conhecimento genérico ou por dados de outros projetos. O formulário está no documento 13. Laboratório pode prosseguir com dados fictícios, desde que não sejam apresentados como infraestrutura real.

## 6. Referências técnicas herdadas para consulta

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

## 7. Alterações em relação ao escopo 0.1

O pacote retém produto e arquitetura, reduz a exigência inicial de BGP/interfaces ricos, aprofunda integridade/controles/replay, separa validade atual e buffer, condiciona upstream à topologia e mantém banco especializado opcional. Acrescenta backlog, contratos, testes e procedimentos, explicitamente como detalhamento de engenharia.

Essas alterações são a consolidação pedida; não é transcrição integral dos debates. Os originais permanecem como registro histórico, mas não prevalecem sobre a interpretação crítica e os recortes deste pacote.
