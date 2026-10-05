#!/usr/bin/env bash
# lab/demo.sh — o CENÁRIO SINTÉTICO do laboratório (B8; 02 §10): sucesso, falha → incidente, recuperação, ausência e reenvio (replay).
#
# Roda sobre o laboratório já no ar (`make lab-up`), com as DUAS sondas, a central de verdade, os workers e o alvo `lab`. Cada fase TEM
# ASSERÇÕES: o script sai com código diferente de zero se qualquer uma falhar, e termina mostrando o `notifications-dev.log`.
#
# ⚠️ É LABORATÓRIO. O alvo é de mentira, as duas sondas estão na mesma máquina (não é diversidade de rede) e o canal de notificação é o
# `dev`, simulado: o que passa aqui prova o CAMINHO (alvo → sonda → ingestão → motor → incidente → notificação), e não homologa nada real.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
LAB="$HERE/lab.sh"
PSQL="/usr/lib/postgresql/17/bin/psql -h 127.0.0.1 -p 54329 -U b3mon_lab -d b3mon_lab -qAt"
DEVLOG="$ROOT/central/storage/logs/notifications-dev.log"
FALHAS=0
INICIO=$SECONDS

sql() { $PSQL -c "$1"; }
passo() { printf '\n\033[1m== %s ==\033[0m\n' "$*"; }
ok()    { printf '  \033[32mok\033[0m   %s\n' "$*"; }
nok()   { printf '  \033[31mFALHA\033[0m %s\n' "$*"; FALHAS=$((FALHAS+1)); }
nota()  { printf '       %s\n' "$*"; }

# aguardar "descrição" SEGUNDOS expressão-bash : espera a expressão ficar verdadeira.
aguardar() {
  local d="$1" t="$2"; shift 2
  local fim=$((SECONDS + t))
  while [ "$SECONDS" -lt "$fim" ]; do
    if eval "$*" >/dev/null 2>&1; then ok "$d ($((SECONDS - (fim - t))) s)"; return 0; fi
    sleep 2
  done
  nok "$d: não aconteceu em ${t} s"
  return 1
}

saude()          { sql "select health from current_states limit 1"; }
cobertura()      { sql "select coverage from current_states limit 1"; }
abertos()        { sql "select count(*) from incidents where confirmed_end_at is null"; }
total_incid()    { sql "select count(*) from incidents"; }
fechados()       { sql "select count(*) from incidents where confirmed_end_at is not null"; }
resultados()     { sql "select count(*) from results"; }
notif()          { grep -c "\"reason\":\"$1\"" "$DEVLOG" 2>/dev/null || true; }
dead()           { sql "select (select count(*) from ingestion_work where status='dead')+(select count(*) from decision_work where status='dead')+(select count(*) from notification_outbox where status='dead')"; }

restaurar() {
  # Deixa o laboratório como estava, mesmo se o demo falhar no meio.
  bash "$LAB" mode ok >/dev/null 2>&1
  bash "$LAB" central start >/dev/null 2>&1
  bash "$LAB" probes start >/dev/null 2>&1
}
trap restaurar EXIT

passo "0. O laboratório está de pé?"
bash "$LAB" smoke || { nok "o laboratório não está saudável: rode 'make lab-up'"; exit 1; }
bash "$LAB" mode ok >/dev/null
mkdir -p "$(dirname "$DEVLOG")"; touch "$DEVLOG"
B_OPENED=$(notif opened); B_RECOV=$(notif recovered); B_INC=$(total_incid); B_LINHAS=$(wc -l <"$DEVLOG")
nota "linha de base: incidentes=$B_INC, opened=$B_OPENED, recovered=$B_RECOV, resultados=$(resultados)"

passo "1. SUCESSO: o alvo responde, as duas sondas medem, o estado é 'up' com cobertura plena"
aguardar "estado 'up'" 90 '[ "$(saude)" = up ]'
[ "$(cobertura)" = full ] && ok "cobertura plena (2 domínios válidos de 2)" || nok "cobertura é '$(cobertura)', esperava full"
[ "$(abertos)" = 0 ] && ok "nenhum incidente aberto" || nok "há incidente aberto"

passo "2. FALHA → INCIDENTE: o alvo passa a responder 503"
bash "$LAB" mode falha >/dev/null
aguardar "incidente aberto" 120 '[ "$(abertos)" = 1 ]'
aguardar "notificação 'opened' no canal dev" 60 '[ "$(notif opened)" -eq $((B_OPENED + 1)) ]'
# O incidente pode abrir como `degraded` (uma sonda confirmou antes da outra: abrangência parcial) e AGRAVAR para `down` quando a segunda confirma.
aguardar "estado 'down' (as duas sondas confirmaram a queda)" 60 '[ "$(saude)" = down ]'
INC1=$(sql "select id from incidents order by created_at desc limit 1")
nota "incidente $INC1: $(sql "select severity || ', ' || scope || ', início observado ' || observed_start_at from incidents where id = '$INC1'")"
[ "$(notif opened)" -eq $((B_OPENED + 1)) ] && ok "UMA notificação 'opened' (não uma por amostra)" || nok "opened=$(notif opened)"

passo "3. RECUPERAÇÃO: o alvo volta; o incidente FECHA e a recuperação é avisada"
bash "$LAB" mode ok >/dev/null
aguardar "incidente fechado" 120 '[ "$(abertos)" = 0 ]'
aguardar "notificação 'recovered'" 60 '[ "$(notif recovered)" -eq $((B_RECOV + 1)) ]'
[ "$(saude)" = up ] && ok "estado de volta a 'up'" || nok "estado é '$(saude)'"

passo "4. AUSÊNCIA: as duas sondas param; sem evidência recente o estado NÃO fica verde, e ninguém é avisado de uma queda inventada"
O4=$(notif opened); R4=$(notif recovered); I4=$(total_incid)
bash "$LAB" probes stop >/dev/null
nota "frescor do check: 4 × 5 s + 1 + 6 = 27 s; depois disso a evidência envelheceu e o agendador (a cada 10 s) pede a reavaliação"
aguardar "o estado deixa de ser 'up' sozinho, SEM resultado novo (expiração por tempo)" 90 '[ "$(saude)" = unknown ]'
[ "$(cobertura)" = insufficient ] && ok "cobertura 'insufficient': o estado desconhecido é desconhecido, não verde" || nok "cobertura é '$(cobertura)'"
[ "$(notif opened)" = "$O4" ] && [ "$(total_incid)" = "$I4" ] && ok "NENHUM incidente nem aviso por causa da ausência" || nok "a ausência gerou incidente ou aviso"
[ "$(sql "select count(*) from state_events where perspective_id is null and from_state = 'up' and to_state = 'unknown'")" -ge 1 ] \
  && ok "a transição up → unknown está no histórico do estado" || nok "sem a transição up → unknown no histórico"
bash "$LAB" probes start >/dev/null
aguardar "as sondas voltaram e o estado é 'up' de novo" 120 '[ "$(saude)" = up ]'
[ "$(notif opened)" = "$O4" ] && [ "$(notif recovered)" = "$R4" ] && ok "a volta da ausência também não avisa nada" || nok "a volta da ausência gerou aviso"

passo "5. REENVIO (replay): a CENTRAL cai com o alvo em falha; as sondas guardam no spool; a central volta e o incidente abre UMA vez"
O5=$(notif opened); I5=$(total_incid); N5=$(resultados)
bash "$LAB" mode falha >/dev/null
bash "$LAB" central stop >/dev/null
nota "central fora por 40 s: as sondas medem (503) e guardam no spool"
sleep 40
bash "$LAB" central start >/dev/null
aguardar "backlog entregue: resultados novos na central" 60 '[ "$(resultados)" -ge $((N5 + 10)) ]'
aguardar "incidente aberto" 120 '[ "$(abertos)" = 1 ]'
aguardar "notificação 'opened'" 60 '[ "$(notif opened)" -ge $((O5 + 1)) ]'
sleep 10
[ "$(notif opened)" -eq $((O5 + 1)) ] && ok "UMA notificação 'opened' para o backlog inteiro (sem tempestade de replay)" || nok "opened=$(notif opened), esperava $((O5 + 1))"
[ "$(total_incid)" -eq $((I5 + 1)) ] && ok "UM incidente novo" || nok "incidentes=$(total_incid), esperava $((I5 + 1))"
INC2=$(sql "select id from incidents order by created_at desc limit 1")
nota "o início observado do incidente é do PASSADO (durante a queda da central): $(sql "select observed_start_at || ' (confirmado ' || confirmed_at || ')' from incidents where id = '$INC2'")"

bash "$LAB" mode ok >/dev/null
aguardar "incidente fechado" 120 '[ "$(abertos)" = 0 ]'
aguardar "notificação 'recovered'" 60 '[ "$(notif recovered)" -eq $((B_RECOV + 2)) ]'

passo "6. O fecho"
[ "$(notif opened)" -eq $((B_OPENED + 2)) ] && ok "duas quedas, duas notificações 'opened'" || nok "opened=$(notif opened)"
[ "$(notif recovered)" -eq $((B_RECOV + 2)) ] && ok "duas recuperações, duas notificações 'recovered'" || nok "recovered=$(notif recovered)"
[ "$(fechados)" -eq $((B_INC + 2)) ] && ok "os dois incidentes estão fechados" || nok "fechados=$(fechados)"
[ "$(dead)" = 0 ] && ok "nenhum item de fila parado como dead" || nok "há item dead"
grep -q '^\[SIMULADO\] ' "$DEVLOG" && ok "todo aviso é do canal dev e se identifica como [SIMULADO]" || nok "o log não tem a marca [SIMULADO]"
[ "$(grep -vc '^\[SIMULADO\] ' "$DEVLOG")" = 0 ] && ok "nenhuma linha do log sem a marca de simulado" || nok "há linha sem a marca [SIMULADO]"

printf '\n\033[1m== notifications-dev.log (desta execução) ==\033[0m\n'
tail -n +$((B_LINHAS + 1)) "$DEVLOG" | grep '^\[SIMULADO\]' | sed -E 's/^\[SIMULADO\] //' | jq -r '"  \(.reason | (. + "          ")[:10]) v\(.decision_version) incidente \(.incident_id[0:8]) canal \(.channel)/\(.recipient) health=\(.payload.health) severity=\(.payload.severity)"' 2>/dev/null | tail -8

printf '\n'
if [ "$FALHAS" = 0 ]; then
  printf '\033[32mDEMO OK\033[0m em %d s: sucesso, falha → incidente aberto e fechado, ausência sem queda inventada, replay sem tempestade.\n' $((SECONDS - INICIO))
  exit 0
fi
printf '\033[31mDEMO FALHOU\033[0m: %d asserção(ões). Logs em lab/.run/logs.\n' "$FALHAS"
exit 1
