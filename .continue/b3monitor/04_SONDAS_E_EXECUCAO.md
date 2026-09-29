# 04 — Sondas e execução

**Versão:** 1.0 · 28/09/2026  
**Natureza:** RC-01/02/04/06/10/14; mecanismo de agenda/spool é DE-05.

## 1. Ciclo da sonda

Inicializar identidade e política local → verificar capacidades → carregar última configuração válida → reconciliar agenda → persistir execução planejada → executar teste permitido → persistir resultado final → transmitir → remover do spool somente após confirmação durável/decisão terminal registrada.

O coordenador não exige uma chamada da central a cada teste. A central não acessa um shell da sonda. O processo deve continuar medindo durante uma indisponibilidade temporária da central, dentro da validade e dos limites locais.

## 2. Identidade e ativação

Cada instalação recebe identidade individual. O cadastro depende de aprovação. Credenciais de bootstrap são de uso único e expiram; credencial permanente fica vinculada àquela sonda e aos endpoints necessários. Um clone de disco deve receber nova identidade, não apresentar-se simultaneamente como a mesma sonda.

Configuração remota é versionada, autenticada, validada e aplicada atomicamente. Registrar `bundle_revision` do conjunto, `config_generation` por assignment, hash, recebimento, vigência e expiração. Manter a geração de assignments que não foram alterados. Um erro não destrói a última configuração válida; ela permanece até seu prazo. Nunca reativar automaticamente uma revisão expirada.

Identidade, spool e configuração persistem em reinícios. Processo deve possuir exclusão mútua para impedir dois coordenadores executarem a mesma agenda com a mesma identidade. Detectar e reportar identidade duplicada/conflitos na central.

## 3. Agenda

A agenda usa slots absolutos `schedule_epoch + n × interval`. O slot integra a chave natural da medição. O offset de jitter é determinístico por assignment e geração; o horário real de início é registrado separadamente. Quando houver jitter negativo, a antecipação prevista não é erro de relógio: validar `scheduled_at` contra o slot da configuração e `started_at` contra a tolerância do jitter/relógio. Rejeitar timestamps futuros além desses limites, sem rejeitar a antecipação autorizada.

Timeout, tolerância de início, concorrência global e por destino/caminho são limitados. Perder um slot não autoriza executar todos os testes perdidos em rajada nem fingir que foram executados no passado. Registrar `skipped`/lacuna e seguir para o próximo slot válido.

Reinício entre agendamento e conclusão requer reconciliação local. Se não é possível saber se a execução terminou, emitir erro de execução com a identidade original, sem fabricar sucesso. Um resultado já finalizado é imutável e nunca é reexecutado com o mesmo ID.

Evitar sobreposição da mesma verificação. Perfis de caminho com mecanismos sensíveis a concorrência exigem isolamento próprio; não serializar toda a sonda por conveniência. Retentativas de transporte não repetem o probe. Retentativas internas do protocolo devem ser explicitadas no resultado, respeitar timeout total e não inflar as sequências de confirmação.

## 4. Contrato com o executor

O coordenador passa uma configuração normalizada e destino validado a um executor local. O retorno precisa distinguir resultado do alvo, erro de execução, capacidade indisponível e erro de configuração.

Ao usar Blackbox Exporter, o coordenador o consulta localmente, coleta/normaliza a resposta e associa à execução original. Blackbox não faz agenda, buffer, incidentização nem envio de resultados do produto. Não publicar sua porta na rede externa; configuração e módulos locais não podem permitir uma segunda via para sondar destinos proibidos.

O adaptador deve preservar família IP, destino efetivamente conectado, Host/SNI quando aplicável, tempos, status/asserção e erro seguro. Se o executor não expõe a evidência necessária, o contrato exige instrumentação complementar ou classifica a dimensão como não observada. Não preencher o IP efetivo com o primeiro resultado de DNS por aproximação.

## 5. Protocolos do MVP

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

## 6. Spool persistente e backpressure

Guardar envelope, resultado, hash, estado de envio e tentativas em SQLite local, ou substituto transacional justificado. Testar encerramento abrupto, reinício e falta de disco. Reservar espaço para eventos de perda/saúde; logs não podem consumir toda a área do spool.

O limite é simultaneamente temporal e em bytes. A meta inicial é reter duas horas na carga homologada; o tamanho em disco será medido. Ao atingir limite, priorizar entrega de dados correntes e eliminar os mais antigos somente por política explícita. Registrar faixa, quantidade descartada e motivo. Descarte não vira sucesso nem “sem falhas”.

Enviar lotes limitados com backoff exponencial e jitter. Respeitar `Retry-After`; não sobrecarregar uma central recém-recuperada. Uma rejeição definitiva deve ficar em diagnóstico/quarentena local com limite, para não bloquear o restante da fila indefinidamente.

Estados por item: `accepted`, `duplicate`, `retryable`, `rejected_terminal`, `conflict`. Confirmar por item, inclusive em lote parcialmente aceito. HTTP 200 do lote não autoriza apagar todos os itens. A aceitação exige commit durável na central.

## 7. Reenvio organizado

A sonda distingue fluxo corrente de backlog e comunica sessão/manifesto do backlog. O cursor de replay é persistente e retomável. Faixa completa e quantidade esperada permitem à central reconciliar sem disparar por páginas intermediárias.

A central pode solicitar retentativa, mas não mudar o conteúdo de um resultado já finalizado. O histórico tem política própria de aceitação; expurgo da tabela de deduplicação não pode permitir reintroduzir resultados muito antigos como atuais.

Mudança de configuração durante desconexão preserva a geração de cada resultado. Resultados da geração antiga continuam ligados a ela; nunca são reinterpretados como execução da nova configuração.

## 8. Autodiagnóstico e controles

O heartbeat da sonda informa versão/build, revisão aplicada, hash da política local, uptime, última execução, atraso de agenda, tamanho/idade do spool, descartes, disco disponível, capacidades IPv4/IPv6/ICMP e estado do relógio.

Controle de conectividade deve incluir IP literal e nome, em destinos autorizados que não dependam todos da mesma rede da sonda. Manter estado por família e por dependência (DNS, conexão e aplicação). Falha de controle isolado não invalida tudo automaticamente.

O horário é aferido contra referência externa configurada, não apenas contra a central. Registrar método, sincronização e offset; desconhecido não pode aparecer como zero. Medir duração por relógio monotônico.

## 9. Segurança aplicada na borda

A política local define redes, protocolos, portas, tipos de teste, redirecionamentos e referências de segredo permitidos. A configuração recebida é a interseção dessa política com as atribuições da central, nunca sua substituição.

Destinos devem ser resolvidos e validados antes de conectar; a conexão precisa usar o endereço autorizado, sem segunda resolução não controlada pelo executor. Revalidar quando houver mudança de DNS, novo destino ou redirect permitido. HTTP conserva hostname/SNI necessário sem mudar a seleção de IP validado.

Destinos privados de gerenciamento são permitidos somente por configuração local específica. Bloquear metadata, loopback e link-local não autorizados. Um executor local não pode ser acionado por usuários externos para contornar o coordenador. Detalhes no documento 07.

## 10. Privilégios e atualização

Rodar sem root quando possível e sem container privilegiado. A estratégia de ICMP deve ser testada: sockets não privilegiados ou capacidade estritamente necessária, conforme executor/host. Não conceder `NET_ADMIN` ou socket Docker para resolver atalhos de configuração.

Atualizações são controladas, versionadas e reversíveis. O MVP não precisa de auto-update comandado pela central. Binário/imagem e política local só mudam pelo mecanismo administrativo da operação. Não aceitar plugins, scripts ou binários arbitrários no payload de configuração.

## 11. Pronto para homologar a sonda

Demonstrar agendamento local sem central; perda e recuperação de conexão; rejeição de destino proibido; destino efetivo auditável; IPv6 real; erro do executor distinto de falha do alvo; spool após crash; descarte explícito ao esgotar disco; rotação/revogação; replay sem duplicação; capacidade de retornar à versão anterior.

Não marcar como homologada uma capacidade que só foi implementada em mock ou que o host de testes não suporta.
