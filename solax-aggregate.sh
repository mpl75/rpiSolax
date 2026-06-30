#!/bin/bash
#
# Agregace syrových 5s logů do 10minutových záznamů.
# Spouští se 1×/den (systemd timer). Soubory starší než `keepDays` dní
# (kromě dneška) sloučí do 10min oken a původní syrový soubor zazipuje.
#
# Pravidla agregace v 10min okně:
#   - okamžité výkony (W)        -> průměr  (pv, total, feedIn, load, battery, inverter)
#   - kumulativní denní kWh      -> poslední hodnota v okně (jsou rostoucí)
#   - SoC, teploty, kapacita     -> průměr
#   - selfSufficiencyRate        -> průměr
#   - inverterMode, batteryMode  -> poslední hodnota (stav)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/solax.conf"

logdir=${logdir:-$SCRIPT_DIR/logs}
keepDays=${keepDays:-1}   # kolik celých předešlých dní nechat v 5s granularitě

raw="$logdir/raw"
agg="$logdir/agg"
[ -d "$raw" ] || { echo "Nic k agregaci ($raw neexistuje)."; exit 0; }
mkdir -p "$agg"

HEADER="timestamp,pv1Power,pv2Power,totalPower,totalProduction,totalProductionInclBatt,feedInPower,totalGridIn,totalGridOut,load,batteryPower,totalChargedIn,totalChargedOut,batterySoC,batteryCap,batteryTemp,inverterTemp,inverterPower,totalConsumption,selfSufficiencyRate,inverterMode,batteryMode"

# Převod data YYYY-MM-DD na epoch (lokální půlnoc), kompatibilní s Linuxem i macOS.
toEpoch() {
  date -d "$1" +%s 2>/dev/null || date -j -f "%Y-%m-%d" "$1" +%s 2>/dev/null
}

today=$(date +%F)
todayEpoch=$(toEpoch "$today")
threshold=$(( todayEpoch - keepDays * 86400 ))

count=0
for f in "$raw"/*.csv; do
  [ -e "$f" ] || continue
  d=$(basename "$f" .csv)
  de=$(toEpoch "$d") || continue
  [ -n "$de" ] || continue
  (( de < threshold )) || continue   # mladší soubory necháme v 5s

  out="$agg/$d.csv"
  # LC_ALL=C: aby awk četl i tiskl desetinnou TEČKU (pod cs_CZ by jinak
  # parsoval "5.1" jako 5 a tiskl %.1f s čárkou a rozbil CSV).
  if LC_ALL=C awk -F, -v OFS=, -v hdr="$HEADER" '
    function round(x){ return (x>=0) ? int(x+0.5) : -int(-x+0.5) }
    function flush(){
      if (n==0) return
      printf "%d,%d,%d,%d,%s,%s,%d,%s,%s,%d,%d,%s,%s,%d,%.1f,%d,%d,%d,%s,%d,%s,%s\n",
        cur,
        round(s2/n), round(s3/n), round(s4/n),
        l5, l6,
        round(s7/n),
        l8, l9,
        round(s10/n), round(s11/n),
        l12, l13,
        round(s14/n), s15/n,
        round(s16/n), round(s17/n), round(s18/n),
        l19,
        round(s20/n),
        l21, l22
      n=0; s2=s3=s4=s7=s10=s11=s14=s15=s16=s17=s18=s20=0
    }
    BEGIN { print hdr }
    NR==1 { next }                 # přeskoč hlavičku vstupu
    NF<22 { next }                 # ochrana proti useknutým řádkům
    {
      b = int($1/600)*600
      if (n>0 && b!=cur) flush()
      cur=b; n++
      s2+=$2; s3+=$3; s4+=$4; s7+=$7; s10+=$10; s11+=$11
      s14+=$14; s15+=$15; s16+=$16; s17+=$17; s18+=$18; s20+=$20
      l5=$5; l6=$6; l8=$8; l9=$9; l12=$12; l13=$13; l19=$19; l21=$21; l22=$22
    }
    END { flush() }
  ' "$f" >"$out.tmp"; then
    mv "$out.tmp" "$out"
    gzip -f "$f"          # syrový soubor zazipujeme jako zálohu (D.csv -> D.csv.gz)
    count=$((count + 1))
    echo "Agregováno: $d -> $out (syrový soubor zazipován)"
  else
    rm -f "$out.tmp"
    echo "CHYBA při agregaci $f" >&2
  fi
done

echo "Hotovo. Zpracováno souborů: $count"
