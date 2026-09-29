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
