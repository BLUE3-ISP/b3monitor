# Evidências da F0-01

Capturas **sintéticas** do laboratório (`make lab-up`), sem dado real: o alvo é o `lab` em `127.0.0.1`, as sondas são duas simulações na mesma máquina
(`lab-dom-a` e `lab-dom-b`) e o canal de aviso é o `dev`, marcado SIMULADO. A faixa roxa em cada tela diz o ambiente. Não há IP, nome de host nem credencial.

| Arquivo | O que mostra | Como foi gerado |
|---|---|---|
| `painel-saudavel.png` | Estado `em pé`, cobertura plena (2/2 domínios), integridade válida, as duas perspectivas em pé, as duas sondas com heartbeat de 0 s e a revisão do bundle em dia | `make lab-up`, esperar o estado `up` |
| `painel-incidente-aberto.png` | Estado `fora` (todas as observadas), incidente **aberto** (crítico) acima dos fechados, **uma** notificação `opened` SIMULADA | `lab/lab.sh mode falha`, esperar o incidente abrir |

Ambas em 1366×768, sem rolagem horizontal:

```
google-chrome --headless=new --no-sandbox --disable-gpu --hide-scrollbars --window-size=1366,768 \
  --screenshot=evidence/f0-01/<arquivo>.png http://127.0.0.1:18000/painel
```

O histórico de incidentes fechados que aparece nas telas vem de execuções anteriores do `make demo` no mesmo banco do laboratório. O que a captura **não** prova está em
[docs/painel.md](../../docs/painel.md) e em [docs/lab.md](../../docs/lab.md).
