#!/usr/bin/env bash
# lab/acceptance.sh — `make acceptance-lab`: o ACEITE da F0-01 no laboratório (B10; 10 §1 e §6; 09 §2).
#
# Roda sobre o laboratório já no ar (`make lab-up`) e executa os casos da fatia, cada um citando as invariantes (INV, de 01 §3 / PRECEDENCIA §6),
# os requisitos (RC, de 01 §3) e o teste do catálogo (T, de 10). Três blocos, e cada caso diz QUAL bloco o prova:
#
#   AO VIVO      contra a central de verdade: o `lab/demo.sh` (sucesso, falha, recuperação, ausência, reenvio) e, aqui mesmo, a duplicata, a
#                resposta perdida depois do commit e o conflito, por POST com o token da sonda `lab-a`.
#   SUÍTE        o teste automatizado que prova o que não dá para provocar ao vivo (a reação da SONDA a uma resposta perdida). Teste que não
#                rodou (zero testes) ou que foi pulado NÃO conta como aprovado (09 §6).
#   BLOQUEADO    o que o laboratório não pode provar (IPv6, iOS, upstream real, diversidade real de rede, canal real). Fica escrito como
#                BLOQUEIO, nunca como aceite.
#
# Sai com código diferente de zero se qualquer caso AO VIVO ou de SUÍTE falhar. Escreve `evidence/f0-01/ACEITE.md`, SANITIZADO (sem home, host
# nem credencial): comando, ambiente, versão, commit e resultado de cada caso.
#
# ⚠️ É LABORATÓRIO. Aprovar aqui prova o CAMINHO e não homologa nada real: não há gate acima de `verified_lab` (09 §1).
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
LAB="$HERE/lab.sh"
PSQL="/usr/lib/postgresql/17/bin/psql -h 127.0.0.1 -p 54329 -U b3mon_lab -d b3mon_lab -qAt"
CENTRAL="http://127.0.0.1:18000"
EVID="$ROOT/evidence/f0-01"
SAIDA="$EVID/ACEITE.md"
# ACEITE_SEM_DEMO=1 pula o bloco B (os ~3 min do demo) para quem está depurando o PRÓPRIO aceite (mutação). Isso NUNCA é um aceite: a saída vai para um
# arquivo temporário, não toca em evidence/ e o código de saída é diferente de zero mesmo com tudo verde.
PARCIAL="${ACEITE_SEM_DEMO:-0}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
INICIO=$SECONDS
FALHAS=0
LINHAS=()   # linhas da tabela de resultado

sql() { $PSQL -c "$1"; }
passo() { printf '\n\033[1m== %s ==\033[0m\n' "$*"; }
ok()    { printf '  \033[32mok\033[0m   %s\n' "$*"; }
nok()   { printf '  \033[31mFALHA\033[0m %s\n' "$*"; }
# registra CASO | BLOCO | INV | RC | T | RESULTADO | DETALHE
reg() { LINHAS+=("| $1 | $2 | $3 | $4 | $5 | $6 | $7 |"); [[ "$6" == APROVADO* ]] || FALHAS=$((FALHAS+1)); }

command -v jq >/dev/null || { echo "falta o jq" >&2; exit 1; }
bash "$LAB" smoke >/dev/null 2>&1 || { echo "o laboratório não está saudável: rode 'make lab-up'" >&2; exit 1; }

# ---------------------------------------------------------------------------------------------------------------------------------------
passo "A. AO VIVO — duplicata, resposta perdida depois do commit e conflito (POST real na central, com o token da sonda lab-a)"
# Um resultado MANUAL: ele passa pelo MESMO caminho de idempotência do agendado (recibo único por result_id), mas não entra na decisão do estado
# (só `scheduled` entra), então a prova não mexe no estado nem inventa incidente.
TOKEN="$(cat "$ROOT/lab/.run/probes/lab-a/token")"
read -r PROBE ASSIGN GER < <(sql "select p.id, a.id, a.current_generation from probes p join assignments a on a.probe_id = p.id where p.name = 'lab-a' limit 1" | tr '|' ' ')
[ -n "${PROBE:-}" ] || { echo "sonda lab-a não encontrada no banco do laboratório" >&2; exit 1; }

lote() { # $1 result_id, $2 duration_ms
  local agora; agora="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  jq -n --slurpfile f "$ROOT/contracts/fixtures/result/valido-manual.json" \
    --arg rid "$1" --arg pid "$PROBE" --arg aid "$ASSIGN" --argjson g "$GER" --arg now "$agora" --argjson d "$2" \
    --arg bid "$(cat /proc/sys/kernel/random/uuid)" --arg mrid "$(cat /proc/sys/kernel/random/uuid)" '
    ($f[0] + {result_id: $rid, probe_id: $pid, assignment_id: $aid, config_generation: $g, started_at: $now, finished_at: $now,
              duration_ms: $d, manual_request_id: $mrid}) as $r
    | {batch_id: $bid, mode: "live", replay_session_id: null, results: [$r]}'
}
enviar() { # $1 arquivo do lote → imprime o JSON da resposta
  curl -sS -m 20 -X POST "$CENTRAL/api/v1/probe/results" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' --data-binary "@$1"
}
status_item() { jq -r '.items[0].status // "erro"' <<<"$1"; }
durable_item() { jq -r '.items[0].durable // "n/a"' <<<"$1"; }
nlinhas() { sql "select count(*) from result_receipts where result_id = '$1'"; }
# O que NÃO pode mudar por causa da duplicata: incidentes, avisos e a saúde atual.
fotografia() { sql "select (select count(*) from incidents) || '/' || (select count(*) from notification_outbox)"; }

# --- DUPLICATA (T-027) ---
RID1="$(cat /proc/sys/kernel/random/uuid)"
lote "$RID1" 120 >"$TMP/l1.json"
F0="$(fotografia)"
R1="$(enviar "$TMP/l1.json")"; R2="$(enviar "$TMP/l1.json")"; R3="$(enviar "$TMP/l1.json")"
D_OK=1; D_DET="1º envio: $(status_item "$R1"); reenvios: $(status_item "$R2"), $(status_item "$R3")"
[ "$(status_item "$R1")" = accepted ] || D_OK=0
[ "$(status_item "$R2")" = duplicate ] && [ "$(status_item "$R3")" = duplicate ] || D_OK=0
[ "$(durable_item "$R2")" = true ] || D_OK=0
[ "$(nlinhas "$RID1")" = 1 ] || { D_OK=0; D_DET+="; linhas no ledger: $(nlinhas "$RID1")"; }
[ "$(fotografia)" = "$F0" ] || { D_OK=0; D_DET+="; incidentes/avisos mudaram ($F0 → $(fotografia))"; }
if [ "$D_OK" = 1 ]; then ok "duplicata: $D_DET; UMA linha no ledger; incidentes e avisos intactos ($F0)"; reg "Duplicata" "AO VIVO" "INV-07" "RC-06" "T-027" "APROVADO" "mesmo resultado enviado 3 vezes: \`accepted\`, \`duplicate\` e \`duplicate\` durável; **uma** linha no ledger; incidentes e avisos inalterados"
else nok "duplicata: $D_DET"; reg "Duplicata" "AO VIVO" "INV-07" "RC-06" "T-027" "REPROVADO" "$D_DET"; fi

# --- RESPOSTA PERDIDA DEPOIS DO COMMIT (T-028) ---
# A central grava e a resposta se perde: aqui o cliente DESCARTA a primeira resposta (para a central é idêntico: ela commitou e não sabe se a
# sonda soube). A sonda reenvia o mesmo lote e tem de receber `duplicate` durável, para poder apagar do spool sem perder nem duplicar.
RID2="$(cat /proc/sys/kernel/random/uuid)"
lote "$RID2" 130 >"$TMP/l2.json"
enviar "$TMP/l2.json" >/dev/null                               # resposta descartada
APOS_COMMIT="$(nlinhas "$RID2")"
R4="$(enviar "$TMP/l2.json")"
P_OK=1; P_DET="após o 1º envio sem resposta lida, o ledger tinha $APOS_COMMIT linha(s); o reenvio devolveu \`$(status_item "$R4")\` (durável: $(durable_item "$R4"))"
[ "$APOS_COMMIT" = 1 ] && [ "$(status_item "$R4")" = duplicate ] && [ "$(durable_item "$R4")" = true ] && [ "$(nlinhas "$RID2")" = 1 ] || P_OK=0
if [ "$P_OK" = 1 ]; then ok "resposta perdida: $P_DET"; reg "Resposta perdida depois do commit" "AO VIVO" "INV-07" "RC-06" "T-028" "APROVADO" "o commit aconteceu sem a resposta ser lida; o reenvio é \`duplicate\` durável (a sonda pode apagar do spool); **uma** linha no ledger. A reação da **sonda** é provada na SUÍTE (abaixo)"
else nok "resposta perdida: $P_DET"; reg "Resposta perdida depois do commit" "AO VIVO" "INV-07" "RC-06" "T-028" "REPROVADO" "$P_DET"; fi

# --- CONFLITO: mesma chave, conteúdo diferente (T-029) ---
lote "$RID1" 999 >"$TMP/l3.json"
# `lote` gera um manual_request_id novo: o conflito tem de ser pelo result_id com OUTRO conteúdo.
AUD0="$(sql "select count(*) from audit_events where action = 'ingest.conflict'")"
R5="$(enviar "$TMP/l3.json")"
DUR="$(sql "select count(*) from results where result_id = '$RID1' and duration_ms = 120")"
C_OK=1; C_DET="reenvio com conteúdo diferente: \`$(status_item "$R5")\`; original com duration_ms=120: $DUR linha(s)"
[ "$(status_item "$R5")" = conflict ] && [ "$(durable_item "$R5")" != true ] && [ "$DUR" = 1 ] && [ "$(nlinhas "$RID1")" = 1 ] || C_OK=0
# O INCREMENTO, e não a contagem: o banco do laboratório guarda conflitos de execuções anteriores.
AUD="$(sql "select count(*) from audit_events where action = 'ingest.conflict'")"
[ "$AUD" -eq $((AUD0 + 1)) ] || { C_OK=0; C_DET+="; o conflito não gerou exatamente 1 evento de auditoria ($AUD0 → $AUD)"; }
if [ "$C_OK" = 1 ]; then ok "conflito: $C_DET; auditado (+1 evento de ingest.conflict)"; reg "Conflito (mesma chave, conteúdo diferente)" "AO VIVO" "INV-07" "RC-06" "T-029" "APROVADO" "\`conflict\` não durável; o original ficou imutável; o conflito foi **auditado**"
else nok "conflito: $C_DET"; reg "Conflito (mesma chave, conteúdo diferente)" "AO VIVO" "INV-07" "RC-06" "T-029" "REPROVADO" "$C_DET"; fi

# ---------------------------------------------------------------------------------------------------------------------------------------
passo "B. AO VIVO — o cenário (sucesso, falha, recuperação, ausência, reenvio): lab/demo.sh, ~3 min"
if [ "$PARCIAL" = 1 ]; then echo "  PULADO (ACEITE_SEM_DEMO=1): isto NÃO é um aceite"; : >"$TMP/demo.log"; DEMO_RC=0
else
  bash "$HERE/demo.sh" 2>&1 | sed -E 's/\x1b\[[0-9;]*m//g' >"$TMP/demo.log"
  DEMO_RC=${PIPESTATUS[0]}
fi
cat "$TMP/demo.log" | grep -E '^(==|  ok|  FALHA|DEMO)' | sed 's/^/  /'

# Quantas asserções falharam e passaram em cada fase (a fase é o `== N.` mais recente).
fase_res() { awk -v n="$1" '
  /^== [0-9]+\./ { cur = $2; sub(/\./, "", cur) }
  cur == n && /^  ok/ { ok++ }
  cur == n && /^  FALHA/ { f++ }
  END { printf "%d %d", ok + 0, f + 0 }' "$TMP/demo.log"; }
caso_demo() { # $1 fase, $2 nome, $3 INV, $4 RC, $5 T, $6 descrição do que se afirma
  local r ok f; r="$(fase_res "$1")"; ok="${r% *}"; f="${r#* }"
  if [ "$f" = 0 ] && [ "$ok" -gt 0 ]; then reg "$2" "AO VIVO" "$3" "$4" "$5" "APROVADO" "$6 ($ok asserções)"
  else reg "$2" "AO VIVO" "$3" "$4" "$5" "REPROVADO" "$f falha(s), $ok ok na fase $1 do demo (lab/.run/logs)"; fi
}
if [ "$PARCIAL" = 1 ]; then :
elif [ "$DEMO_RC" -ne 0 ] && ! grep -q '^== 1\.' "$TMP/demo.log"; then
  reg "Cenário (demo.sh)" "AO VIVO" "—" "—" "—" "REPROVADO" "o demo não chegou a rodar"
else
  caso_demo 1 "Sucesso" "INV-01, INV-02" "RC-01, RC-03" "T-001 (com 2 domínios, não 3)" "alvo responde 200; estado \`up\`, cobertura plena, nenhum incidente"
  caso_demo 2 "Falha → incidente" "INV-01" "RC-05" "T-003" "alvo 503: **um** incidente \`critical\` \`all_observed\` e **uma** notificação \`opened\` (não uma por amostra)"
  caso_demo 3 "Recuperação" "INV-01" "RC-05" "T-003" "alvo volta: incidente **fechado** e notificação \`recovered\`; estado de volta a \`up\`"
  caso_demo 4 "Ausência" "INV-02, INV-11" "RC-03" "T-008" "as duas sondas param: o estado deixa de ser \`up\` **sozinho**, vira \`unknown\` com cobertura insuficiente; **nenhum** incidente nem aviso"
  caso_demo 5 "Reenvio (replay) com a central fora" "INV-07, INV-08" "RC-06, RC-07" "T-030" "central cai, o spool guarda, a central volta: **um** incidente com início observado no passado e **uma** notificação"
  caso_demo 6 "Fecho do cenário" "INV-07" "RC-05, RC-07" "—" "duas quedas, duas \`opened\`, duas \`recovered\`, nenhum item \`dead\`, todo aviso \`[SIMULADO]\`"
fi

# ---------------------------------------------------------------------------------------------------------------------------------------
passo "C. SUÍTE — o que não dá para provocar ao vivo (zero testes ou teste pulado = REPROVADO)"
pest_suite() { # $1 nome, $2 arquivo, $3 INV, $4 RC, $5 T, $6 o que prova
  local out; out="$(cd "$ROOT/central" && ./vendor/bin/pest "$2" 2>&1 | tail -1)"
  local n p; n="$(jq -r '.tests // 0' <<<"$out" 2>/dev/null)"; p="$(jq -r '.passed // 0' <<<"$out" 2>/dev/null)"
  local sk; sk="$(jq -r '.skipped // 0' <<<"$out" 2>/dev/null)"
  if [ "${n:-0}" -gt 0 ] && [ "$n" = "$p" ] && [ "${sk:-0}" = 0 ]; then ok "$1: $p testes Pest"; reg "$1" "SUÍTE" "$3" "$4" "$5" "APROVADO" "$6 ($p testes Pest, nenhum pulado)"
  else nok "$1: tests=$n passed=$p skipped=$sk"; reg "$1" "SUÍTE" "$3" "$4" "$5" "REPROVADO" "tests=$n passed=$p skipped=$sk"; fi
}
go_suite() { # $1 nome, $2 pacote, $3 regexp, $4 INV, $5 RC, $6 T, $7 o que prova
  local out; out="$(cd "$ROOT/agents/probe" && go test -count=1 -v -run "$3" "$2" 2>&1)"
  local p f s; p="$(grep -c -- '^--- PASS' <<<"$out")"; f="$(grep -c -- '^--- FAIL' <<<"$out")"; s="$(grep -c -- '^--- SKIP' <<<"$out")"
  if [ "$p" -gt 0 ] && [ "$f" = 0 ] && [ "$s" = 0 ]; then ok "$1: $p teste(s) Go"; reg "$1" "SUÍTE" "$4" "$5" "$6" "APROVADO" "$7 ($p teste(s) Go)"
  else nok "$1: pass=$p fail=$f skip=$s"; reg "$1" "SUÍTE" "$4" "$5" "$6" "REPROVADO" "pass=$p fail=$f skip=$s"; fi
}
go_suite "Resposta perdida: a reação da sonda" ./internal/envio 'TestRespostaPerdidaDepoisDoCommitVirouDuplicateENaoDuplica' "INV-07" "RC-06" "T-028" "a sonda reenvia depois da resposta perdida, recebe \`duplicate\` e só então apaga do spool"
go_suite "Central fora → spool → reenvio (a sonda)" ./internal/coordenador 'TestCentralForaSpoolEReenvioSemPerdaNemDuplicata' "INV-07" "RC-06" "T-045" "10 resultados na central, nenhum no spool, nenhum duplicado"
go_suite "Reinício no meio do teste (a sonda)" ./internal/coordenador 'TestCrashEReinicioMantemAAgendaSemDuplicarNemInventarSucesso' "INV-07" "RC-06" "T-047" "processo morto com SIGKILL: nada se perde, nada duplica, nenhum sucesso fabricado"
pest_suite "Ingestão idempotente (a central)" tests/Feature/Ingestao/ProbeResultsTest.php "INV-07" "RC-06" "T-027, T-028, T-029" "aceito, duplicado, conflito e resposta perdida depois do commit, com o recibo e o trabalho na mesma transação"
pest_suite "Expiração por tempo (a central)" tests/Feature/Decisao/ExpiracaoTest.php "INV-02, INV-11" "RC-03" "T-008" "sem resultado novo, o estado vira \`unknown\` pelo relógio do motor; não fecha incidente aberto nem avisa"
pest_suite "Replay sem tempestade (a central)" tests/Feature/Decisao/ReplayTest.php "INV-08" "RC-07" "T-030, T-032, T-036" "incidente que abre e fecha inteiro no backlog, e histórico antigo que não sobrescreve o presente"
pest_suite "Painel: nunca verde antigo" tests/Feature/Painel/PainelTest.php "INV-11" "RC-03" "—" "validade vencida aparece como desconhecida, mesmo com o banco dizendo \`up\`; saúde, cobertura e integridade separadas"

# ---------------------------------------------------------------------------------------------------------------------------------------
passo "D. BLOQUEADO — o que o laboratório NÃO prova (não é aceite)"
BLOQ=(
  "IPv6|INV-03|RC-04|T-016, T-017|O laboratório só tem IPv4 em loopback. O executor recusa fallback entre famílias (testado em Go), mas não há alvo IPv6 real nem sonda com capacidade IPv6 medida (F0-06)."
  "Upstream real e prova de caminho|INV-05|RC-08, RC-09, RC-18|UP-01 a 09|Sem upstream, sem borda e sem vínculo de saída reais: G-UP segue \`blocked\` e depende de Q-02 (fabricante e modelo da borda), que é do dono."
  "Diversidade real de rede|INV-04|RC-02, RC-10|T-021, T-022|As duas sondas do laboratório estão na mesma máquina e na mesma rede: dois domínios de observação **declarados**, não independentes."
  "Relógio medido (NTP/PTP)|INV-02|RC-02|T-026|O relógio das sondas é **declarado** pelo operador (\`lab_declared\`); a sonda ainda não mede referência externa."
  "Canal de notificação real (Telegram, push)|INV-10|RC-13|T-040, T-058 a 061|Só o canal \`dev\`, SIMULADO. Telegram, FCM e APNs dependem de credenciais do dono; o vigilante independente é do F0-07."
  "iOS e mobile|INV-12|RC-16|T-067 a 075|O módulo mobile vive em outro repositório e não faz parte desta fatia (D-05, D-08)."
  "Segurança de produção (autenticação, 2FA)|INV-09|RC-14|T-050 a 057|O painel não tem login (F0-05) e é fechado por padrão, só loopback. A política local do laboratório é de laboratório."
  "Carga, disco real enchendo e fuso de produção|INV-07|RC-06, RC-15|T-049, T-063, T-064|Nenhum teste rodou com carga real nem com disco de verdade enchendo."
)
for b in "${BLOQ[@]}"; do IFS='|' read -r nome inv rc t porque <<<"$b"; printf '  \033[33mBLOQUEADO\033[0m %s\n' "$nome"; done

# ---------------------------------------------------------------------------------------------------------------------------------------
passo "E. Evidência sanitizada: evidence/f0-01/ACEITE.md"
mkdir -p "$EVID"
[ "$PARCIAL" = 1 ] && SAIDA="$TMP/ACEITE-PARCIAL.md"
COMMIT="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo desconhecido)"
git -C "$ROOT" diff --quiet HEAD -- 2>/dev/null || COMMIT="$COMMIT (árvore com alterações não commitadas)"
VERSAO="$(head -1 "$ROOT/version.md" | tr -d '[:space:]')"
{
  echo "# Aceite da F0-01 no laboratório"
  echo
  echo "> **É laboratório.** Aprovar aqui prova o **caminho** (alvo → sonda → ingestão → motor → incidente → notificação simulada → painel). Não homologa upstream,"
  echo "> IPv6, mobile, diversidade real de rede nem canal real, e nada passa de \`verified_lab\` (09 §1). Os bloqueios estão no fim, **escritos como bloqueio**."
  echo
  echo "| | |"
  echo "|---|---|"
  echo "| Comando | \`make lab-up && make acceptance-lab\` (\`lab/acceptance.sh\`) |"
  echo "| Versão | $VERSAO |"
  echo "| Commit | $COMMIT |"
  echo "| Data (UTC) | $(date -u +%Y-%m-%dT%H:%M:%SZ) |"
  echo "| Duração | $((SECONDS - INICIO)) s |"
  echo "| Sistema | $(uname -sr) |"
  echo "| PHP | $(php -r 'echo PHP_VERSION;') |"
  echo "| Go | $(cd "$ROOT/agents/probe" && go env GOVERSION) (toolchain do módulo agents/probe) |"
  echo "| PostgreSQL | $(sql 'show server_version' | cut -d' ' -f1) |"
  echo "| Ambiente | \`lab\`: central em \`php -S\`, três workers, agendador, alvo \`lab\` e duas sondas simuladas na mesma máquina; canal \`dev\` SIMULADO |"
  echo
  echo "## Casos"
  echo
  echo "| Caso | Prova | INV | RC | T | Resultado | O que se afirma |"
  echo "|---|---|---|---|---|---|---|"
  printf '%s\n' "${LINHAS[@]}"
  echo
  echo "**AO VIVO** = contra a central de verdade, rodando agora. **SUÍTE** = teste automatizado do repositório, rodado agora; teste pulado ou ausente reprova."
  echo
  echo "## Bloqueado (o que não rodou e **não é aceite**)"
  echo
  echo "| O que | INV | RC | T | Por quê |"
  echo "|---|---|---|---|---|"
  for b in "${BLOQ[@]}"; do IFS='|' read -r nome inv rc t porque <<<"$b"; echo "| $nome | $inv | $rc | $t | $porque |"; done
} | sed "s#$HOME#~#g" >"$SAIDA"

printf '  escrito: %s\n' "${SAIDA#$ROOT/}"
printf '\n'
if [ "$PARCIAL" = 1 ]; then
  printf '\033[33mPARCIAL\033[0m (sem o demo): %d caso(s) com falha. Isto NÃO é um aceite.\n' "$FALHAS"
  exit 1
fi
if [ "$FALHAS" = 0 ]; then
  printf '\033[32mACEITE DO LABORATÓRIO OK\033[0m em %d s: %d casos aprovados, %d bloqueios registrados (não são aceite).\n' $((SECONDS - INICIO)) "${#LINHAS[@]}" "${#BLOQ[@]}"
  exit 0
fi
printf '\033[31mACEITE REPROVADO\033[0m: %d caso(s). Veja %s.\n' "$FALHAS" "${SAIDA#$ROOT/}"
exit 1
