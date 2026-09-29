# 03 — Estados, incidentes e replay

**Versão:** 1.0 · 28/09/2026  
**Natureza:** princípios RC; algoritmos, enums e parâmetros são detalhamento DE-03/DE-04.  
**Autoridade:** este documento define a semântica do motor. A interface não pode reinterpretá-la.

## 1. Dimensões independentes

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

## 2. Tipos de resultado e ausência

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

## 3. Elegibilidade da observação

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

## 4. Confirmação por perspectiva

A unidade de estado é a verificação em uma perspectiva e geração do assignment. A revisão do pacote de configuração da sonda não reinicia assignments inalterados. Ordenar por instante agendado, não por ordem de recebimento.

- Inicialização: estado `unknown` até a sequência inicial de confirmações válidas.
- `failure_count` falhas válidas em slots consecutivos confirmam `down`.
- `recovery_count` sucessos válidos consecutivos confirmam `up` ou recuperação.
- Amostra ausente/inválida ou troca de geração interrompe a sequência. Não contar três falhas espaçadas como três consecutivas.
- Antes da confirmação, manter o último estado confirmado somente se ainda elegível e expor `pending_failure`/`pending_recovery`. A UI deve escrever “falha em confirmação”/“recuperação em confirmação”, nunca apenas um verde.
- Erro de integridade ou incapacidade de medir torna a perspectiva corrente `unknown`; o último estado confirmado permanece histórico.
- Perda de dados não resolve um incidente já aberto.

Degradação de desempenho usa limites explicitamente configurados para o par verificação/perspectiva e sequência de confirmação própria. Não criar limite universal de latência. Percentual de falhas HTTP não é perda de pacotes. Perda ICMP só será calculada com contagens reais e amostragem mínima publicada; na ausência dela, mostrar “amostragem insuficiente”.

## 5. Cobertura, diversidade e quórum

Cada assignment pertence a um domínio de observação configurado. Não criar votos adicionais por reiniciar uma sonda ou duplicar uma VM no mesmo domínio. O cadastro registra provedor, ASN, região e dependências conhecidas; isso é diversidade documentada, não prova de independência física total.

Política externa inicial: três perspectivas previstas e mínimo de dois domínios elegíveis para uma conclusão agregada. Política interna/de caminho pode ter mínimo de um, explicitamente restrita àquela observação. Não aplicar quórum externo de três ao teste de um único caminho interno.

Se duas sondas no mesmo domínio discordarem, preservar as duas observações; esse domínio não fornece voto unânime de sucesso/falha. Mostrar conflito e não usá-lo para completar um quórum de queda abrangente. Não reduzir automaticamente o mínimo quando sondas desaparecem.

**Cobertura completa:** todas as perspectivas previstas possuem evidência recente e elegível.  
**Reduzida:** parte está ausente/inapta, mas o mínimo configurado permanece satisfeito.  
**Insuficiente:** o mínimo não é satisfeito.

Além das contagens de sondas, exibir domínios válidos/previstos. Comparar confirmações em janelas temporais sobrepostas: uma confirmação antiga e outra atual não criam consenso. A evidência da confirmação deve guardar início/fim da sequência; o avaliador só usa intervalos de sustentação atuais e sobrepostos, com tolerância de agenda explicitamente limitada pelo perfil.

### 5.1 Tabela de decisão externa

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

## 6. Controles das sondas e integridade

Cada sonda mede controles autorizados e independentes dos ativos Blue3: pelo menos um por IP literal e um por nome; quando viável, dois provedores/destinos diferentes. A saúde desses controles também tem frescor e sua própria evidência.

- Controle por nome falha e por IP funciona: suspeita de resolução; não invalidar automaticamente todos os testes por IP literal.
- Todos os alvos e controles de conectividade de uma sonda falham: reduzir confiança daquela perspectiva, preservar todas as falhas e abrir problema de monitoramento/origem. Não declarar o provedor inteiro fora apenas por essa coincidência.
- Controles respondem e alvos falham: evidência fortalece o diagnóstico de falha nos alvos, sem demonstrar causa raiz.
- Um controle falha em diversas origens, outros controles funcionam: tratar possível falha do controle; não invalidar toda a plataforma.

Não existe condição circular “o alvo caiu, então a sonda é inválida”. A inelegibilidade precisa de motivo independente identificável e ser aplicada somente às medições afetadas. Controles sem evidência suficiente tornam a confiança `unknown`/`suspect`, não comprovam que a VM morreu.

## 7. Incidentes e anti-flap

Classes iniciais: `service`, `upstream_path`, `monitoring_coverage`, `monitoring_integrity`, `platform` e `job_heartbeat`. Uma classe pode agrupar eventos correlacionados sem apagar evidências de outras classes.

O incidente contém `observed_start_at`, `confirmed_at`, escopo/família, gravidade, estado técnico, reconhecimento, responsável, fim observado, fim confirmado e linha do tempo. Início observado e instante de detecção são diferentes.

A chave de correlação usa escopo, regra/família e classe, não só o alvo IP. Eventos consecutivos do mesmo episódio reutilizam identidade. Reconhecimento não muda o estado técnico. Não permitir “fechar como resolvido” uma falha ainda confirmada sem que permaneça claramente sinalizada; no MVP a recuperação técnica é automática por evidência.

### 7.1 Política de oscilação inicial

- Recaída dentro de `reopen_window_s` reabre o mesmo episódio e registra nova transição.
- Quatro transições técnicas confirmadas em dez minutos marcam `unstable` no perfil inicial.
- A entrada em instabilidade gera notificação própria deduplicada; não é silêncio permanente.
- Saída da instabilidade exige `stable_recovery_count` sucessos válidos consecutivos, maior que a recuperação comum.
- Instabilidade não mascara queda: saúde `down` e estabilidade `unstable` podem coexistir.
- Lacunas/medições inválidas não alimentam contagem de flap nem estabilidade.

A correlação por dependência só suprime avisos secundários quando a regra é explícita e auditável. O MVP inclui grupos simples de redundância e agrupamento; não inferir causa por IA ou por coincidência temporal.

### 7.2 Integridade persistente e quarentena lógica

A primeira contradição comprovada invalida imediatamente aquela observação e fica visível. Após a sequência configurada, a verificação pode entrar em quarentena lógica para impedir votos/alertas de saúde baseados no instrumento defeituoso.

A coleta continua. O incidente de integridade permanece visível e seus avisos são deduplicados; não silenciar o defeito junto com o ruído de saúde. Não manter último verde como situação atual.

A saída exige três execuções consecutivas com integridade válida. Essas execuções podem ter outcome `fail`: instrumento consertado não significa link recuperado. Após recuperar integridade, reavaliar a sequência recente de resultados válidos; só resultados `pass` contam para saúde/estabilidade positiva. Um contador bom isolado não retira a quarentena.

## 8. Redundância

Grupos simples definem membros, quantidade mínima requerida e quantidade desejada de caminhos válidos. Não derivar saúde do upstream pelo resultado de serviços externos.

Exemplo A/B com mínimo 1 e desejado 2: A falha de forma válida, B funciona → redundância reduzida. A desconhecido e B funciona → redundância não confirmada, com conectividade observada via B. Ambos falham de forma válida → caminhos observados indisponíveis. Não inferir impacto em todos os clientes sem medições desses serviços.

A classificação de redundância é condicionada ao que foi realmente cadastrado e medido; não inventar um circuito de backup a partir de resposta pública.

## 9. Replay: histórico, atualidade e alertas

### 9.1 Identidade e reordenação

Chave natural de resultado agendado: `(probe_id, assignment_id, config_generation, scheduled_at)`. Cada assignment fixa família e caminho. O resultado possui também `result_id` estável, gerado antes da execução e persistido localmente.

Reenvio do mesmo conteúdo retorna duplicado aceito. Mesma chave com conteúdo incompatível gera conflito auditável e não sobrescreve a observação original. Tentativas internas de uma execução não criam resultados conflitantes: formam evidências do mesmo resultado final. Testes manuais têm identidade/tipo distintos e não entram por padrão nas sequências agendadas.

### 9.2 Três janelas diferentes

1. **Buffer local:** quanto a sonda consegue reter sem central.
2. **Aceitação histórica:** idade máxima de resultado que a central aceita e pode reconciliar.
3. **Frescor:** idade máxima para determinar a situação presente.

Elas não são iguais. Um resultado de uma hora pode reconstruir uma falha histórica, mas não confirmar o estado atual de um teste de 30 segundos. Uma medição recente que veio no mesmo replay pode ser elegível para o presente. O buffer de duas horas não impõe frescor de duas horas.

### 9.3 Processamento da reconexão

Separar envio corrente de backlog; priorizar dados atuais para não bloquear a situação presente por horas de histórico. O backlog usa sessão/manifesto de replay com faixa e quantidade esperada. Persistir lotes sem disparar avisos intermediários das observações históricas.

Após receber uma faixa completa, reordenar, recompor incidentes afetados e reconciliar com o snapshot corrente. Usar políticas e configurações vigentes no horário observado, não aplicar retroativamente uma regra nova.

Sessão incompleta não segura os dados correntes indefinidamente: após timeout, marcar histórico pendente/lacuna, manter alertas baseados na faixa corrente completa e continuar reconciliação histórica quando o restante chegar. Nunca abrir e fechar alerta crítico artificialmente por páginas intermediárias do replay.

Eventos que se sobrepõem a um episódio já conhecido mantêm sua identidade. Se a reconstrução unir episódios, conservar um ID canônico e aliases auditáveis; não apagar reconhecimentos, comentários nem tentativas de envio.

### 9.4 Decisão de notificação após reconciliação

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

## 10. Manutenção, silêncio e relatórios

Manutenção exige início, término, autor, justificativa e escopo. A coleta continua. A política de notificação consulta a janela; a decisão técnica não é reescrita. Não permitir excluir canário/vigilante por manutenção de ativos.

No MVP, não disponibilizar edição retroativa para melhorar indicadores. Correção histórica excepcional exige registro separado, justificativa, aprovador distinto e preservação da visão original; pode ser tratada por procedimento controlado antes de existir UI específica.

Relatórios devem publicar dois eixos: condição administrativa (dentro/fora de manutenção) e qualidade da observação (elegível, sem cobertura suficiente ou integridade comprometida). Dentro do tempo elegível, mostrar duração `up`, `degraded`, `down`. Assim manutenção e falta de cobertura não são somadas duas vezes inadvertidamente.

Indicador técnico estrito sugerido: `duração up / duração elegível não excluída`. Mostrar ao lado fração elegível sobre período total, tempo em manutenção, desconhecido e comprometido, inclusive suas interseções quando existirem. Degradação não é automaticamente contada como saúde estrita. Denominador zero resulta “sem observação suficiente”, não 100%. Não vender o indicador como SLA contratual.

## 11. Relógio e mudanças de configuração

Persistir em UTC e apresentar inicialmente em America/Sao_Paulo. Durações usam relógio monotônico. Sonda e central reportam sincronização contra referência externa configurada; concordar entre si não basta.

Resultados com offset fora da tolerância, sincronização desconhecida ou timestamp futuro indevido são preservados, mas não têm voto temporal corrente até validação. Não corrigir timestamps históricos silenciosamente. Mudança de política/caminho/agenda gera revisão com vigência; não misturar sequências de gerações incompatíveis.

## 12. Parâmetros iniciais de homologação — DE-03

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
