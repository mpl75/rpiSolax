#!/bin/bash
#
# Agregace syrových 5s logů do 10minutových záznamů.
# Spouští se 1×/den (systemd timer). Soubory starší než `keepDays` dní
# (kromě dneška) sloučí do 10min oken a původní syrový soubor zazipuje.
# Zpracuje logy střídače (logs/raw -> logs/agg) i wallboxu
# (logs/evse/raw -> logs/evse/agg); okna začínají na stejných časech,
# takže křivky v grafu sedí na sebe.
#
# Pravidla agregace v 10min okně – střídač:
#   - okamžité výkony (W)        -> průměr  (pv, total, feedIn, load, battery, inverter)
#   - kumulativní denní kWh      -> poslední hodnota v okně (jsou rostoucí)
#   - SoC, teploty, kapacita     -> průměr
#   - selfSufficiencyRate        -> průměr
#   - inverterMode, batteryMode  -> poslední hodnota (stav)
# Wallbox:
#   - výkony, proudy, napětí, teplota                       -> průměr
#   - stav, energie/začátek/délka nabití, celkem kWh, max A -> poslední hodnota

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/solax.conf"

logdir=${logdir:-$SCRIPT_DIR/logs}
keepDays=${keepDays:-1}   # kolik celých předešlých dní nechat v 5s granularitě

HEADER="timestamp,pv1Power,pv2Power,totalPower,totalProduction,totalProductionInclBatt,feedInPower,totalGridIn,totalGridOut,load,batteryPower,totalChargedIn,totalChargedOut,batterySoC,batteryCap,batteryTemp,inverterTemp,inverterPower,totalConsumption,selfSufficiencyRate,inverterMode,batteryMode"
# musí sedět s EVSE_HEADER v evse.php
EVSE_HEADER="timestamp,power,powerL1,powerL2,powerL3,currentL1,currentL2,currentL3,voltageL1,voltageL2,voltageL3,state,sessionEnergy,sessionStart,sessionDuration,totalEnergy,temp,maxCurrent"

# --- střídač (22 sloupců) ---
AWK_INVERTER='
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
'

# --- wallbox (18 sloupců) ---
AWK_EVSE='
  function round(x){ return (x>=0) ? int(x+0.5) : -int(-x+0.5) }
  function flush(){
    if (n==0) return
    printf "%d,%d,%d,%d,%d,%.2f,%.2f,%.2f,%.1f,%.1f,%.1f,%s,%s,%s,%s,%s,%d,%s\n",
      cur,
      round(s2/n), round(s3/n), round(s4/n), round(s5/n),
      s6/n, s7/n, s8/n,
      s9/n, s10/n, s11/n,
      l12, l13, l14, l15, l16,
      round(s17/n),
      l18
    n=0; s2=s3=s4=s5=s6=s7=s8=s9=s10=s11=s17=0
  }
  BEGIN { print hdr }
  NR==1 { next }
  NF<18 { next }
  {
    b = int($1/600)*600
    if (n>0 && b!=cur) flush()
    cur=b; n++
    s2+=$2; s3+=$3; s4+=$4; s5+=$5; s6+=$6; s7+=$7; s8+=$8
    s9+=$9; s10+=$10; s11+=$11; s17+=$17
    l12=$12; l13=$13; l14=$14; l15=$15; l16=$16; l18=$18
  }
  END { flush() }
'

# Převod data YYYY-MM-DD na epoch (lokální půlnoc), kompatibilní s Linuxem i macOS.
toEpoch() {
  date -d "$1" +%s 2>/dev/null || date -j -f "%Y-%m-%d" "$1" +%s 2>/dev/null
}

today=$(date +%F)
todayEpoch=$(toEpoch "$today")
threshold=$(( todayEpoch - keepDays * 86400 ))
count=0

# aggregateDir <rawDir> <aggDir> <hlavička> <awk program>
aggregateDir() {
  local raw=$1 agg=$2 hdr=$3 prog=$4
  [ -d "$raw" ] || { echo "Nic k agregaci ($raw neexistuje)."; return 0; }
  mkdir -p "$agg"

  local f d de out
  for f in "$raw"/*.csv; do
    [ -e "$f" ] || continue
    d=$(basename "$f" .csv)
    de=$(toEpoch "$d") || continue
    [ -n "$de" ] || continue
    (( de < threshold )) || continue   # mladší soubory necháme v 5s

    out="$agg/$d.csv"
    # LC_ALL=C: aby awk četl i tiskl desetinnou TEČKU (pod cs_CZ by jinak
    # parsoval "5.1" jako 5 a tiskl %.1f s čárkou a rozbil CSV).
    if LC_ALL=C awk -F, -v OFS=, -v hdr="$hdr" "$prog" "$f" >"$out.tmp"; then
      mv "$out.tmp" "$out"
      gzip -f "$f"          # syrový soubor zazipujeme jako zálohu (D.csv -> D.csv.gz)
      count=$((count + 1))
      echo "Agregováno: $f -> $out (syrový soubor zazipován)"
    else
      rm -f "$out.tmp"
      echo "CHYBA při agregaci $f" >&2
    fi
  done
}

aggregateDir "$logdir/raw" "$logdir/agg" "$HEADER" "$AWK_INVERTER"
aggregateDir "$logdir/evse/raw" "$logdir/evse/agg" "$EVSE_HEADER" "$AWK_EVSE"

echo "Hotovo. Zpracováno souborů: $count"
