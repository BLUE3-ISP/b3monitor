# Changelog

Entradas no formato da mensagem de commit (`versão - descrição curta em
português`, veja [docs/versioning.md](docs/versioning.md)), mais novas primeiro.
**Cada cabeçalho `##` é literalmente o subject do commit** — a entrada é escrita
*antes* do commit, para que a frase que cai no `git log` seja pesada, e não
improvisada na hora do `git commit`.

Os corpos são narrativa: o que mudou, por quê e o que foi medido. Este arquivo
nunca é reescrito.

## 0.1.19 - B4: workers com lease e reconciliador, e o worker morto no meio não perde nem duplica o resultado

A ingestão (B3) deixava o item em `ingestion_work` esperando, e nada o consumia. Este é o consumidor, com o que o
2.0 pede dele: `FOR UPDATE SKIP LOCKED`, lease com expiração e um reconciliador que devolve a lease vencida.

O ponto de desenho que sustenta as duas provas pedidas está no **ADR-015**: a fila é a própria tabela de domínio, e não
a `jobs` do Laravel, porque o B2 já tinha posto `lease_owner` e `lease_expires_at` nelas e o `queue:work` não os conhece.
O `claim` é um `UPDATE … WHERE id = (SELECT … FOR UPDATE SKIP LOCKED LIMIT 1)`. O passo do worker abre uma transação,
**tranca a linha com a lease conferida**, roda o handler e conclui, tudo junto: processo morto desfaz a transação e o
efeito some com ele; worker lento que perdeu a lease é descartado **antes** do handler.

- **Três filas.** `ingest` (existe: confere que o resultado está em `results` e enfileira a reavaliação do check),
  `decide` (tabela nova `decision_work`, coalescida por índice único parcial: um pedido pendente por check e família IP)
  e `notify` (a outbox). `decide` e `notify` são do B5, e `b3mon:work` **recusa subir** nelas em vez de consumir sem fazer
  nada.
- **O item que mata o worker não volta para sempre.** Estado novo `dead` nas filas: esgotadas as tentativas, por falha ou
  por lease vencida, o item para, visível, com o motivo em `last_error`. Antes de ter isto, um item venenoso seria
  reenfileirado em laço.
- **`b3mon:partitions`**, de hora em hora: sem ele a migração criava só a janela do dia em que rodou, e a ingestão
  passaria a responder `retryable` quando o último dia acabasse. Faltava quem criasse as partições "no dia a dia" (a
  doc do B2 dizia que seria o agendador).
- **Unit `b3mon-worker@.service`** em `deploy/systemd/`, com o endurecimento da C-07 (`ProtectSystem=strict`,
  `NoNewPrivileges`, capabilities vazias, `SystemCallFilter`): `systemd-analyze verify` limpo e exposição 1.3 OK no
  `security --offline`. **Nada foi instalado**, e o layout (`b3mon`, `/srv/www/b3mon`) é o previsto em 02 §7, não medido.
  O cron do `schedule:run` está no README da pasta.

**Medido.** 215 testes, 1.368 asserções. O que prova o item: a lease vencida é retomada, e **um processo PHP de verdade é
morto com SIGKILL no meio do passo** (transação aberta, efeito gravado e não commitado, linha trancada): o efeito não
existe, o item continua do morto, o reconciliador o devolve só depois da lease, e o segundo worker o processa **uma vez**,
com o resultado intacto no ledger. A suíte nova que precisa de commit de verdade (`tests/Concorrencia`) usa
`DatabaseMigrations`.

**A suíte foi submetida a mutação, e escapou quatro vezes na primeira rodada.** Dos 26 estragos à mão, escaparam: o `hold`
que não confere o dono, o `hold` sem `FOR UPDATE`, a coalescência removida (o teste contava só os pendentes, e o passo
que falhava por violar o índice dava o mesmo número) e o worker que não reconcilia a própria fila. Cada um ganhou o teste
que o pega, e na segunda rodada os 26 foram pegos. A mutação também achou um defeito **do teste**: um teste de
concorrência que falhava antes do `rollBack` deixava a segunda conexão com a transação aberta, e o `migrate:fresh` do teste
seguinte esperava esse lock para sempre, travando a suíte por 38 minutos.

**Não está aqui:** o motor sobre o banco e o envio (B5); o expurgo de itens `done` e `dead`, que só crescem; e o alerta
de `dead` (F0-07). `docs/trabalho-assincrono.md` diz isso, com o ciclo de vida e a tabela do que acontece quando o worker
morre.

## 0.1.18 - B3: a ingestão durável, com o reenvio provado e a bomba de descompressão recusada

`POST /api/v1/probe/results` autentica a sonda por token (só o SHA-256 fica no banco), lê o corpo respeitando os limites de
transporte, valida o lote e grava cada resultado numa transação própria: o recibo no ledger, a linha em `results` e o item em
`ingestion_work` entram **juntos ou não entram**. A promessa é que `accepted` só sai **depois do commit**: se a resposta se
perde e a sonda reenvia, o reenvio é `duplicate`, sem segundo trabalho. Os outros destinos de um item são `conflict` (mesmo
`result_id` ou mesma chave natural com outro conteúdo: a observação original não é sobrescrita e a divergência é auditada),
`rejected_terminal` (mais velho que os 7 dias de aceitação) e `retryable` (falta de partição: a transação inteira volta,
**sem recibo órfão**). Um lote com resultado de outra sonda, assignment alheio ou geração que não existe é recusado com `403`
**inteiro**. As migrações ganharam uma quarta, com `probes`, `probe_credentials` e `assignments`, no mínimo que a ingestão exige.

**A validação é código próprio, e isso é uma escolha com custo.** A biblioteca de JSON Schema está travada no ADR-014, e o plano
que a fila registrou para esse caso era validar à mão. O `BatchValidator` é uma segunda implementação das regras do contrato, e
o que o impede de divergir dele em silêncio é um teste de conformidade com as 44 fixtures como oráculo, mais uma versão ponta a
ponta pelo endpoint. A decisão e como revertê-la estão na premissa 9 de `.loop/ASSUMPTIONS.md`. As mensagens de erro dizem o
caminho e a regra, **nunca o valor recebido**: uma sonda não ganha o próprio dado de volta.

**O teste do gzip achou um defeito real.** O `BodyReader` recusava um gzip **válido**, dizendo que estava truncado, o que teria
quebrado todo envio comprimido: depois que o fluxo termina dentro do laço, perguntar de novo com `ZLIB_FINISH` devolve outro
status. A correção checa o término a cada passo e passou a recusar também bytes sobrando depois do fim do fluxo. A bomba de
descompressão (17 MiB de zeros, dezenas de KiB no fio) é contada **enquanto expande**, em pedaços de 4 KiB, porque o tamanho
comprimido não previne a expansão.

**Provado, e de novo duas vezes quase não foi.** 60 testes de ingestão e 164 no total, com as 1.149 asserções, todos pelo
endpoint real e conferindo o banco depois. Estraguei o código de treze maneiras, e **quatro "escaparam" na primeira rodada, por
culpa minha**: três usavam `->whereRaw('false')->insert(...)`, e o `insert` do Laravel ignora o `where`, então não mudavam nada;
a do gzip trocava só uma das duas checagens do limite, e a outra continuava pegando. Refeitas com estragos que mudam o
comportamento, as treze foram pegas. O que **não** está aqui: o motor não é acionado (o trabalho fica `pending` até os workers
do B4), não há limite de taxa (`429`), e o cadastro de sondas é o mínimo, sem aprovação nem rotação (F0-05). O
`docs/ingestao.md` diz tudo isso.

## 0.1.17 - B2: o banco da central, que recusa por si o que não pode existir

Três migrações criam dez tabelas no PostgreSQL: `results` (particionada por dia de `started_at`, imutável),
`result_receipts` (o ledger de idempotência global), `ingestion_work`, `current_states`, `state_events`, `incidents`,
`incident_events`, `notification_outbox`, `notification_attempts` e `audit_events`. A regra que guia o desenho é que **o que
o banco consegue recusar, ele recusa**, porque uma invariante que mora só na aplicação desaparece no primeiro caminho que
esquecer dela. Entre as impostas por CHECK, gatilho ou chave única: o estado "verde" com cobertura insuficiente é
impossível (INV-11); só **um** incidente fica aberto por chave de correlação; a mesma decisão de notificar não entra duas
vezes; o canal `dev` é identificado como simulado por uma coluna **gerada**; e `results`, a auditoria, as tentativas e a
linha do tempo do incidente não aceitam UPDATE nem DELETE.

**A partição tem um custo, e ele está no desenho.** Em PostgreSQL toda chave única de uma tabela particionada inclui a
coluna de partição, e nada pode apontar para ela por FK. Por isso a idempotência global **não** mora em `results`: mora no
ledger, sem partição e com retenção maior. Sem partição para o dia de um resultado, o `INSERT` falha alto, que é o sinal
que o monitoramento de "falha de criação de partição" precisa ver. A relação das três janelas de retenção (C-02: bruto ≥
aceitação + 7 d, ledger ≥ bruto + 7 d) é **validada ao subir a aplicação**, e uma configuração incoerente derruba o boot.
Os valores de enum dos CHECK vêm de uma classe `Glossario`, e um teste os compara com o `common.schema.json`, para o banco
e o contrato não divergirem em silêncio.

**Provado, e as duas vezes que quase não foi.** 43 testes de banco e 13 de retenção e glossário, cada recusa conferida
pelo **SQLSTATE exato**; as migrações sobem, descem e sobem de novo. Estraguei o SQL de dez maneiras e cada uma derrubou um
teste, mas **duas só caíram porque a migração quebrou por SQL inválido**, o que não prova que o teste discrimina; refeitas
com um estrago válido (o gatilho de imutabilidade restrito a DELETE), foram pegas pelos testes de imutabilidade. Um defeito
real apareceu na segunda execução da suíte: o `migrate:fresh` apaga as tabelas e **não** as funções, então a segunda rodada
tropeçava nas que a primeira deixou. As funções passaram a `CREATE OR REPLACE`. Escrevi no documento "59 testes" de cabeça e
eram 43; conferi antes de commitar. Um teste que eu havia deixado marcado como pulado foi removido, porque pulado não conta
como aprovado.

Duas diferenças em relação à fila, que o 06 §2 resolve: o ledger é `result_receipts` e a auditoria é `audit_events`, e não
`ingestion_receipts` e `audit_log`. `probes`, `assignments`, `checks` e as demais entidades **não existem ainda** (B3, F0-04,
F0-05), então os recibos e `results` guardam `probe_id` e `assignment_id` sem chave estrangeira. O que o banco faz e o que
falta está em `docs/modelo-de-dados.md`.

## 0.1.16 - B1: o motor de estado existe, é uma função pura, e a suíte dele foi posta à prova por mutação

`App\State\Engine`, em `central/app/State/`, recebe a política versionada, as perspectivas previstas, as
observações e o **instante**, e devolve saúde, abrangência, cobertura, o estado de cada perspectiva e as
transições confirmadas. Não lê o relógio, não toca banco nem rede e não envia nada: a mesma entrada dá a mesma
saída, em qualquer ordem de chegada. Implementa a confirmação por perspectiva com sequência **consecutiva** (a
lacuna zera), a elegibilidade (só `pass` e `fail` medem o alvo, e relógio ruim, integridade inválida e geração antiga
não somam), o frescor, e o quórum **por domínio** da tabela 5.1: duas sondas do mesmo domínio dão um voto, e se
discordam o domínio não vota. O presente expira por tempo, e um `down` que perde toda a observação vira `unknown`,
nunca `up`. O que ele **não** faz está dito em `docs/motor-de-estado.md`: integridade persistente, anti-flap,
redundância, replay e incidentes são do F0-04 e do B5.

**Os testes, e por que eu confio neles.** 40 testes e 430 asserções, sem banco, rede nem relógio real, com os três casos
do 10 §10 verbatim em `tests/Fixtures/motor/casos.json`, oito derivados da tabela 5.1, e os T-001 a T-013, T-016, T-017,
T-024 e T-026 citados um a um. Passaram de primeira, e é exatamente por isso que não bastam: testes que eu escrevo
contra código que eu escrevo. Então estraguei o motor de onze maneiras (frescor `>` virando `>=`, a lacuna que não zera a
sequência, a geração antiga que entra na conta, a incapacidade de medir virando falha, relógio ruim e integridade
inválida passando, o conflito escolhendo a primeira amostra, o domínio discordante ainda votando, o mínimo de domínios
ignorado, a confirmação com uma falha a menos e o slot futuro participando), e **cada uma derrubou pelo menos um teste**.
Na primeira rodada o meu script de mutação dizia que todas tinham escapado, e o defeito era dele: cortava o começo do
JSON onde está o resultado. Um teste estático também lê o fonte do motor e quebra se alguém introduzir `DB::`, `now(` ou
`new DateTimeImmutable()`.

**Uma divergência do pacote, resolvida e registrada.** A C-01 do 2.0 dá a fórmula do frescor, `(failure_count + 1) ×
intervalo + tolerância + margem`, e diz que ela vale "cerca de 105 a 120 s". Com 3 falhas ela dá 132 s neste perfil, e o
"105 a 120" corresponde à fórmula **sem** o `+ 1`. Usei a fórmula, que é a parte normativa, como campo da `Policy`, com
um teste que fixa o valor e outro que fixa a invariante que a C-01 exige (o frescor é maior que o tempo para confirmar uma
queda). A escolha e como revertê-la estão em `.loop/ASSUMPTIONS.md`, junto das outras três do motor: `skipped` como
ausência, o conflito no mesmo slot sem escolher por ordem de chegada, e os motivos de inelegibilidade como internos.

## 0.1.15 - A8a: os contratos existem e foram conferidos, e a validação de schema fica à espera do dono

`contracts/` ganha seis schemas JSON Schema 2020-12 (resultado, lote, resposta por item, bundle,
heartbeat e os tipos comuns, com os enums do glossário 14 §3), o OpenAPI 3.1 e **44 fixtures de
laboratório**, 15 válidas e 29 inválidas, com um manifesto que diz, para cada inválida, a **regra que
ela viola**. As invariantes que cabem em schema moram nele: o IP efetivo tem de ser da família do
assignment (INV-03), relógio desconhecido tem offset nulo e nunca zero (INV-02), `accepted` só se
durável, replay carrega a sessão, o executor é `native` (C-05), nenhum campo extra. O OpenAPI
referencia os schemas por `$ref` externo, então existe um contrato e não dois.

**Os schemas estão certos, e isso foi provado.** Não havia validador algum instalado, então avaliei
duas candidatas em módulos descartáveis **fora do repositório**: `santhosh-tekuri/jsonschema` v6.0.3 em
Go e `opis/json-schema` 2.6.0 em PHP. As 44 fixtures batem com o manifesto nas duas, com 0
divergências; um lote de 500 resultados passa e o de 501 não; e cada inválida falha **pelo motivo
declarado**, não por acidente. Isso também achou um fato que o ADR registra: em Go a verificação de
`format` precisa ser ligada, senão o INV-03 não é verificado e um IPv6 num assignment IPv4 passa.

**A validação dentro do repositório não foi feita, e não pude fazê-la.** As duas bibliotecas são
dependência nova fora do ADR-013, e o `settings.json` põe `composer require` e `go get` em `ask`
justamente para o dono decidir. O **ADR-014** é a proposta, com licença, versão, manutenção e o
risco que pesa (a biblioteca PHP está quase um ano sem release), e o item **A8b** fica como `- 🔒`.
O que entrou no repositório é o que não depende dela: testes estruturais **nas duas linguagens**
(JSON bem formado, `$ref` que resolve, manifesto e disco em correspondência 1 para 1, toda inválida
dizendo a regra), provados pela quebra intencional do contrato: um arquivo órfão e um `$ref` para
definição inexistente derrubam o Pest e o `go test`. Pest com 8 testes e 384 asserções, e Go com os
testes de estrutura e do relógio, todos verdes.

`make verify` roda o que existe (testes, `go vet`, `composer validate`) e **termina com erro**,
dizendo que a validação de payload contra os schemas não foi feita. Um `verify` verde afirmaria uma
verificação que ninguém fez. Duas correções no caminho: o endpoint de ingestão é `POST
/probe/results`, como diz o 06 §7, e eu havia escrito `POST /results` na fila; e o `go vet` num
módulo sem pacotes quebrava o `verify`, como o `go test` já tinha quebrado o `test`, e agora esse
módulo é pulado dizendo que foi. O `docs/status/SESSOES.md` ganhou o registro do A2 ao A8a, com os
comandos e os resultados.

## 0.1.14 - A7: os módulos Go da sonda e do vigilante, e o custo da toolchain, finalmente medido

`agents/probe` e `agents/watchdog` viram módulos Go (`github.com/BLUE3-ISP/b3monitor/agents/…`) com
`go 1.26.0` e `toolchain go1.26.8`, como o ADR-013 fixou. O vigilante tem **só o `go.mod`**, como o
item pede. A sonda ganha o primeiro pacote real, `internal/relogio`: o relógio atrás de uma interface,
com a versão do sistema (sempre UTC) e uma `Falso` que o teste avança, e até recua, à mão. É uma
escolha com uso garantido: a agenda de slots absolutos e o frescor do B6 e do B1 dependem de "agora",
e código que chama `time.Now()` direto não se testa.

O ADR-013 deixava dois custos como "não medidos", e o primeiro `go vet` os mediu. A toolchain baixou
em cerca de **10 s** e ocupa **235 MB** em `~/go/pkg/mod`; o Go 1.24.4 da estação trocou sozinho, sem
`sudo`. O tamanho do download em si continua sem medida, e o ADR diz isso. Verificado: `go vet`,
`gofmt` e `go test -race` limpos (quatro testes, incluindo uso concorrente), e `CGO_ENABLED=0 go
build` passa, o que prova que o pacote já compila sem cgo; a prova para o **driver SQLite** segue
sendo do B6, quando ele entrar.

**O `make test` quebrou no meio e foi corrigido.** Um módulo com `go.mod` e nenhum pacote, que é o
vigilante agora, faz o `go test ./...` sair com erro, e o alvo falhava por isso. Em vez de esconder,
o alvo passa a pular esse módulo **dizendo que pulou** (`PULADO, NÃO APROVADO: agents/watchdog tem
go.mod e nenhum pacote`), e roda o resto. Hoje o `make test` executa o Pest (3 testes) e o `go test`
da sonda, e sai com 0. O `CLAUDE.md`, o `AGENTS.md` e o runbook perdem a frase de que os agentes
ainda não existem.

## 0.1.13 - A6: a central nasce em central/, com Pest 5 verde e a rota de saúde

`composer create-project` do Laravel 13 (13.34.0) em `central/`, sem os scripts do esqueleto, que
migrariam um SQLite que não queremos. Entram os namespaces de módulo de 02 §3 (`Identity`,
`Inventory`, `Probes`, `Ingestion`, `State`, `Incidents`, `Notifications`, `History`, `Audit`,
`Ops`), a API versionada sob `/api/v1` e `GET /api/v1/health` no módulo `Ops`, que responde 200
com o banco de pé e **503** quando ele não atende. O controlador diz no próprio texto que isso
**não é o canário** (F0-07): um 200 ali não prova que os workers andam.

**A suíte roda só no banco `b3mon_test`.** O `RefreshDatabase` recria o banco a cada teste, então a
trava ficou no `tests/TestCase.php`, no ponto em que a configuração já carregou e nenhum trait
rodou ainda. A primeira versão da trava estava no `Pest.php`, lia o ambiente cedo demais e recusava
tudo; o "erro interno do Pest" que apareceu junto era só o meu `exit` no meio da inicialização.
Corrigido e **provado nos dois sentidos**: com o banco certo, 3 testes e 15 asserções passam; com o
`phpunit.xml` apontado de propósito para o `b3mon_dev`, os três são recusados e uma tabela sentinela
gravada no dev continua lá depois. O teste de "banco fora do ar" mora em `tests/Unit`, porque o
rollback do `RefreshDatabase` falhava na conexão que ele mesmo deixa quebrada.

`make test` roda a suíte real da central. A dos agentes **é pulada em voz alta** (`PULADO, NÃO
APROVADO`) até o item A7, porque o 09 §6 diz que teste ignorado não conta como aprovado; com um
`go.mod` ela passa a rodar sozinha. O `make deploy-central` segue recusado.

Três fatos que mudaram o que estava decidido. **Pest:** o esqueleto do Laravel 13 fixa
`pest ^4.7` e `phpunit ^12.5`, e o Pest 5 exige o PHPUnit 13; a central sobe os dois juntos, e o
resultado é Pest 5.3.0 com PHPUnit 13.3.6, o que o ADR-013 agora registra. **Esqueleto:** ele
trouxe um `AGENTS.md` e um `CLAUDE.md` dentro de `central/`, com instruções de baixar o PHP por
`curl | bash` e instalar o Laravel Boost; **não segui nenhuma das duas** e removi os arquivos, já
que a norma da frota só admite os gêmeos na raiz. **`.env`:** o `settings.json` da raiz, que vale
na sessão aberta na pasta guarda-chuva, nega escrita em `.env*`. Eu já tinha apagado o
`.env.example` gerado para recriá-lo e **não consegui recriá-lo**, e não contornei a regra; ficou
como item 🔒 na fila, com o conteúdo, variável a variável, na seção 3 do `docs/runbook.md`.

O runbook ganhou as seções 1 a 3 (requisitos, da máquina limpa até rodando, configuração); a 4, de
deploy, segue a preencher porque nenhum deploy existe.

## 0.1.12 - A5: um PostgreSQL de desenvolvimento isolado, em espaço de usuário, na porta 54329

`lab/pg.sh` cria e administra um cluster PostgreSQL 17 em `lab/.pgdata`, com socket em
`lab/.pgrun`, **sem `sudo` e sem tocar o PostgreSQL do sistema**, que segue de pé na 5432.
`make pg-up`, `pg-down` e `pg-status` chamam o script, que é idempotente: na primeira vez faz
`initdb`, depois só sobe e garante os roles e bancos `b3mon_dev` e `b3mon_test` (o segundo é
onde a suíte vai rodar, para nunca limpar o primeiro). Antes de subir, o script recusa se
outro processo já segura a porta 54329, em vez de iniciar em silêncio numa porta que outra
coisa responde, e limpa as variáveis `PG*` do ambiente para nada desviar para o cluster do
sistema.

Rodado de verdade, com o comando do critério de pronto: `psql -h 127.0.0.1 -p 54329 -U
b3mon_dev -d b3mon_dev -c 'select version()'` responde PostgreSQL 17.11. Conferido também que
`pg-up` rodado duas vezes não recria nada, que `pg-down` para e `pg-status` sai com erro 3, que
o cluster volta com os dados (uma linha gravada antes da parada, lida depois) e que o
`postgresql@17-main` do sistema continua ativo. O `git status` não mostra nada de `lab/.pg*`, e
o `check-ignore` aponta as linhas do ADR-003.

**A autenticação é `trust` em loopback**, e isso é uma escolha com custo: qualquer processo local
conecta como qualquer role. É aceitável porque o cluster só guarda dados de laboratório e nunca
uma credencial de produção; o aviso está no topo do script, e a premissa em
`.loop/ASSUMPTIONS.md` diz como trocar por `scram-sha-256`. O `Makefile` perde três alvos
`não implementado`.

## 0.1.11 - A4: o layout do repositório, um Makefile que não finge e os gatilhos de versão

Nascem `central/`, `agents/probe/`, `agents/watchdog/`, `contracts/{openapi,schemas,fixtures}/`,
`deploy/systemd/`, `lab/` e `evidence/` (com `.gitkeep`, porque pasta vazia não vai para o git),
a estrutura de 02 §4 do pacote 2.0 sem o diretório mobile que a D-08 tirou.

O `Makefile` traz os alvos de 02 §10 com uma regra só: **o que ainda não tem o que fazer falha**,
e diz qual item da fila o implementa. Um `make test` verde sem teste nenhum seria o falso verde
que o 00 §3 proíbe. Rodado de verdade: `make test`, `verify` e `pg-up` saem com erro e a
mensagem certa (`não implementado: central/ no item A6; agents/ no item A7; motor no item B1`),
e `make deploy-central` é **recusado**, porque deploy exige autorização do dono e backup prévio
(11 §7). O único alvo real é `make docs-bundle`, que gera em `dist/` o consolidado na ordem de
leitura 2.0 (o 02 do 2.0 no lugar do 1.0): 2.401 linhas, 17 arquivos de origem, e `dist/` fica
fora do git pelo ADR-003.

O `CLAUDE.md` e o `AGENTS.md` deixam de ter campo a preencher: *Stack* aponta Laravel 13, Go
com a toolchain do ADR-013, PostgreSQL 17 e a ausência de Docker; *Roda localmente com* e
*Testes rodam com* dizem **que falham hoje e por quê**, em vez de prometer o que não existe;
*Nunca toque* nomeia o `.env`, a porta 5432, os documentos 1.0, as cópias do repodocs e a
produção. Os gatilhos de versão, que o skeleton deixava como exemplo, ficam definidos aqui e em
`docs/versioning.md`: Z é o caso normal; Y é componente principal novo, contrato que quebra
compatibilidade ou **fatia do backlog fechada**; fechar a F0-01 é a `0.2.0`; X é do dono.

## 0.1.10 - A3: o PRECEDENCIA.md diz o que o 2.0 sobrescreve em cada documento do 1.0

`.continue/b3monitor_2.0/PRECEDENCIA.md` é o índice que o ADR-004 pede: **nenhum documento 1.0
foi reescrito**. Ele traz a ordem de leitura 2.0, os três arquivos substituídos por inteiro
(o README-consolidado, o 02 e o 14 novo), uma seção para cada documento de 00 a 13 e uma tabela
com **uma linha por ID da revisão**: oito D, vinte e três C e oito L, mais os nove defeitos de
empacotamento (P) já resolvidos, o que o 2.0 acrescenta e as invariantes com ID.

A conferência está no próprio arquivo e foi rodada: os 39 IDs aparecem cada um uma vez na
primeira coluna, as 14 seções existem, e o `grep` por Blackbox, Redis e Docker nos documentos
1.0 cai exatamente em oito deles (00, 02, 04, 06, 07, 10, 11 e 12), todos com a passagem citada
na seção correspondente. Duas passagens que a primeira versão deixava de fora, o "HA por usar
Docker" do 11 §2 e os links do Blackbox no 12 §6, foram acrescentadas depois da conferência.

Uma coisa o índice **não** pôde fazer: as invariantes `INV-01` a `INV-12` não têm numeração em
lugar nenhum, porque o 01 do 2.0, que as numeraria, nunca foi escrito, e o 14 só cita o
INV-03. O índice as numera pela ordem do 00 §3, que bate com esse INV-03, e diz na própria
tabela que é derivação e não numeração oficial. A premissa está em `.loop/ASSUMPTIONS.md`. O
`CLAUDE.md` e o `.continue/README.md` deixam de dizer que o índice "nasce no item A3".

## 0.1.9 - A2: as decisões da revisão 2.0 viram dez ADRs, do 004 ao 013

O `docs/decisions.md` passa de três para treze ADRs. O ADR-004 registra a regra do dono, de
29/09/2026: o 2.0 complementa o 1.0 e vale no conflito, sem reescrever nenhum documento 1.0.
Do 005 ao 012, um ADR por decisão da revisão (D-01 a D-08), cada um com o contexto medido,
a consequência e o "se o dono discordar" que a revisão já trazia: Debian bare-metal com
systemd, executor Go nativo no lugar do Blackbox, PostgreSQL 17, sem Redis no caminho crítico,
módulo no app BLUE3, Blade com Livewire e CoreUI 5, Go na sonda e no vigilante, e o monorepo.

O ADR-013 fixa as versões do A1 e decide o que o A1 deixou em aberto: a **toolchain de Go**
fica por conta do próprio Go, com `toolchain go1.26.8` no `go.mod`, porque isso se reproduz
por um arquivo versionado e dispensa `sudo`. O custo está escrito no ADR: o primeiro `go test`
baixa a toolchain pela rede, e um runner sem rede precisa dela já presente. É decisão do agente
e reversível, e por isso está também em `.loop/ASSUMPTIONS.md`.

Dois acertos de fato em relação à revisão. A D-08 citava a organização `blue3-internet`, e a
real é `BLUE3-ISP`; o ADR-012 diz isso. E a numeração da fila estava certa, mas a contagem do
plano original (004 a 011) não: precedência, oito decisões e versões somam dez.

## 0.1.8 - A1: a toolchain foi inspecionada contra os mantenedores, e o Go instalado está fora de suporte

Primeiro item da fila do loop (F0-00). Nada foi instalado: o item só lê. O resultado está
em `docs/status/SESSOES.md`, o registro append-only do que o agente fez de fato, com a
tabela de versão instalada × suportada × escolhida e a URL consultada em cada linha, todas
lidas em 01/10/2026.

O achado que muda o plano é o **Go**. O 1.24.4 desta máquina está fora da política de
suporte do projeto, que hoje mantém o 1.27.1 e o 1.26.8, e os módulos que a sonda precisa
pedem `go 1.26.0` (`golang.org/x/net` v0.59.0 e `modernc.org/sqlite` v1.60.1) e `go 1.25.0`
(`miekg/dns` v1.1.73). O `GOTOOLCHAIN` está em `auto`, conferido com `go env`, então nada
quebra: o Go baixa a toolchain pedida pelo `go.mod`. Mas o primeiro `go test` passa a depender
da rede, e escolher entre isso e uma instalação em espaço de usuário é decisão do ADR-013
(item A2), não desta passada.

Do lado do PHP, o **Pest 5 exige 8.4 ou mais novo**, o que fixa o piso; a máquina tem o
8.4.26 e o Laravel 13 (13.34.0, de 29/09/2026) aceita 8.3 a 8.5. O suporte ativo do 8.4 acaba
em 31/12/2026, e a revisão para o 8.5 fica registrada. A página de release notes do Laravel
ainda descreve o 13 como "Q1 2026" apesar de a release existir, então vale a versão do
GitHub. Livewire 4.4.7, CoreUI 5.9.0, PostgreSQL 17.11 (fim em 08/11/2029) e Node 24 (em
manutenção a partir de 20/10/2026, só para os assets) estão sem conflito. Composer, git e
make constam como **não verificados**: são ferramentas do sistema, sem consulta a fonte
oficial nesta passada. Que `modernc.org/sqlite` dispensa cgo é a documentação do projeto; a
prova é do item A7.

## 0.1.7 - a fila, o escopo e as premissas do loop entram no repositório, sem armar

`.loop/QUEUE.md` guarda os vinte itens que levam o repositório até *F0-01 FECHADA*:
oito de bootstrap (F0-00) e onze mais o fechamento da fatia vertical (F0-01 do
prompt 00), cada um numa linha que se executa sozinha e termina num critério de
pronto que dá para conferir. Sete pendências que dependem do dono ficam como `- 🔒`
e fora da fila de execução: a borda de rede (Q-02), o IP de saída do escritório
(Q-03), o nome do produto, as sondas e o vigilante em provedores distintos, os
canais de alerta, deploy e outros repositórios, e o runner de CI.

O hook só entrega o primeiro `- [ ]` a cada parada, então o cabeçalho do
`QUEUE.md` carrega as seis regras de cada fechamento — a regra de leitura (o 2.0
vence o 1.0), o português, commit com Release à mão, atualização do quadro com o
`if_version`, nenhum segredo e nenhum relato no chat —, porque o agente abre o
arquivo toda vez que marca um item. O `SCOPE.md` diz o que pode entrar e o que
para e pergunta, e o `ASSUMPTIONS.md` já nasce com as três premissas desta
preparação: ADRs em `docs/decisions.md` e não em `docs/adr/`, `b3mon` como slug e a
regra do repositório como autorização de push. A numeração dos ADRs da fila foi
conferida: precedência, D-01…D-08 e versões fixadas somam dez, do ADR-004 ao
ADR-013.

**O loop não foi armado**, de propósito: armar prende a rodada ao id de uma sessão,
e a sessão certa é uma aberta dentro do repositório, onde o hook acha o `.loop/`.
Conferido com o `loop_ctl.py`: a fila lê 20 pendentes, 7 travados e o A1 como
próximo, e `porque` mostra o hook barrado só no portão `.loop/`, sem `STATE.json`,
que é o esperado.

## 0.1.6 - o CLAUDE.md passa a apontar o material do produto, a regra de leitura e o quadro

A sessão que vai rodar o loop lê o `CLAUDE.md` sozinha, e o hook só injeta em cada
parada o **primeiro** item pendente da fila. O que tem de valer em todo turno,
portanto, precisa morar aqui, e não na cabeça de quem armou. A seção *O que é este
projeto* deixa de dizer que o material "vai para a `.continue/`": ele já está lá, e
ela agora diz o que é o produto, onde ficam os dois pacotes, qual é a regra de
leitura do dono — **o 2.0 complementa o 1.0 e vale no conflito** —, qual é a
primeira entrega e onde está o quadro de acompanhamento.

O quadro é o artefato *Produção b3monitor* (privado, com o `db` do próprio
artefato, semeado com 76 documentos a partir dos documentos 09 e 13 e da REVISAO).
O texto registra a única armadilha que a ferramenta impõe: escrever num documento
que já existe exige o `if_version`, então o agente lista a coleção antes de
atualizar. O `PRECEDENCIA.md` citado ainda **não existe**; o texto diz que ele nasce
no item A3, em vez de fingir que já está lá. Os dois gêmeos foram regenerados e
conferidos com `diff`.

## 0.1.5 - o estado gerado e o estado local de execução ficam fora do git, por ADR

O `.gitignore` do skeleton diz que tudo é versionado e que a única exceção é
segredo, e que qualquer outra exceção exige um ADR. Com o build chegando, isso
deixou de bastar, e a evidência apareceu antes do código: rodar uma vez o
`loop_ctl.py` da skill vendorizada criou `__pycache__/` na árvore, e o primeiro
`composer install` traria `vendor/` com dezenas de milhares de arquivos. Nenhum
dos dois é fonte; ambos se refazem por um comando.

O [ADR-003](docs/decisions.md) lista o que sai, e só isso: `vendor/`,
`node_modules/`, `__pycache__/`, `dist/`, `agents/**/bin/`, o cluster PostgreSQL de
desenvolvimento em `lab/.pgdata/` e `lab/.pgrun/`, e o estado de **máquina** do loop
(`STATE.json`, `entries/`, `INDEX.md`, `STOP`). O que continua versionado é a
**memória** do loop — `QUEUE.md`, `SCOPE.md`, `ASSUMPTIONS.md`, `SEM-ESCOPO`,
`STATUS.md` e `loop.sh` —, porque a própria skill diz que ler o `ASSUMPTIONS.md` não é
opcional, e uma fila que um `git clean` apaga destrói o que a norma protege.

Conferido com `git check-ignore` nos dois sentidos: os onze caminhos de estado
gerado saem, e os nove de memória, mais `composer.lock` e `go.sum`, ficam. O
`fleet.sh check` do repodocs não é afetado, porque só reclama de ignorar os
arquivos do padrão, e nenhum está na tabela.

## 0.1.4 - o build passa a ter permissão: toolchain mínima e um PostgreSQL de dev isolado

A produção do b3monitor começa pela fatia F0-01, e a stack já está decidida
(D-01…D-08 do 2.0, aceitas em 29/09/2026): Laravel na central, Go na sonda,
PostgreSQL 17. Sem permissão, o primeiro `go test` do loop pararia num prompt, e um
loop de horas sem ninguém no teclado é justamente o caso em que isso não pode
acontecer. O dono concedeu **só o build**, e cada linha está no `_comment` do
`settings.json` e na tabela do `.claude/README.md`, com o motivo e o jeito de
reverter, como a norma da frota manda.

Entram no `allow` `php artisan`, `php vendor/bin/pest`, `composer
install|validate|test`, `go build|test|vet|fmt|mod tidy`, `make`, `initdb` e `pg_ctl`
do PostgreSQL 17, `psql` **só na porta 54329** e a ferramenta `ArtifactData`. O
banco de desenvolvimento roda em espaço de usuário, sem sudo, e nunca toca o
PostgreSQL do sistema, na 5432. O `make` é o mais amplo dos grupos, porque roda o
que o Makefile disser; o Makefile é do repositório e é revisado como qualquer
arquivo, e o motivo diz isso. Ficam em `ask` `composer require|update` e `go get`,
porque dependência nova é decisão de cadeia de suprimento, e entram no `deny`
`migrate:fresh`, `migrate:reset` e `db:wipe`, como na raiz do BLUE3.

A linha *Testes rodam com* do `CLAUDE.md` e do `AGENTS.md` recebeu `make test`
**antes** da permissão existir, que é a ordem que o repodocs pede. **O `Makefile` ainda
não existe**: ele nasce no item A4 da fila. Nada foi executado neste commit — é só
configuração —, e o JSON foi validado com `json.tool`.

## 0.1.3 - o material de definição do produto entra na fila, versionado

Em 28/09/2026 o Samir colocou em `.continue/` o material da plataforma de
monitoramento distribuído da Blue3: `b3monitor/`, o pacote 1.0 (documentos 00–13
e o consolidado de 161 KB, que o `README.md` da pasta repete byte a byte), e
`b3monitor_2.0/`, a revisão 2.0 (README, glossário 14, arquitetura 02 em rascunho
e a `REVISAO_1.0_PARA_2.0.md`, mais o `files.zip` original). Nada disso tinha
commit, e uma fila que não está no git é uma fila que um apagamento não devolve —
o `fleet.sh check` do repodocs acusava exatamente isso.

Os arquivos entram **sem alteração de conteúdo**: fila não se arruma, nem o
consolidado duplicado nem o zip saem. O que muda é o `.continue/README.md`, que
agora registra a regra de leitura que o dono deu em 29/09/2026 — **o 2.0
complementa o 1.0 e, no que conflitar, vale o 2.0** —, as decisões D-01…D-08
aceitas no mesmo dia e as perguntas ainda abertas (Q-02, Q-03). Uma delas o
agente assumiu e deixou marcada como reversível: os ADRs ficam em
`docs/decisions.md`, a norma da frota, e não em `docs/adr/`, como o 14 §5 do 2.0
escreve. É conflito entre o 2.0 e a norma do repositório, que é outro eixo da
regra que o dono definiu.

## 0.1.2 - a CI do repositório passa a ter endereço: o servidor cicd, não os runners do GitHub

Os dois runs do workflow de Release no primeiro push, `36465790740` e
`36465902322`, foram recusados pelo GitHub em 4–5 s, sem executar nenhum passo:
*"recent account payments have failed or your spending limit needs to be
increased"*. A cota de minutos hospedados da `BLUE3-ISP` está travada; o ONLINE
falha igual desde pelo menos 24/09/2026. O dono decidiu, no mesmo dia, que este
repositório não precisa dos runners hospedados: a casa tem o servidor `cicd`
(`100.64.100.240`, usuário `samir`), que roda jobs do GitHub Actions de
repositórios privados em runners self-hosted.

O [ADR-002](docs/decisions.md) registra a decisão. A §8 nova do
[docs/runbook.md](docs/runbook.md) diz o que é a máquina, como entrar e o que
muda para registrar o runner de um repositório de **organização** — o token vem
de `repos/BLUE3-ISP/...` ou de `orgs/BLUE3-ISP/...`, e o painel ainda não lê
execuções de organização. Também traz as três regras da máquina que valem aqui:
nunca runner em repositório público, `LANG=C.UTF-8` no `.env` e token pelo
stdin. O detalhe do servidor não foi copiado: a fonte continua sendo o
`deploy/servidor-cicd.md` do shvia-web, e a seção linka para lá. **Nenhum runner
foi registrado**, porque ainda não há código nem suíte para rodar.

Enquanto não houver runner, a Release sai à mão. O `CLAUDE.md`, o `AGENTS.md` e o
[docs/versioning.md](docs/versioning.md) dizem agora que quem bumpa o
`version.md` roda `./tools/release.sh` depois do push e confere a Release e o selo
`Latest`. O `release.yml` continua no repositório, e cada bump deixa um run
recusado no GitHub. O checklist antes de tornar o repositório público ganhou um
item: o runner sai antes de a visibilidade mudar.

## 0.1.1 - a skill loop-work entra vendorizada em .claude/skills

A skill `/loop-work` do skill-LOOP, que faz o agente trabalhar a fila por horas
sem pedir "continua", passa a viver dentro do repositório: `vendor.sh` do
skill-LOOP, versão 0.5.4 (commit `cb511e9`), copiou `skill/loop/` para
`.claude/skills/loop-work/` e os dois modelos de prompt para `.claude/prompts/`,
sem os quais o hook procura os arquivos três níveis acima e falha em silêncio.

**A cópia é versionada**, e isso diverge dos irmãos: nos repositórios da
`BLUE3-ISP` que já têm a skill, a cópia está na 0.3.4 e fora do git —
`.claude/skills/` e `.claude/prompts/` aparecem como untracked no ONLINE, por
exemplo. Aqui vale a regra da frota: tudo é versionado, e a única exceção é
segredo.

Nenhum hook foi registrado no repositório. O hook `Stop` é global, instalado pelo
`install.sh` do skill-LOOP no config dir do Claude Code, e aponta para o upstream;
ele não faz nada enquanto não houver `.loop/STATE.json`, e a `.loop/` só nasce
quando alguém arma o loop. Nenhuma permissão foi concedida para o `loop_ctl.py`.
Conferido: `loop_ctl.py --help` roda a partir da cópia, e nenhuma `.loop/` foi
criada. O `.claude/README.md` descreve as duas pastas novas.

## 0.1.0 - a estrutura de documentação do repodocs chega ao repositório, em português

O b3monitor foi criado em 28/09/2026 na `BLUE3-ISP`, privado, com um `README.md`
vazio e mais nada. Este commit traz a estrutura de documentação do padrão da
frota, [samirhvbr/repodocs](https://github.com/samirhvbr/repodocs), copiada do
`templates/skeleton/` e reescrita em português: o repositório é da `BLUE3-ISP`,
e esse conjunto é escrito em português do começo ao fim (ADR-020 do repodocs).
A norma em si continua lá e **não** é copiada aqui, para existir um lugar só
onde mudá-la; o [docs/repodocs.md](docs/repodocs.md) é o mapa do que veio, e de
como.

Ainda não há código nem stack. Tudo o que depende disso — a stack e os comandos
no `CLAUDE.md`, os critérios de bump em `docs/versioning.md`, o modelo de
ameaças em `docs/security.md`, instalação e deploy em `docs/runbook.md` — fica
marcado como _a preencher_, com um comentário dizendo que é padrão do skeleton.
O material de definição do projeto vai para a `.continue/`, e a decisão sobre o
que o b3monitor é está registrada lá como pendente.

Três coisas divergem do skeleton, e cada uma por escrito. **Não há `LICENSE`
nem `NOTICE`**: é código interno da Blue3 em repositório privado, e o MIT com
copyright pessoal daria a quem tiver uma cópia o direito de redistribuí-la;
nenhum dos outros 13 repositórios da `BLUE3-ISP` tem licença. É o
[ADR-001](docs/decisions.md), e ele é revisto antes de o repositório ser aberto.
**O `CLAUDE.md` carrega os quatro blocos de eco**, e não os três do skeleton: o
`RELEASES-RULE` entra já, porque o `fleet.sh check` do repodocs cobra os quatro.
`LANGUAGE-RULE` e `QUEUE-RULE` vêm na variante `-PT`, escolhida pelo dono no
`origin`; `COMMIT-RULE` e `RELEASES-RULE` vêm literais em inglês, porque não têm
variante `-PT` e são regenerados, nunca editados no lugar. **O `effortLevel` é
`xhigh`**, e não o `high` do skeleton: é a decisão do dono no repodocs 1.17.1,
que o skeleton ainda não acompanhou.

Os hooks de `tools/git-hooks/`, o `tools/release.sh` e o
`.github/workflows/release.yml` são cópias literais do padrão, em inglês —
os hooks só com `HOOK_ESCAPE_VAR="B3MONITOR_NO_HOOK"` —, porque o
`fleet.sh check` compara hooks e `release.sh` byte a byte com a fonte, e uma
cópia traduzida é uma cópia que deixa de ser regenerada. Os hooks foram
habilitados neste clone antes do commit, e este commit passou por eles.
