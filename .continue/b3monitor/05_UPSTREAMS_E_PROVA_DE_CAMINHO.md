# 05 — Upstreams e prova de caminho

**Versão:** 1.0 · 28/09/2026  
**Natureza:** RC-08/09/18; mecanismo concreto é DO-NOC, não decisão fechada pelos debates.

## 1. O que o MVP deve entregar

Identificar falha no caminho do upstream A mesmo quando B mantém os serviços públicos acessíveis. A conclusão será sobre o **caminho medido**, com origem, família IP, destinos, validade da evidência e limitações explícitas.

O MVP não precisa de telemetria rica de BGP nem suporte universal a equipamentos. Pode precisar de leitura mínima de rota/interface/política para sustentar sua prova. Essa leitura não deve ser artificialmente adiada se for indispensável.

Sem validação real, a aplicação continua útil para acessibilidade externa, mas o caminho aparece “não homologado”. Um resultado de laboratório não pode mudar o status de validação produtivo.

## 2. Perfil obrigatório por caminho

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

## 3. Exigência de evidência, não receita universal

O perfil deve demonstrar duas propriedades: **seleção do caminho esperado** e **ausência de fallback silencioso**. A implementação precisa manter evidência suficiente de que essas propriedades ainda valem; um teste aprovado no primeiro dia não certifica configurações futuras.

Podem ser usados, conforme o ambiente, isolamento de rede, política de encaminhamento restrita, leitura de configuração/rota, observação do encaminhamento efetivo ou captura limitada em janela. O agente deve propor o menor mecanismo confiável suportado pelo equipamento real e submetê-lo ao teste positivo e aos testes de falha.

Se não for possível observar continuamente todo pacote, documentar o que é atestado em cada medição e o que é revalidado periodicamente. O produto exibirá esse alcance e a validade da evidência. Evidência vencida, contraditória ou insuficiente impede confirmação de saúde corrente daquele caminho.

### 3.1 Evidências insuficientes isoladamente

- IP de origem devolvido por endpoint de eco: identifica a origem vista, não comprova interface de saída.
- Incremento de regra que apenas marca pacotes: pode ocorrer antes da seleção efetiva da rota.
- BGP estabelecido: não substitui o teste do plano de dados.
- Ping por rota padrão: pode ter saído pelo backup.
- AS-path ou traceroute isolado: evidência complementar com limites, não prova absoluta de todo encaminhamento físico.
- “API do roteador respondeu”: não comprova a política em vigor.

### 3.2 Contadores, quando escolhidos

Não transformar `delta == N` em requisito universal do produto. Se forem usados contadores, o adaptador precisa demonstrar unidade (pacotes/bytes), ponto de coleta, exclusividade, correlação com a execução, comportamento com concorrência, reset/wrap, fragmentação, offload/FastTrack e ausência de leitura.

O controle positivo deve demonstrar que o método vê tráfego legítimo. Uma leitura vazia/ausente não é delta zero. Um contador compartilhado não identifica sozinho o fluxo. Não criar regras dinâmicas por teste em produção como atalho, pois isso viola o caráter somente leitura do agente.

Qualquer método baseado em contador só é aprovado para a topologia em que foi ensaiado. O backend recebe classificação/evidência tipada do adaptador; não codifica uma regra matemática única para todos os fabricantes.

## 4. Separar vínculo de caminho, encaminhamento e resposta

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

## 5. Destinos de referência

Não usar um único eco como oráculo de todos os upstreams. Cadastrar pelo menos dois destinos controlados/autorizados em redes distintas, com verificação de sua saúde por outras perspectivas.

O sucesso em um e a falha em outro indicam alcance parcial, não queda total do upstream. Falha de um destino demonstrada por perspectivas independentes abre problema do destino/controle e reduz a cobertura do teste de caminho; não apaga os resultados originais.

Se os dois destinos falharem sem evidência de que estejam saudáveis por outros caminhos, não há base suficiente para atribuição abrangente ao upstream. Mostrar falha observada para os destinos e a limitação. Quando a evidência das referências é indisponível, não manter um verde antigo.

A agregação de destinos é uma política explícita do perfil, separada do quórum externo de sondas. Registrar quantidade de destinos elegíveis/previstos.

## 6. Ida e volta

O escopo mínimo exige saída definida sem fallback. O retorno pode ser assimétrico e isso precisa acompanhar a evidência. Controlar o retorno é uma evolução especializada, não condição universal do MVP.

Não exigir `/24` exclusivo, anúncio BGP de teste, ROA/IRR ou retirada de prefixo para todos os pilotos. Caso o NOC escolha um experimento que dependa disso, elaborar plano específico, verificar dependências reais e obter autorização separada. Não fazer essas mudanças com base em um debate textual.

## 7. Ensaios obrigatórios de Fase 0

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

## 8. Critério de liberação

Um caminho só pode ser mostrado como monitoramento homologado quando: inventário preenchido; desenho revisado pelo NOC; mecanismo de evidência implementado; testes relevantes aprovados; limitações registradas; validade/revalidação operantes; credenciais somente leitura; procedimento de reversão demonstrado.

Sem isso, registrar bloqueio DO no documento 13. O resto do aplicativo pode seguir em desenvolvimento. O rótulo de produção não pode ser liberado por fixture, contador simulado ou resposta de eco isolada.
