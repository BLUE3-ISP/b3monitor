# 01 — Produto e escopo

**Versão documental:** 1.0 · 28/09/2026  
**Natureza:** RC; detalhamentos de recorte identificados na matriz de fases.  
**Objetivo:** permitir que a operação saiba o que foi observado, de onde, com qual confiabilidade e qual atendimento está em andamento.

## 1. Problema a resolver

A Blue3 precisa acompanhar aplicações, equipamentos e conectividade por sondas em VMs externas e internas. Serviços podem continuar acessíveis quando um upstream falha, porque o backup assume o tráfego. Uma sonda também pode perder conectividade e produzir sintomas semelhantes a falha do alvo.

O produto deve distinguir essas situações sem prometer observação de toda a internet ou causa raiz automática. A central ficará fora da infraestrutura monitorada, com suas dependências e ponto único de falha declarados. Uma vigilância independente verificará o monitoramento.

## 2. Usuários e interfaces

| Papel | Necessidade |
|---|---|
| Gestor | Entender impacto, serviços afetados, cobertura e redundância. |
| Operador/NOC | Receber alerta, consultar evidências, reconhecer, assumir e registrar atendimento. |
| Administrador | Gerenciar sondas, ativos, políticas, usuários e destinos autorizados. |
| Leitura | Consultar apenas os recursos autorizados, sem ações operacionais. |

O app iPhone/Android é a interface de plantão e consulta rápida. O painel web é a interface de configuração e investigação; seu dashboard com atualização atende a visão inicial de NOC. Não haverá terceira aplicação dedicada a NOC no MVP.

## 3. Requisitos consolidados rastreáveis

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

## 4. Matriz de escopo por fase

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

## 5. Entidades do domínio

**Ativo:** serviço, aplicação, equipamento ou circuito. Contém nome amigável, descrição, tipo, POP/grupo, criticidade, responsável e etiquetas.

**Verificação:** aspecto observável de um ativo, com protocolo, destino, família IP explícita, critério de sucesso e perfil de medição. Um ativo pode possuir várias verificações.

**Assignment:** vínculo versionado entre verificação, sonda, agenda e caminho opcional. É a unidade executável. A separação evita tratar três medições de uma verificação como três ativos.

**Perspectiva:** combinação de origem/sonda, família IP e caminho aplicável. A diversidade registrada limita o quórum; quantidade de VMs não demonstra independência física absoluta.

**Resultado:** observação imutável com execução, integridade e evidências. Não confundir resultado com incidente nem ausência de resultado com timeout do alvo.

**Incidente:** episódio operacional com identidade estável, classe, gravidade, alcance, evolução e estado de atendimento.

**Caminho de upstream:** perfil de encaminhamento e evidência homologado pelo NOC, separado da simples configuração de destino.

## 6. Observações mínimas por tipo

| Tipo | O que pode afirmar | O que não pode afirmar sozinho |
|---|---|---|
| ICMP | Houve resposta/RTT; sucesso das amostras | Aplicação de negócio funcional |
| TCP | Conexão na porta | Resposta correta da aplicação |
| HTTP/API | Resposta e asserção configurada | Todas as jornadas de negócio funcionam |
| DNS | Resposta do servidor consultado | Todos os resolvedores respondem igual |
| TLS | Validação e vencimento observado | Disponibilidade integral da aplicação |
| Upstream | Observação do caminho definido | Causa comercial do defeito ou retorno simétrico não medido |

HTTP básico deve usar operações sem efeitos de negócio; nenhum teste executa compras, alterações de cadastro ou scripts livres. Endpoints públicos atrás de proxy/CDN e suas origens são verificações distintas.

## 7. Exemplos de produto

1. Serviço responde em IPv4, falha em IPv6: exibir dimensões separadas e degradação do serviço dual-stack, não saúde global.
2. Upstream A falha em caminho homologado, B permanece disponível e serviços públicos respondem: “serviços acessíveis; caminho A com falha; redundância reduzida”.
3. Evidência do caminho A fica inválida: “situação do caminho A não confirmada; integridade do monitoramento comprometida”. Sucesso pelo B não recupera A.
4. Duas de três sondas param de enviar: “cobertura insuficiente”; não declarar todos os alvos indisponíveis.
5. Operador reconhece incidente: atendimento registrado; o estado técnico continua sendo calculado.

## 8. Fora do MVP

SaaS multiempresa e cobrança; NMS completo; descoberta de toda a rede; coleta de todas as ONUs; análise integral BGP; NetFlow/sFlow; testes contínuos de velocidade; shell remoto; scripts arbitrários; reboot; remediação automática; alterações automáticas de BGP; chamados completos; página pública de status; IA como autoridade de causa raiz; Kubernetes; suporte universal a fabricantes.

Uma interface preparada para novos executores não é autorização para implementar todos agora. A aplicação deve admitir evolução sem antecipar toda a complexidade comercial.

## 9. Critério de valor da primeira versão

A primeira versão será útil quando um operador conseguir diferenciar falha real, alcance parcial, redução de redundância e incapacidade de medição, receber os alertas relevantes e registrar atendimento pelo celular. Quantidade de telas ou conectores não substitui essa comprovação.
