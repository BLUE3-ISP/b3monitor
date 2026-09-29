# 07 — Segurança

**Versão:** 1.0 · 28/09/2026  
**Natureza:** RC-14; controles de implementação detalhados como DE-07.  
**Limite operacional:** monitorar e diagnosticar; não alterar equipamentos automaticamente.

## 1. Modelo de ameaça mínimo

Proteger segredos de gerenciamento, rede privada, integridade das medições, contas operacionais e capacidade de alerta. Considerar central comprometida, usuário abusando de cadastro/manual run, sonda comprometida, origem falsificando resultados, destino malicioso/redirecionamento, replay conflitante e vazamento por logs/push.

Este escopo não promete resistência absoluta a uma sonda ou roteador totalmente comprometido. O sistema limita danos, mantém proveniência, reduz confiança em evidências contraditórias e registra incidentes de integridade. Não confundir TLS com prova da honestidade do conteúdo recebido.

## 2. Limites de confiança

A central distribui tarefas, mas não amplia a política de egress local. Uma sonda só executa na interseção entre atribuições autenticadas e sua autorização local. O mobile jamais possui credenciais administrativas de equipamentos. Leitura de interface/rota utiliza credencial restrita; o serviço não tem permissão de escrita de configuração.

O endpoint de executor local constitui uma interface privilegiada. Deve aceitar chamadas somente do coordenador e permanecer inacessível pela rede pública e pela rede de usuários. Credencial de sonda não permite acessar recursos de outras sondas nem funções administrativas da central.

## 3. Identidade e autorização

Perfis: administrador, operador e leitura. Autorizar no servidor por ação e recurso; esconder botão não é autorização. Operações sensíveis têm sessão revogável, auditoria e proteção contra repetição/conflito.

2FA obrigatório para administradores antes do piloto conectado ao ambiente real. Guardar códigos de recuperação de forma apropriada, com procedimento de recuperação auditado. Credenciais de desenvolvimento não podem sobreviver automaticamente à promoção para produção.

Sondas usam credenciais exclusivas com escopo reduzido, rotação e revogação. Bootstrap de uso único, TTL e aprovação. Tokens não devem aparecer em query string. Comunicações por TLS com validação habilitada; autenticação mTLS pode ser adotada por ADR, mas não é pré-condição universal deste pacote.

Registrar e bloquear tentativas de IDOR, atribuição de resultado a outra sonda, acesso a incidente fora do escopo e uso de token de device/job como usuário. Rate limits por identidade e endpoint; tratar 401/403 diferentemente de falha temporária de rede.

A revogação impede novas chamadas à central imediatamente, mas não interrompe magicamente uma sonda offline. O risco residual é limitado pela validade de configuração/credencial local e por políticas de rede; documentar esse limite e oferecer revogação local pela operação.

## 4. Política local de sonda

Arquivo/manifesto mantido por administrador da VM, protegido contra alteração pelo processo de medição. Conteúdo mínimo: revisão; redes/destinos autorizados; protocolos/portas; nomes permitidos quando necessários; redirects; referências de segredo; limites de frequência/corpo/concorrência.

A central recebe apenas revisão/hash e capacidades derivadas. Pode reduzir/suspender tarefas; não acrescentar rede privada ou segredo que o administrador local não autorizou. Aceitar nova política local exige ação explícita da operação, não download silencioso da API.

Segredos de equipamento preferencialmente permanecem no store local da sonda. A central guarda referência e metadata, não precisa receber a senha de gerenciamento para cadastrar um perfil. Se houver entrega remota de segredo, exigir criptografia, escopo e trilha específicos por ADR.

## 5. SSRF e escolha real de destino

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

## 6. Credenciais de equipamento e HTTP

Preferir interfaces autenticadas e protegidas suportadas pelo equipamento real, sempre somente leitura. Legado que exija exceção precisa de inventário, isolamento, ACL de origem e aceite de risco; não prometer compatibilidade sem teste.

Headers de autenticação HTTP são segredos; não devolvê-los no painel, auditoria, erro ou push. TLS valida hostname e cadeia. Exceção para certificado interno exige CA autorizada ou política explícita, não `skip_verify` global.

Não manter segredos em Git, compose versionado, imagens, fixtures, exemplos, capturas ou artefatos de CI. `.env.example` contém somente placeholders seguros. O banco armazena segredo cifrado quando realmente necessário e a chave fica fora dele, com backup protegido separado.

## 7. Mobile e painel

Tokens mobile em armazenamento seguro da plataforma. Logout revoga sessão e associação do token push ao usuário. Troca de usuário no mesmo aparelho não pode continuar recebendo incidentes da conta anterior.

Deep links só levam a recurso após autenticação e autorização. O push carrega referência opaca e texto mínimo; não carregar topologia sensível na tela bloqueada por padrão. Ao abrir, consultar estado atual.

Painel protege sessão, CSRF, conteúdo exibido e ações administrativas. Sanitizar comentários e campos vindos de alvos para impedir XSS. CORS restrito aos clientes necessários; não usar origem curinga junto com credenciais. Exibir erro operacional sem stacktrace nem segredo.

## 8. Integridade do pipeline

Validar relações entre identidade, assignment, geração e resultado. Rejeitar timestamps absurdos, família incoerente, path evidence faltante e payload excessivo. Conflito de idempotência gera registro separado e nunca altera a amostra original.

Separar canário de resultado real por identidade/escopo. Uma credencial do vigilante não pode afirmar saúde de clientes. Falha de replay não modifica política ou prova do caminho. Auditoria e ledger sobrevivem à reexecução do worker.

## 9. Auditoria mínima

Registrar login relevante, falha de autorização, criação/alteração de ativo/check/política, cadastro/revogação de sonda, alteração de perfil de caminho, manutenção/silêncio, reconhecimento, responsável, correção histórica, mudança de canal e exclusão/expurgo administrativo.

Evento contém autor/identidade, recurso, horário, correlation ID e alteração sanitizada. Não guardar senha antiga/nova nem tokens. Não permitir apagar auditoria pela interface comum. Expurgo por retenção é operação controlada e rastreável, não edição de evento.

## 10. Dependências, containers e execução

Executar como usuário não privilegiado; filesystem somente leitura onde possível; volumes persistentes restritos; nada de socket Docker ou `privileged: true`. ICMP recebe somente a capacidade/mecanismo demonstrado como necessário. Não usar `NET_ADMIN` para contornar falta de planejamento de rede.

Fixar dependências, verificar origem, licenças e vulnerabilidades no momento de implementar. Atualização do coordenador/executor é operação controlada; configuração remota não pode baixar plugins/binários arbitrários.

## 11. Bloqueadores de promoção

Credencial compartilhada sem escopo; segredo em Git/log/push; check acessando destino fora da allowlist; re-resolução que permita rebinding; executor público; escrita em roteador; app usando credencial de equipamento; canário falsificável por usuário comum; 2FA administrativo ausente; backup sem proteção de segredos; incidentes baseados em medição comprovadamente inválida.

As exceções devem ser específicas, temporárias, registradas e aprovadas; não usar “é MVP” para dispensar as fronteiras de segurança centrais.
