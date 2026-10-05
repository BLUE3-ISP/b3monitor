# O executor HTTP nativo

> **Status:** `ACTIVE` · O que `agents/probe/internal/executor` faz **hoje** (item B7 da fila). Especificação:
> [04 §4](../.continue/b3monitor/04_SONDAS_E_EXECUCAO.md) e [07](../.continue/b3monitor/07_SEGURANCA.md) com as
> sobreposições D-02, C-05 e C-06 do 2.0. O coordenador que o chama está em [sonda.md](sonda.md).

## A decisão de desenho

Não há Blackbox Exporter (D-02): a medição é `net/http` do Go, com a conexão controlada daqui. E a defesa de SSRF **não é uma lista
de casos ruins**: é uma propriedade estrutural.

> **O IP que foi validado é o IP em que se conecta.**

A resolução de nomes acontece **dentro do `DialContext`**, uma vez por conexão, e o que foi resolvido ali é validado e discado ali.
Não existe um segundo `lookup` entre a validação e a conexão, então o **DNS rebinding (T-052)** não tem onde acontecer: um nome que
muda de resposta na segunda consulta nunca é consultado uma segunda vez.

## A ordem de decisão

Para cada conexão (a inicial e a de **cada redirecionamento**, que é uma conexão nova pelo mesmo caminho):

1. **Host e porta** estão na política local? Senão, `policy_denied`, antes de qualquer DNS.
2. Resolve o nome **uma vez**; **filtra pela família do assignment** (IPv4 só `A`, IPv6 só `AAAA`). Sem endereço da família:
   `family_unavailable`, **sem fallback** para a outra (INV-03).
3. **Todos** os endereços da família têm de passar: no **piso proibido** (nunca é alvo, mesmo que a política o cubra por engano) e
   nos **CIDRs da política**. Um IP bom e um proibido na mesma resposta (o round-robin do atacante) negam a resposta inteira.
4. Só então conecta, em `tcp4` ou `tcp6` (nunca `tcp`), no endereço **fixado**, com o `Dialer.Control` conferindo o endereço do
   socket com a mesma regra (segunda barreira).

Qualquer "não" é `policy_denied`, **sem conexão**, com `actual_destination` nulo.

### O piso proibido

Não especificado (`0.0.0.0`, `::`), a faixa `0.0.0.0/8`, multicast, broadcast e **link-local** (inclui `169.254.169.254`, o
metadado de nuvem). Valem **mesmo que a política os cubra**. Loopback e redes privadas **não** estão no piso, de propósito: o
laboratório e alguns alvos reais os usam, e quem os permite é a política, com CIDR explícito. E a política **recusa CIDR largo
demais** (`0.0.0.0/0`, `10.0.0.0/7`, `::/0`): o piso é `/8` em IPv4 e `/16` em IPv6, para a lista de permitidos nunca virar "tudo".

### O que mais

- **Proxy nulo.** `HTTP_PROXY` do ambiente não desvia a conexão (o desvio contornaria o dial validado). Conexão **não é reaproveitada**
  entre medições (`DisableKeepAlives`).
- **Redirecionamento:** `follow_redirects: false` faz o 3xx ser a **resposta** (o status entra na asserção); `true` segue até 3 saltos,
  cada um validado, e **não rebaixa de https para http**.
- **TLS:** o SNI e a verificação do certificado usam o **hostname**, mesmo conectando no IP fixado. O Go deriva o `ServerName` de cada
  host (e por isso não se fixa um só: um redirecionamento para outro host quebraria). Certificado inválido e handshake que falha são
  razões distintas.
- **Corpo:** só o status importa. Lê até 64 KiB e descarta; um corpo infinito não prende a medição. O corpo **não** vai ao resultado.
- **Sem porta de escuta (02 §6).** O executor fala com o coordenador por **socket Unix de modo 0600** (`Ouvir`, `Servir`, `Cliente`),
  nunca TCP nem UDP; só substitui um arquivo antigo se for um **socket** (nunca apaga um arquivo comum). Se o socket não responde, o
  resultado é `executor_unavailable`: a sonda não confunde "o executor caiu" com "o alvo caiu".

## Como cada falha vira resultado

| Situação | `outcome` | `reason_code` | `actual_destination` |
|---|---|---|---|
| Política nega (host, porta, IP, redirecionamento) | `policy_denied` | `policy_denied` | nulo |
| Nome sem endereço da família | `fail` | `family_unavailable` | nulo |
| Nome não resolve | `fail` | `dns_servfail` | nulo |
| Conexão recusada | `fail` | `connection_refused` | nulo |
| Tempo esgotado | `fail` | `target_timeout` | o conectado, se houve |
| Certificado inválido ou de outro nome | `fail` | `tls_certificate_invalid` | o conectado |
| Handshake falhou (inclusive HTTP puro numa porta https) | `fail` | `tls_handshake_failed` | o conectado |
| Status fora da lista | `fail` | `unexpected_status` | o conectado |
| Status na lista | `pass` | `http_assertions_passed` | o conectado |

O `actual_destination` é o **IP e a porta da conexão**, nunca "o primeiro resultado de DNS por aproximação" (04 §4).

## Como se prova

`go test` do pacote (todos contra servidores locais, sem rede externa). Os casos **adversariais** do item, cada um recusado **e
conferido por contagem de requisições recebidas pelo alvo proibido**: o **rebinding** (1ª consulta `127.0.0.1`, 2ª `127.0.0.2`: o
serviço interno recebe **zero** requisições, e o nome é consultado **uma** vez); a 1ª resposta proibida; a resposta **mista**
(20 repetições); 17 IPs proibidos resolvidos por um nome permitido (RFC 1918, CGNAT, loopback, link-local e o metadado de nuvem,
`0.0.0.0/8`, multicast, broadcast, IPv4 mapeado em IPv6); 13 **formas estranhas de endereçar o mesmo host** (decimal `2130706433`,
hex, octal, `127.1`, `[::1]`, mapeado, `localhost`, credencial na URL `alvo.example@127.0.0.1`, sufixo e prefixo parecidos, porta
fora da lista); redirect para destino proibido, para outra porta, em laço e rebaixando de https; e o IPv4 sem fallback para IPv6
(e o inverso). O caso legítimo passa, com IPv4 e com IPv6, com nome e com IP, em HTTP e em HTTPS com SNI conferido.

**A suíte foi submetida a mutação:** 34 estragos que de fato mudam o comportamento (dial sem política de host, sem filtro de família,
validar só o primeiro IP, **resolver de novo para conectar** (o rebinding de verdade), rede `tcp` em vez de `tcp4`, proxy do
ambiente, redirect sem limite ou rebaixando, piso sem cada uma das categorias, IP fora dos CIDRs que passa, cada classificação
de erro trocada, corpo lido sem limite, destino com o IP errado, socket sem `0600`, política que aceita CIDR que é tudo…).
**Cinco escaparam na primeira rodada** e viraram teste (e a segunda barreira, `Dialer.Control`, que **nenhum** teste de ponta a ponta exercia, porque a primeira decide antes dela, foi extraída e é testada diretamente): a rede do IPv6 (não havia teste de IPv6 de verdade), o proxy (o Go **não usa
proxy para loopback** e **guarda a configuração do ambiente na primeira leitura**, então o teste de comportamento não enxergava nada:
agora há um teste estrutural e um com nome não-loopback), o piso de multicast, broadcast e não especificado (a política os negava
antes de o piso agir: agora há um teste com a política **cobrindo** cada faixa) e o corpo sem limite (200 MiB em loopback lê rápido
demais: agora o corpo é infinito). **Um segue vivo e é equivalente:** a conferência de host e porta dentro do `CheckRedirect`, porque
o `DialContext` repete a mesma conferência na conexão do redirecionamento; fica como defesa em profundidade (recusa antes do DNS).

## O que não está aqui

- **Um executor por namespace de rede** (`b3mon-executor@<ns>`, `NetworkNamespacePath`): o transporte por socket já existe, mas o
  processo, a unit e o isolamento são do F0-06 (caminhos e IPv6).
- **TCP, ICMP, DNS e TLS como espécies próprias** (F0-03).
- **A política de destinos vinda de um arquivo real de produção.** `politica/lab.json` é de laboratório (loopback e `203.0.113.0/24`);
  uma política de produção é decisão do dono, e a validação do CIDR já não aceita uma que seja "tudo".
