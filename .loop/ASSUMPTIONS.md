# Premissas assumidas sem o dono

Cada entrada: pergunta · decisão · alternativa descartada · como reverter. **Revisar este
arquivo não é opcional:** é o preço de o loop não ter interrompido ninguém. As três
primeiras nasceram na preparação, em 29/09/2026, antes de o loop ser armado.

## 1. Onde ficam os ADRs

- **Pergunta:** o 14 §5 do pacote 2.0 põe os ADRs em `docs/adr/ADR-NNN.md`; a norma da
  frota (repodocs) manda um único registro em `docs/decisions.md`. Qual vale?
- **Decisão:** `docs/decisions.md`, numeração sequencial (o ADR-003 já existe). É conflito
  entre o 2.0 e a norma do repositório, que é outro eixo da regra que o dono definiu
  (2.0 vence o 1.0).
- **Descartada:** `docs/adr/` com um arquivo por decisão.
- **Reverter:** mover cada ADR para `docs/adr/` e trocar a linha do `CLAUDE.md`; o
  `fleet.sh check` do repodocs não cobra o formato.

## 2. Slug de trabalho

- **Pergunta:** o Q-04 do 2.0 pede confirmação de `b3mon` como slug de units, diretórios,
  tabelas e prefixos.
- **Decisão:** `b3mon` segue como slug até haver nome final do produto (também 🔒). O
  repositório continua `b3monitor`.
- **Descartada:** esperar a resposta antes de criar `central/`, `agents/` e as units.
- **Reverter:** trocar o prefixo com uma varredura única; por isso ele não é copiado para
  contratos públicos.

## 3. Push e Release

- **Pergunta:** o prompt 00 §4 proíbe push "sem autorização compatível com as regras do
  repositório". As regras do repositório (COMMIT-RULE) mandam commitar **e** dar push a
  cada entrega.
- **Decisão:** a regra do repositório é a autorização: commit e push por entrega em
  `master`, sem `--force`, e `./tools/release.sh` depois de todo bump (o Actions da
  organização está travado; ADR-002). Deploy, publicação em loja e qualquer ambiente real
  continuam exigindo autorização específica.
- **Descartada:** acumular commits locais até o fim da rodada.
- **Reverter:** dizer ao agente para parar de dar push; nada na fila depende dele.

## 4. Toolchain de Go: automática pelo `go.mod`, não instalação à parte (01/10/2026, item A2)

- **Pergunta:** o Go instalado (1.24.4) está fora de suporte, e os módulos da sonda pedem
  `go 1.26.0`. Instalar o Go 1.26 em espaço de usuário ou deixar a toolchain automática do Go
  baixá-lo?
- **Decisão:** toolchain automática, com `toolchain go1.26.8` no `go.mod` (ADR-013). Reproduz-se
  por um arquivo versionado, sem `sudo`.
- **Descartada:** instalar o Go 1.26 em `~/sdk` ou em `/usr/local`, que deixa a versão fora do
  repositório. Também o Go 1.27.1, mais novo mas só da série mais recente.
- **Reverter:** trocar a linha `toolchain` do `go.mod` e, se for o caso, instalar o Go à parte.
  Custo conhecido: o primeiro `go test` baixa a toolchain pela rede.

## 5. Numeração das invariantes INV-01 a INV-12 (01/10/2026, item A3)

- **Pergunta:** o 2.0 numera as invariantes em 01 §3, mas esse arquivo nunca foi escrito, e o 14 só
  cita o INV-03. Qual numeração usar?
- **Decisão:** a ordem do 00 §3 (a única repetição autorizada das invariantes no 1.0), que bate com
  o INV-03 do 14, mais o INV-12 novo (D-05, T-075). Está em `PRECEDENCIA.md` §5, marcada como
  derivação do índice e não como numeração oficial.
- **Descartada:** esperar o 01 do 2.0, que bloquearia todo teste que cita INV.
- **Reverter:** se o 01 do 2.0 vier a ser escrito, ele manda; renumerar `PRECEDENCIA.md` §5 e os
  testes que citam INV.

## 6. PostgreSQL de dev com autenticação `trust` em loopback (01/10/2026, item A5)

- **Pergunta:** o cluster de desenvolvimento em espaço de usuário (porta 54329) usa senha ou `trust`?
- **Decisão:** `trust` em 127.0.0.1 e no socket Unix de `lab/.pgrun` (modo 0700). Qualquer processo local
  conecta, o que é aceitável porque o cluster só guarda dados de laboratório identificados como tais e
  nunca uma credencial ou dado de produção. O aviso está no cabeçalho de `lab/pg.sh`.
- **Descartada:** `scram-sha-256` com senha de dev. Exigiria `PGPASSWORD` ou um `.pgpass`, e a regra do
  `settings.json` só libera `psql -h 127.0.0.1 -p 54329`, sem variável de ambiente à frente.
- **Reverter:** trocar `trust` por `scram-sha-256` em `pg_hba.conf` (e `--auth-*` no `initdb`), criar as
  senhas e passá-las ao Laravel pelo `.env`. O resto do script não muda.

## 7. Frescor do motor: a fórmula da C-01, e não o número do exemplo (05/10/2026, item B1)

- **Pergunta:** a C-01 do 2.0 dá a fórmula do frescor, `(failure_count + 1) × interval + tolerância + margem`, e diz
  que ela vale "cerca de 105 a 120 s" para 30 s. Com 3 falhas, a fórmula dá 132 s neste perfil (4 × 30 + 6 + 6), e os
  dois não batem: o "105 a 120" é `failure_count × interval + tolerância + margem`.
- **Decisão:** a fórmula, que é a parte normativa, como campo da `Policy` (`freshnessS()`), com margem de transporte de
  6 s (o 2.0 manda ter a margem e não diz quanto). Um teste fixa o valor, e outro a invariante que a C-01 exige: o
  frescor é maior que o tempo para confirmar uma queda.
- **Descartada:** usar o número do exemplo (105 a 120 s), que daria um presente mais curto e mais conservador.
- **Reverter:** trocar o `+ 1` em `Policy::freshnessS()` e ajustar o teste. A diferença é de um intervalo (30 s) a mais
  de tolerância antes de uma perspectiva sem amostra nova virar `unknown`.

## 8. Outras escolhas do motor sem decisão do dono (05/10/2026, item B1)

- **`skipped` é ausência** e não incapacidade de medir: não força `unknown`, só o frescor decide.
- **Conflito no mesmo slot** (conteúdos diferentes): nenhuma das duas vale, para o resultado não depender da ordem de
  chegada. **Descartada:** manter a primeira recebida. **Reverter:** exige um critério de desempate que o 03 não dá.
- **Os motivos de inelegibilidade** (`stale`, `clock_untrusted`, …) são internos ao motor e ainda não fazem parte de
  nenhum contrato. Ao serem persistidos com a observação, precisam de ADR.

## 9. A ingestão valida o lote com código próprio, guardado pelas fixtures do contrato (05/10/2026, item B3)

- **Pergunta:** o B3 precisa validar o lote contra o schema, e as bibliotecas de JSON Schema estão `- 🔒` (ADR-014,
  dependência nova). Esperar o dono ou seguir?
- **Decisão:** seguir com um validador próprio, `App\Ingestion\BatchValidator`, **guardado pelas fixtures do contrato**:
  um teste de conformidade passa cada `valido-*` de `contracts/fixtures/{result,batch}` e exige que cada `invalido-*` seja
  recusado, com o manifesto como oráculo. É o plano que a própria fila registrou para o caso de o dono não aprovar o
  ADR-014. Se um schema mudar e o validador não, o teste quebra.
- **Descartada:** parar o B3 até o dono decidir, que bloquearia a F0-01 inteira; e validar sem as fixtures, que deixaria
  o código divergir do contrato em silêncio.
- **Reverter:** quando o dono aprovar as bibliotecas, trocar o corpo de `BatchValidator` pela biblioteca (`opis/json-schema`
  com os schemas de `contracts/`). As fixtures e o teste de conformidade ficam: é por elas que se confere a troca.
- **Custo conhecido:** o validador próprio é uma segunda implementação das regras do contrato, e as regras que cruzam
  campos (família IP × IP efetivo, relógio desconhecido × offset, agendado × manual) foram escritas à mão.

## 10. Escolhas da decisão e da notificação sem decisão do dono (05/10/2026, item B5)

- **Pergunta:** o 03 deixa em aberto o que uma decisão faz em casos que o B5 tem de resolver para existir. Esperar o dono
  ou seguir?
- **Decisão:** seguir, com as escolhas abaixo, todas reversíveis por código e registradas em `docs/incidentes-e-notificacao.md`.
  1. **Gravidade:** `down` é `critical` e `degraded` é `warning`. O 03 não mapeia.
  2. **Quem avisa:** abertura, **agravamento** (`warning` → `critical`) e recuperação **só se a abertura foi avisada**. Alívio
     (`critical` → `warning`) atualiza a linha do tempo e fica quieto.
  3. **Presente `unknown` não fecha nem repete:** com incidente aberto e a evidência sumida, nada muda.
  4. **A decisão olha só o presente.** Incidente que começou e terminou inteiramente no passado fica só em `state_events`, sem
     linha em `incidents` (03 §9.3 pede a recomposição; fica para o F0-04).
  5. **Avaliação mais velha que a projeção atual é descartada**, para o estado nunca regredir.
  6. **Destinatários:** só `dev`/`lab`. Os canais reais dependem de credenciais do dono (`- 🔒`).
  7. **Perfil de política fixo** (`critical-v1`) e janela de histórico de 1 hora.
- **Descartada:** abrir incidente histórico para toda queda recomposta pelo replay (viraria aviso de coisa que já acabou, o
  oposto do que o item pede); e avisar a perda de cobertura já no B5 (exige decidir a classe `monitoring_coverage`, F0-04).
- **Reverter:** cada escolha mora numa função pequena de `IncidentManager`/`Decider`, com teste que a trava.
