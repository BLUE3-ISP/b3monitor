#!/usr/bin/env bash
# lab/pg.sh — PostgreSQL 17 de DESENVOLVIMENTO do b3monitor, em espaço de usuário.
#
#   lab/pg.sh up       cria o cluster na primeira vez, sobe, e garante roles e bancos
#   lab/pg.sh down     para (fast shutdown); os dados ficam em lab/.pgdata
#   lab/pg.sh status   diz se está no ar e em que porta
#
# Por que assim (ADR-005, D-01; A5 da fila):
#   - roda como o usuário que chama: sem sudo, sem tocar o PostgreSQL do sistema (porta 5432);
#   - porta 54329, só em 127.0.0.1, e socket Unix em lab/.pgrun;
#   - dados em lab/.pgdata e lab/.pgrun, que o ADR-003 deixa fora do git.
#
# ⚠️ AUTENTICAÇÃO `trust` em loopback: QUALQUER processo local conecta como qualquer role. É
# aceitável porque este cluster guarda só dados de laboratório, identificados como tais, e
# nunca uma credencial ou dado de produção. Se um dia guardar algo que importe, troque
# `trust` por `scram-sha-256` em pg_hba.conf — o resto do script não muda.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BIN=/usr/lib/postgresql/17/bin
DATA="$HERE/.pgdata"
RUN="$HERE/.pgrun"
HOST=127.0.0.1
PORT=54329
LOG="$DATA/server.log"
DBS=(b3mon_dev b3mon_test b3mon_lab)

# Nada do ambiente pode desviar para o cluster do sistema.
unset PGHOST PGPORT PGUSER PGDATABASE PGSERVICE PGPASSFILE PGPASSWORD PGSERVICEFILE

die() { printf 'pg.sh: %s\n' "$*" >&2; exit 1; }
running() { [ -f "$DATA/PG_VERSION" ] && "$BIN/pg_ctl" -D "$DATA" status >/dev/null 2>&1; }
sql() { "$BIN/psql" -h "$HOST" -p "$PORT" -U postgres -d postgres -v ON_ERROR_STOP=1 -qAt "$@"; }

cmd_up() {
  [ -x "$BIN/initdb" ] || die "não achei $BIN/initdb (postgresql-17 instalado?)"

  if running; then
    echo "já no ar: $HOST:$PORT"
  else
    # A porta é nossa. Se outro processo a segura, parar aqui é melhor que subir em silêncio
    # numa porta que outra coisa responde.
    if ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]${PORT}\$"; then
      die "a porta $PORT já está em uso por outro processo; nada foi iniciado"
    fi
    if [ ! -f "$DATA/PG_VERSION" ]; then
      echo "criando o cluster em lab/.pgdata (primeira vez)"
      "$BIN/initdb" -D "$DATA" -U postgres --encoding=UTF8 --locale=C.UTF-8 \
        --auth-local=trust --auth-host=trust >/dev/null
    fi
    mkdir -p "$RUN" && chmod 700 "$RUN"
    "$BIN/pg_ctl" -D "$DATA" -l "$LOG" -w \
      -o "-p $PORT -k $RUN -c listen_addresses=$HOST" start >/dev/null
    echo "no ar: $HOST:$PORT"
  fi

  for db in "${DBS[@]}"; do
    [ "$(sql -c "select 1 from pg_roles where rolname = '$db'")" = 1 ] || sql -c "create role $db login"
    [ "$(sql -c "select 1 from pg_database where datname = '$db'")" = 1 ] || sql -c "create database $db owner $db"
  done
  echo "roles e bancos: ${DBS[*]}"
}

cmd_down() {
  if running; then
    "$BIN/pg_ctl" -D "$DATA" -m fast -w stop >/dev/null
    echo "parado (dados em lab/.pgdata)"
  else
    echo "já parado"
  fi
}

cmd_status() {
  if running; then
    echo "no ar: $HOST:$PORT"
    sql -c "select 'versão: ' || version()"
    sql -c "select 'bancos: ' || string_agg(datname, ', ' order by datname) from pg_database where datname like 'b3mon_%'"
  else
    echo "parado"
    exit 3
  fi
}

case "${1:-}" in
  up) cmd_up ;;
  down) cmd_down ;;
  status) cmd_status ;;
  *) die "uso: lab/pg.sh up|down|status" ;;
esac
