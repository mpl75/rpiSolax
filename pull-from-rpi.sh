#!/usr/bin/env bash
#
# Stažení naměřených dat z Raspberry Pi na Mac (protisměr k sync-rpi.sh).
# Přírůstkově zrcadlí logs/ z Pi do ./logs-from-pi/ BEZ --delete:
# lokální archiv drží vše, co kdy na Pi bylo.
#
# Použití:
#   ./pull-from-rpi.sh       # stáhne nové/změněné soubory a uklidí duplicity
#   ./pull-from-rpi.sh -n    # dry-run: jen vypíše, co by se stáhlo/smazalo
#
# Na Pi solax-aggregate.sh po dni udělá z raw/D.csv agg/D.csv (10 min)
# a syrový soubor zabalí na raw/D.csv.gz (5 s rozlišení zůstává). Lokálně by
# tak vedle sebe zůstal starý neúplný raw/D.csv a kompletní raw/D.csv.gz –
# skript proto po stažení smaže raw/D.csv, k němuž už existuje raw/D.csv.gz.
#
# Každý běh zapíše jeden řádek do logs-from-pi/_pull.log.
# Když je Pi nedostupné, skončí tiše s kódem 2 a nic nemaže.
# Spouští ho launchd 1×/den – viz launchd/ a README.md.
#
set -uo pipefail

HOST="rpi"                                   # alias z ~/.ssh/config
REMOTE_DIR="/var/www/html/rpiSolax/logs/"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEST="$SCRIPT_DIR/logs-from-pi"
LOG="$DEST/_pull.log"
SSH_OPTS="-o BatchMode=yes -o ConnectTimeout=10 -o LogLevel=ERROR"

DRY=""
if [ "${1:-}" = "-n" ] || [ "${1:-}" = "--dry-run" ]; then
  DRY="--dry-run"
fi

mkdir -p "$DEST"

logLine() {
  printf '%s\t%s\t%s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" "$2" >>"$LOG"
}

# Pi nedostupné -> tiše pryč, nic nemazat.
if ! ssh $SSH_OPTS "$HOST" true 2>/dev/null; then
  logLine 0 "FAIL: $HOST nedostupné"
  exit 2
fi

out=$(rsync -a --itemize-changes $DRY -e "ssh $SSH_OPTS" \
  "$HOST:$REMOTE_DIR" "$DEST/" 2>&1)
rc=$?
files=$(printf '%s\n' "$out" | grep -c '^>f')

[ -n "$DRY" ] && printf '%s\n' "$out" | grep '^>f'

if [ $rc -ne 0 ]; then
  [ -n "$DRY" ] && printf '%s\n' "$out" >&2
  logLine "$files" "FAIL: rsync kód $rc"
  exit 1
fi

# Úklid: syrový den už existuje jako .gz -> neúplný .csv je zbytečný.
removed=0
for gz in "$DEST"/raw/*.csv.gz; do
  [ -e "$gz" ] || continue
  csv="${gz%.gz}"
  [ -e "$csv" ] || continue
  if [ -n "$DRY" ]; then
    echo "smazal bych: raw/$(basename "$csv")"
  else
    rm -f "$csv"
  fi
  removed=$((removed + 1))
done

if [ -n "$DRY" ]; then
  echo "DRY RUN: souborů ke stažení $files, duplicit ke smazání $removed"
  logLine "$files" "DRY (duplicit $removed)"
else
  logLine "$files" "OK (smazáno duplicit $removed)"
fi
