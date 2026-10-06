#!/usr/bin/env bash
# lab/lab.sh — o LABORATÓRIO do b3monitor numa máquina só (B8; 02 §6; L-02).
#
#   lab/lab.sh up       sobe tudo: PG, central, workers, agendador, alvo e DUAS sondas
#   lab/lab.sh down     para tudo (as sondas com SIGTERM, que as faz fechar o spool); o PG também
#   lab/lab.sh status   o que está de pé
#   lab/lab.sh smoke    verificação não destrutiva
#   lab/lab.sh mode ok|falha|lento    alterna o modo do alvo
#
# TUDO aqui é laboratório e se identifica como tal: o alvo responde `X-B3mon-Lab: lab`, o banco é `b3mon_lab`, as sondas são `lab-a` e
# `lab-b` em domínios `lab-dom-a` e `lab-dom-b` (UMA SIMULAÇÃO: duas sondas na mesma máquina não são diversidade de rede), e o canal de
# notificação é o `dev`, que só escreve num arquivo. Nada passa por aqui para um sistema real.
#
# Sem Docker (ADR-005). A central NÃO usa o `.env`: a configuração do laboratório é exportada daqui, para o laboratório nunca depender
# de (nem tocar em) um `.env` de verdade. O estado fica em lab/.run (ADR-003, fora do git).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
RUN="$HERE/.run"
PIDS="$RUN/pids"
LOGS="$RUN/logs"
BIN="$RUN/bin"
PROBES="$RUN/probes"

CENTRAL_PORT=18000
TARGET_PORT=18080
CONTROL_PORT=18081
CENTRAL="http://127.0.0.1:$CENTRAL_PORT"
PSQL=/usr/lib/postgresql/17/bin/psql
DEVLOG="$ROOT/central/storage/logs/notifications-dev.log"

die() { printf 'lab: %s\n' "$*" >&2; exit 1; }
say() { printf 'lab: %s\n' "$*"; }

sql() { "$PSQL" -h 127.0.0.1 -p 54329 -U b3mon_lab -d b3mon_lab -v ON_ERROR_STOP=1 -qAt "$@"; }

# ---- a configuração da central, EXPORTADA (nunca o .env) ----
central_env() {
  mkdir -p "$RUN"
  if [ ! -s "$RUN/app_key" ]; then
    printf 'base64:%s\n' "$(head -c 32 /dev/urandom | base64)" >"$RUN/app_key"
    chmod 600 "$RUN/app_key"
  fi
  export APP_ENV=local APP_DEBUG=false APP_URL="$CENTRAL" APP_KEY="$(cat "$RUN/app_key")"
  export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54329 DB_DATABASE=b3mon_lab DB_USERNAME=b3mon_lab DB_PASSWORD=
  export CACHE_STORE=file SESSION_DRIVER=array QUEUE_CONNECTION=sync LOG_CHANNEL=single BROADCAST_CONNECTION=null
  export B3MON_LAB=1 B3MON_WORK_IDLE_S=1 B3MON_WORK_RECONCILE_EVERY_S=5 PHP_CLI_SERVER_WORKERS=4
  # O painel é FECHADO por padrão (sem login até o F0-05; só loopback). O laboratório o liga e se identifica numa faixa.
  export B3MON_PANEL_ENABLED=1 B3MON_ENVIRONMENT_LABEL='LABORATÓRIO — dados sintéticos'
}

artisan() { (cd "$ROOT/central" && php artisan "$@"); }

# ---- processos ----
alive() { local p="$PIDS/$1.pid"; [ -s "$p" ] && kill -0 "$(cat "$p")" 2>/dev/null; }

# start NOME comando... : sobe em sessão própria (setsid), com o pid do LÍDER no arquivo, para derrubar o grupo inteiro.
start() {
  local nome="$1"; shift
  if alive "$nome"; then say "$nome: já no ar (pid $(cat "$PIDS/$nome.pid"))"; return 0; fi
  mkdir -p "$PIDS" "$LOGS"
  rm -f "$PIDS/$nome.pid"
  : >"$LOGS/$nome.log"
  setsid bash -c 'echo $$ >"$1"; log="$2"; shift 2; exec "$@" >>"$log" 2>&1' _ "$PIDS/$nome.pid" "$LOGS/$nome.log" "$@" </dev/null &
  local i
  for i in $(seq 1 50); do [ -s "$PIDS/$nome.pid" ] && break; sleep 0.1; done
  [ -s "$PIDS/$nome.pid" ] || die "$nome: não subiu"
  say "$nome: subiu (pid $(cat "$PIDS/$nome.pid"))"
}

stop() {
  local nome="$1" p="$PIDS/$1.pid"
  [ -s "$p" ] || return 0
  local pid; pid="$(cat "$p")"
  if kill -0 "$pid" 2>/dev/null; then
    kill -TERM -- "-$pid" 2>/dev/null || kill -TERM "$pid" 2>/dev/null || true
    local i
    for i in $(seq 1 100); do kill -0 "$pid" 2>/dev/null || break; sleep 0.1; done
    kill -0 "$pid" 2>/dev/null && { kill -KILL -- "-$pid" 2>/dev/null || true; say "$nome: precisou de SIGKILL"; }
  fi
  rm -f "$p"
}

esperar() { # esperar "descrição" segundos comando...
  local d="$1" t="$2"; shift 2
  local i
  for i in $(seq 1 "$t"); do "$@" >/dev/null 2>&1 && { say "$d: ok"; return 0; }; sleep 1; done
  die "$d: não ficou pronto em ${t}s (logs em lab/.run/logs)"
}

porta_livre() { ! ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]$1\$"; }

# serve_central: o servidor embutido do PHP, DIRETO (e não `artisan serve`, que muda de porta em silêncio quando a sua está ocupada: a sonda
# ficaria falando com a porta errada). Se a porta está ocupada por outro processo, PARA com a mensagem.
serve_central() {
  esperar "a porta $CENTRAL_PORT está livre" 15 porta_livre "$CENTRAL_PORT"
  start central bash -c "cd '$ROOT/central' && exec php -S 127.0.0.1:$CENTRAL_PORT -t public"
}

# Captura antes de procurar: `grep -q` fecha o pipe cedo e o curl sai com 23 sob pipefail (a página é maior que um buffer).
painel_ok() { local h; h="$(curl -fsS "$CENTRAL/painel")" || return 1; [[ "$h" == *'data-teste="faixa-ambiente"'* ]]; }
central_ok() { curl -fsS "$CENTRAL/api/v1/health" | grep -q '"status":"ok"'; }
alvo_ok() { curl -fsS -D - -o /dev/null "http://127.0.0.1:$TARGET_PORT/health" | grep -qi '^x-b3mon-lab: lab'; }
sonda_viva() { [ "$(sql -c "select count(*) from probe_status where received_at > now() - interval '30 seconds'")" -ge 2 ]; }

# ---- sondas ----
config_sonda() {
  local nome="$1" dir="$PROBES/$1"
  cat >"$dir/probe.json" <<JSON
{
  "probe_id": "$(tr -d '\n' <"$dir/probe_id")",
  "central_url": "$CENTRAL",
  "token_file": "$dir/token",
  "policy_file": "$ROOT/agents/probe/politica/lab.json",
  "spool_path": "$dir/estado/spool.db",
  "bundle_cache_path": "$dir/estado/bundle.json",
  "step_interval_ms": 500,
  "heartbeat_interval_s": 5,
  "config_refresh_interval_s": 10,
  "clock": { "status": "synchronized", "offset_ms": 0, "reference_kind": "lab_declared" },
  "capabilities": { "ipv4": "available", "ipv6": "unknown", "icmp": "unknown" }
}
JSON
  chmod 600 "$dir/probe.json"
}

cmd_up() {
  for c in php composer go curl setsid; do command -v "$c" >/dev/null || die "falta o comando '$c' (veja docs/runbook.md §1)"; done
  mkdir -p "$RUN" "$BIN" "$LOGS" "$PIDS"

  say "PostgreSQL de desenvolvimento"
  bash "$HERE/pg.sh" up >/dev/null
  say "PostgreSQL: no ar (banco b3mon_lab)"

  if [ ! -f "$ROOT/central/vendor/autoload.php" ]; then
    say "central sem dependências: composer install (primeira vez, usa a rede)"
    (cd "$ROOT/central" && composer install --no-interaction --no-progress --prefer-dist >"$LOGS/composer.log" 2>&1) || die "composer install falhou (lab/.run/logs/composer.log)"
  fi

  if [ ! -f "$ROOT/central/public/build/manifest.json" ]; then
    command -v npm >/dev/null || die "falta o comando 'npm' (o painel precisa compilar o CSS; veja docs/runbook.md §1)"
    say "painel sem CSS compilado: npm ci e npm run build (primeira vez, usa a rede)"
    (cd "$ROOT/central" && { [ -d node_modules ] || npm ci --no-audit --no-fund; } && npm run build) >"$LOGS/npm.log" 2>&1 || { tail -20 "$LOGS/npm.log" >&2; die "build do painel falhou (lab/.run/logs/npm.log)"; }
  fi

  central_env
  say "migrações no banco do laboratório"
  artisan migrate --force >"$LOGS/migrate.log" 2>&1 || { tail -20 "$LOGS/migrate.log" >&2; die "migrate falhou"; }

  say "compilando o alvo e a sonda"
  (cd "$HERE/target" && go build -trimpath -o "$BIN/b3mon-lab-target" .) || die "go build do alvo falhou"
  (cd "$ROOT/agents/probe" && go build -trimpath -ldflags "-X main.versao=lab -X main.commit=$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)" -o "$BIN/b3mon-probe" ./cmd/b3mon-probe) || die "go build da sonda falhou"

  say "semeando: duas sondas (lab-dom-a e lab-dom-b), o check e os assignments"
  artisan b3mon:lab-seed --out "$PROBES" --target-url "http://127.0.0.1:$TARGET_PORT/health" --interval 5 || die "lab-seed falhou"
  for s in lab-a lab-b; do mkdir -p "$PROBES/$s/estado"; config_sonda "$s"; done

  start alvo "$BIN/b3mon-lab-target" -listen "127.0.0.1:$TARGET_PORT" -control "127.0.0.1:$CONTROL_PORT" -slow 4s
  serve_central
  esperar "central responde" 30 central_ok
  esperar "alvo responde e se identifica como lab" 15 alvo_ok

  for f in ingest decide notify; do
    start "worker-$f" bash -c "cd '$ROOT/central' && exec php artisan b3mon:work $f"
  done
  start agendador bash -c "cd '$ROOT/central' && exec php artisan schedule:work"

  for s in lab-a lab-b; do
    start "sonda-$s" "$BIN/b3mon-probe" run -config "$PROBES/$s/probe.json"
  done
  esperar "as duas sondas mandaram heartbeat" 60 sonda_viva
  say "laboratório no ar. Alvo em modo '$(curl -fsS "http://127.0.0.1:$CONTROL_PORT/modo" | jq -r .mode)'. Próximo: make demo"
}

cmd_down() {
  # As sondas primeiro (SIGTERM: terminam o passo e fecham o spool), depois quem as recebe.
  for n in sonda-lab-a sonda-lab-b agendador worker-ingest worker-decide worker-notify central alvo; do stop "$n"; done
  bash "$HERE/pg.sh" down >/dev/null
  say "laboratório parado (o estado fica em lab/.run e lab/.pgdata)"
}

cmd_status() {
  local n ok=0
  for n in alvo central worker-ingest worker-decide worker-notify agendador sonda-lab-a sonda-lab-b; do
    if alive "$n"; then printf '  %-14s no ar (pid %s)\n' "$n" "$(cat "$PIDS/$n.pid")"; else printf '  %-14s PARADO\n' "$n"; ok=1; fi
  done
  bash "$HERE/pg.sh" status >/dev/null 2>&1 && printf '  %-14s no ar\n' postgresql || { printf '  %-14s PARADO\n' postgresql; ok=1; }
  return $ok
}

cmd_smoke() {
  local falhas=0
  chk() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then printf '  ok   %s\n' "$d"; else printf '  FALHA %s\n' "$d"; falhas=$((falhas+1)); fi; }
  chk "central responde e alcança o banco" central_ok
  chk "o painel responde (loopback) e se identifica como laboratório" painel_ok
  chk "alvo responde e se identifica como lab" alvo_ok
  chk "as duas sondas mandaram heartbeat nos últimos 30 s" sonda_viva
  chk "nenhum item de fila parado como dead" sem_dead
  cmd_status >/dev/null 2>&1 && printf '  ok   todos os processos de pé\n' || { printf '  FALHA há processo parado\n'; falhas=$((falhas+1)); }
  [ "$falhas" = 0 ] || exit 1
}
sem_dead() { [ "$(psql_dead)" = 0 ]; }
psql_dead() { sql -c "select (select count(*) from ingestion_work where status='dead') + (select count(*) from decision_work where status='dead') + (select count(*) from notification_outbox where status='dead')"; }

cmd_mode() {
  case "${1:-}" in ok|falha|lento) ;; *) die "uso: lab/lab.sh mode ok|falha|lento" ;; esac
  curl -fsS -X POST "http://127.0.0.1:$CONTROL_PORT/modo/$1" | jq -c .
}

cmd_probes() {
  case "${1:-}" in
    stop) stop sonda-lab-a; stop sonda-lab-b; say "sondas paradas (SIGTERM: fecharam o spool)" ;;
    start)
      for s in lab-a lab-b; do start "sonda-$s" "$BIN/b3mon-probe" run -config "$PROBES/$s/probe.json"; done ;;
    *) die "uso: lab/lab.sh probes stop|start" ;;
  esac
}

cmd_central() {
  case "${1:-}" in
    stop) stop central; say "central (web) parada: os workers e o banco seguem de pé" ;;
    start)
      central_env
      serve_central
      esperar "central responde" 30 central_ok ;;
    *) die "uso: lab/lab.sh central stop|start" ;;
  esac
}

# Funções que o demo usa.
if [ "${1:-}" = "--source" ]; then return 0 2>/dev/null || exit 0; fi

case "${1:-}" in
  up) cmd_up ;;
  down) cmd_down ;;
  status) cmd_status ;;
  smoke) cmd_smoke ;;
  mode) shift; cmd_mode "${1:-}" ;;
  probes) shift; cmd_probes "${1:-}" ;;
  central) shift; cmd_central "${1:-}" ;;
  *) die "uso: lab/lab.sh up|down|status|smoke|mode|probes|central" ;;
esac
