#!/usr/bin/env bash
#
# Jednosměrné zrcadlení lokální složky na Raspberry Pi (okamžitý deploy).
# Zdroj pravdy je LOKÁL: soubor smazaný lokálně se smaže i na Pi (rsync --delete).
# Struktura repa je 1:1 se serverem (žádné „zplošťování"), takže je to prostý mirror.
# Spouštěj odkudkoli – cesty se odvozují od umístění skriptu.
#
# Použití:
#   ./sync-rpi.sh          # ostrý sync včetně mazání osiřelých souborů na Pi
#   ./sync-rpi.sh -n       # dry-run: jen vypíše, co by se změnilo, nic nezmění
#
# Přenáší se i reálné (gitignored) configy config.json a solax.conf –
# lokál je jejich zdrojem pravdy, aby se úpravy vždy dostaly i na server.
#
# NEPŘENÁší se / NEMAže se na serveru:
#   logs/            živá data (CSV zapisuje logger jako www-data)
#   .git/ .claude/   metadata repa / editor
#   .DS_Store .nova  smetí macOS/editoru
#   sync-rpi.sh      tenhle skript (na Pi nemá co dělat)
#
# Služby se ZÁMĚRNĚ nerestartují. Když se změní solax.sh nebo systemd unita,
# je potřeba je restartovat ručně (po domluvě): sudo systemctl restart solax-logger
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REMOTE="michal@raspberrypi.local:/var/www/html/rpiSolax/"

DRY=""
if [ "${1:-}" = "-n" ] || [ "${1:-}" = "--dry-run" ]; then
  DRY="--dry-run"
  echo "=== DRY RUN — nic se nezmění ==="
fi

# Tajné soubory drž jen pro vlastníka+skupinu (Apache = www-data je čte).
# rsync -a přenese práva 1:1, takže na Pi budou taky 640.
chmod 640 "$SCRIPT_DIR/config.json" "$SCRIPT_DIR/solax.conf" 2>/dev/null || true

# Vzdálený rsync běží jako www-data => zapsané soubory rovnou patří www-data
# (žádný následný chown není potřeba). Lokální rsync na macOS je starý (2.6.9),
# proto se nepoužívá --chown (to umí až rsync 3.1+).
rsync -avh --delete $DRY \
  --exclude='.git/' \
  --exclude='.claude/' \
  --exclude='.DS_Store' \
  --exclude='.nova' \
  --exclude='logs/' \
  --exclude='*.csv' \
  --exclude='*.csv.gz' \
  --exclude='/sync-rpi.sh' \
  --rsync-path='sudo -u www-data rsync' \
  "$SCRIPT_DIR/" "$REMOTE"

echo
echo "Hotovo. (Pozn.: služby se nerestartovaly – při změně solax.sh / systemd uniti restartuj ručně.)"
