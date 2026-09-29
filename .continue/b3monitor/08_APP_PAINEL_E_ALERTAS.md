# 08 — App, painel e alertas

**Versão:** 1.0 · 28/09/2026  
**Natureza:** experiência consolidada RC; campos/interações detalhados como DE-08.

## 1. Objetivo operacional

Abrir o celular e entender situação, evidência, cobertura e atendimento sem precisar interpretar dezenas de métricas. O dashboard não pode ocultar medição inválida atrás de uma cor verde.

Idioma inicial pt-BR; fuso inicial America/Sao_Paulo com horários UTC no backend. Texto, ícone e cor comunicam estado em conjunto. Usar contraste adequado e não depender de distinguir tons próximos. Não se exige identidade visual final para o piloto.

## 2. Telas mobile da Fase 1

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

### 2.1 Cartão de serviço

Mostrar nome amigável, saúde com abrangência, IPv4/IPv6 quando previstos, contagem de perspectivas válidas/previstas e idade da medição. Integridade comprometida ou cobertura insuficiente deve ter destaque comparável ao de indisponibilidade.

Exemplo de texto correto: “Indisponível nas 2 origens válidas; cobertura 2/3”. Texto incorreto: “Internet fora”. Exemplo de teste suspeito: “Falha em confirmação”; não apenas verde do último estado.

### 2.2 Cartão de upstream

Separar “serviços públicos acessíveis” de “caminho A validado”. Campos que não foram implementados, como BGP detalhado, aparecem como “não coletado” quando relevantes; não preencher `Established`, tráfego zero ou ícone saudável por padrão.

Se a medição de A perde integridade: “Caminho A sem avaliação confiável; última evidência válida às ...”. Um check externo via backup não pode recuperar esse cartão.

## 3. Ações e concorrência

Reconhecer registra usuário/horário; assumir altera responsável; comentar acrescenta evento; silêncio interrompe avisos no escopo e prazo permitido; manutenção é janela administrativa. Nenhuma dessas ações melhora a saúde técnica.

Enviar ação com chave de idempotência e versão quando apropriado. Enquanto aguarda servidor, exibir “enviando”, não “concluído”. Timeout pode significar ação efetivada: consultar pelo identificador antes de repetir com nova chave. Conflito de edição exige atualização da tela e mensagem clara.

Perfil de leitura nunca realiza essas ações. Limitar frequência de reexecução manual, respeitar política local e concorrência da sonda. Execução manual fica marcada e fora do contador automático de falhas/recuperação por padrão.

## 4. Cache e uso offline

Guardar snapshot mínimo autorizado. Exibir faixa persistente “sem conexão — dados de ...”. Não usar horário em que a tela abriu como horário da medição. Snapshot vencido perde aparência de estado atual mesmo que o aplicativo ainda consiga abrir.

No MVP, não enfileirar silenciosamente reconhecimento, silêncio ou manutenção para executar horas depois. A ação só é apresentada como efetivada após confirmação. Ao reconectar, atualizar snapshot e incidentes antes de oferecer ações sensíveis.

O monitoramento continua nas sondas com o app fechado, aparelho desligado ou sem rede. Não depender de tarefa de fundo mobile para decidir incidentes.

## 5. Painel web

Cadastro de ativos e checks, sondas, domínios de observação, políticas, perfis de caminho, destinatários, usuários e janelas. Revisões têm diff sanitizado e vigência. Adicionar dashboard com atualização e filtros, sem criar aplicação NOC separada.

Sonda cadastrada ainda não homologada deve exibir esse estado. Formulário de path profile deve tornar visível cada dependência DO. Sucesso de uma requisição de cadastro não equivale a execução bem-sucedida nem a prova do caminho.

Histórico oferece gráficos básicos por perspectiva/família e tabela de incidentes. Relatórios exibem cobertura e integridade junto da disponibilidade. Exportação sanitizada opcional, sem abrir escopo de relatórios comerciais.

## 6. Canais de notificação

Fase 0: canal de desenvolvimento sem envio externo automático, depois Telegram configurado e autorizado. Fase 1: push do app e Telegram. E-mail é opcional e não bloqueia o núcleo; escalas completas de plantão ficam na Fase 2.

Para criticidade alta, permitir dois canais simultâneos. Não tentar inferir falha de push pela ausência de leitura para só então enviar o outro canal. Registrar tentativas, aceite pelo provedor, erro, retentativa, cancelamento por política e motivo de supressão.

Push não oferece garantia do recebimento no aparelho. Separar as métricas de decisão, tentativa e aceite do provedor da leitura humana. O reconhecimento explícito é sinal de atendimento, não o retorno da API do provedor de push.

## 7. Eventos notificáveis

Abertura confirmada, agravamento material atual, recuperação confirmada, entrada em instabilidade, perda de cobertura relevante, integridade comprometida e problema da plataforma. Reabertura dentro da janela mantém episódio e produz evento próprio conforme política; não gerar um novo incidente só para alterar o texto.

O replay segue integralmente o documento 03. Incidente antigo encerrado não desperta plantão como queda atual; um agravamento atual ainda relevante pode gerar aviso consolidado. Não duplicar aviso já decidido por reinício de worker.

Agrupamento é por incidente/escopo explícito. Silêncio de um serviço não apaga incidente de integridade da plataforma. Manutenção de ativo não silencia canário ou ausência do vigilante.

## 8. Payload e abertura do app

Payload externo contém título amigável, gravidade, referência opaca de incidente e mensagem mínima. Topologia, IPs internos, credenciais, corpo completo de resposta e detalhes sensíveis ficam na API autorizada. Política de privacidade de conteúdo deve permitir mensagem genérica em tela bloqueada.

Ao abrir, autenticar e consultar a situação atual. Uma notificação antiga pode levar a incidente recuperado; o app deve mostrar isso, não reaplicar o estado antigo do payload. Usuário sem acesso recebe resposta adequada, sem vazamento de existência/detalhes.

## 9. Escalonamento mínimo

Proposta herdada do escopo: segundo responsável após cinco minutos sem reconhecimento em incidente crítico. Só ativar quando os destinatários e a política estiverem configurados e testados. O prazo é parâmetro de operação, não regra imutável.

Não confundir esse escalonamento simples com gestão completa de turnos/plantões. Reconhecimento pode interromper escalonamento de não atendimento, mas não impede alerta de agravamento conforme política. Ausência de destinatário válido gera problema operacional visível; não fingir que o alerta foi enviado.

## 10. Manutenção e silêncio

Obrigatórios início/fim, autor, justificativa e escopo. Extensão é alteração auditada. Ao terminar, recalcular a política sobre o estado atual: falha persistente e ainda relevante pode notificar uma vez; não despejar todas as mensagens suprimidas.

Notificações de recuperação durante silêncio/manutenção seguem política declarada. Estados e eventos continuam registrados. Não permitir janela sem término nem edição retroativa silenciosa para alterar indicador.

## 11. Aceite mobile real

Testar em iPhone e Android reais: app em primeiro plano, fechado, retorno por push, permissão negada, aparelho sem conexão, token rotacionado, logout/troca de usuário, ação com resposta perdida e incidente recuperado antes da abertura.

Builds distribuídos para teste devem ter ambiente explícito e não misturar tokens de sandbox/produção. Assinatura, contas de distribuição, credenciais push e autorização de publicação são DO. Sem esses acessos, registrar “não homologado”; build/emulador não comprova recebimento real.
