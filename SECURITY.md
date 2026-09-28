# Política de segurança

> Este arquivo existe pelo **caminho que o GitHub reconhece** — um `SECURITY.md`
> na raiz é o que liga o botão *"Report a vulnerability"*. Ele **não duplica** a
> norma; aponta para a fonte única.
>
> **A fonte é o [docs/security.md](docs/security.md), obrigatório desde o
> primeiro commit.**

## Reportando uma vulnerabilidade

Uma vulnerabilidade, um segredo exposto ou suspeita de vazamento: reporte para
**samirhv@me.com**, **imediatamente**. Por favor, não abra issue pública para um
problema de segurança.

Você recebe uma confirmação de recebimento, e uma correção estrutural vira um
ADR em [docs/decisions.md](docs/decisions.md).

Veja o [docs/security.md §11](docs/security.md#11-resposta-a-incidentes) para o
texto normativo de resposta a incidentes, e o
[§5](docs/security.md#5-segredos-e-configuração) para entender por que um
segredo exposto é **rotacionado, não revertido** — tirar a linha do HEAD deixa o
segredo no histórico e em todo clone.

## Versões suportadas

<!-- Padrão do skeleton a substituir pela janela de suporte real do projeto; a
     linha abaixo vale enquanto não houver artefato publicado. -->
O `master` atual é o estado suportado.

## Contexto

O b3monitor é um repositório **privado** da `BLUE3-ISP` hoje, e sem código.

Se um dia for aberto, este arquivo vira o primeiro ponto de contato de quem
reportar um problema de fora da casa. O checklist a rodar antes disso é o
[docs/runbook.md §6](docs/runbook.md#6-checklist-antes-de-tornar-o-repositório-público)
— e o primeiro item dele é varrer o **histórico**, não a árvore de trabalho.
