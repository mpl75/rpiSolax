#!/bin/bash

# (c) 2024 Michal Politzer

# Adresář skriptu – aby config i logy fungovaly i mimo CWD (systemd).
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/solax.conf"

# Výchozí hodnoty pro logování (v solax.conf: na Macu log=0, na Pi log=1)
log=${log:-0}
logdir=${logdir:-$SCRIPT_DIR/logs}
delay=${delay:-4}

# Hlavička CSV – pořadí MUSÍ sedět s logSample níže, s agregací a FIELDS v index.php
LOG_HEADER="timestamp,pv1Power,pv2Power,totalPower,totalProduction,totalProductionInclBatt,feedInPower,totalGridIn,totalGridOut,load,batteryPower,totalChargedIn,totalChargedOut,batterySoC,batteryCap,batteryTemp,inverterTemp,inverterPower,totalConsumption,selfSufficiencyRate,inverterMode,batteryMode"

unsignedToSigned() {
  local value=$1
  if ((value > 32767)); then
    value=$((value - 65536))
  fi
  echo "$value"
}

progress_bar() {
  local val=$1
  local max=$2
  local bar_length=20

  local progress=$((val * bar_length / max))
  local progress_bar=""

  for ((i=0; i<bar_length; i++)); do
    if (( i < progress )); then
      progress_bar+="#"
    else
      progress_bar+="_"
    fi
  done

  echo -n "[$progress_bar]"
}

declare -a inverterModeMap
inverterModeMap[0]="Waiting"
inverterModeMap[1]="Checking"
inverterModeMap[2]="Normal"
inverterModeMap[3]="Off"
inverterModeMap[4]="Permanent Fault"
inverterModeMap[5]="Updating"
inverterModeMap[6]="EPS Check"
inverterModeMap[7]="EPS Mode"
inverterModeMap[8]="Self Test"
inverterModeMap[9]="Idle"
inverterModeMap[10]="Standby"

declare -a batteryModeMap
batteryModeMap[0]="Self Use Mode"
batteryModeMap[1]="Force Time Use"
batteryModeMap[2]="Back Up Mode"
batteryModeMap[3]="Feed-in Priority"

divLine="-------------------------------------------------\r"

# Připíše jeden CSV řádek do logs/raw/RRRR-MM-DD.csv (surová čísla s tečkou).
logSample() {
  local line=$1
  local dir="$logdir/raw"
  local file="$dir/$(date +%F).csv"
  [ -d "$dir" ] || mkdir -p "$dir"
  [ -f "$file" ] || echo "$LOG_HEADER" >"$file"
  echo "$line" >>"$file"
}

while true; do
  response=$(curl -s -d "optType=ReadRealTimeData&pwd=$sn" -X POST $url)
  data=$(echo "$response" | jq -r '[.Data[14], .Data[15], .Data[82] / 10, .Data[70] / 10, .Data[34], (.Data[93] * 65536 + .Data[92]) / 100, (.Data[91] * 65536 + .Data[90]) / 100, .Data[47], .Data[41], .Data[79] / 10, .Data[78] / 10, .Data[103], .Data[106] / 10, .Data[105], .Data[54], .Data[9], .Data[19], .Data[168]] | @tsv')
  read pv1Power pv2Power totalProduction totalProductionInclBatt feedInPower totalGridIn totalGridOut load batteryPower totalChargedIn totalChargedOut batterySoC batteryCap batteryTemp inverterTemp inverterPower inverterMode batteryMode <<< "$data"

  totalConsumption=$(echo "$totalGridIn + $totalProductionInclBatt - $totalGridOut" | bc)
  selfSufficiencyRate=$(echo "($totalProductionInclBatt - $totalGridOut) * 100 / $totalConsumption" | bc)

  totalPower=$((pv1Power + pv2Power))
  totalPeak=$((peak1 + peak2))
  feedInPower=$(unsignedToSigned "$feedInPower")
  batteryPower=$(unsignedToSigned "$batteryPower")
  load=$(unsignedToSigned "$load")
  inverterPower=$(unsignedToSigned "$inverterPower")

  # --- LOG (surová čísla s tečkou, ještě před přepisem na čárky) ---
  if ((log == 1)); then
    logSample "$(date +%s),$pv1Power,$pv2Power,$totalPower,$totalProduction,$totalProductionInclBatt,$feedInPower,$totalGridIn,$totalGridOut,$load,$batteryPower,$totalChargedIn,$totalChargedOut,$batterySoC,$batteryCap,$batteryTemp,$inverterTemp,$inverterPower,$totalConsumption,$selfSufficiencyRate,$inverterMode,$batteryMode"
  fi

  # --- TUI (jen když je výstupem terminál – pod systemd se přeskočí) ---
  if [ -t 1 ]; then
    totalConsumption=${totalConsumption/./,}
    selfSufficiencyRate=${selfSufficiencyRate/./,}
    totalProduction=${totalProduction/./,}
    totalProductionInclBatt=${totalProductionInclBatt/./,}
    totalGridIn=${totalGridIn/./,}
    totalGridOut=${totalGridOut/./,}
    totalChargedIn=${totalChargedIn/./,}
    totalChargedOut=${totalChargedOut/./,}
    batteryCap=${batteryCap/./,}

    clear

    echo -ne "$divLine"
    echo -e "\033[3C PANELY "
    echo "        celkem: $(printf "%5d" "$totalPower") W   $(progress_bar $totalPower $totalPeak)"
    echo "      string 1: $(printf "%5d" "$pv1Power") W   $(progress_bar $pv1Power $peak1)"
    echo "      string 2: $(printf "%5d" "$pv2Power") W   $(progress_bar $pv2Power $peak2)"
    echo "dnes výroba DC: $(printf "%5.1f" "$totalProduction") kWh"
    echo ""
    echo -ne "$divLine"
    echo -e "\033[3C BATERIE "
    echo "                          $(printf "%3d" "$batterySoC") %        $(printf "%5d" "$batteryTemp") °C"
    echo "        nabití: $(printf "%5.1f" "$batteryCap") kWh $(progress_bar $batterySoC 100)"
    if ((batteryPower >= 0)); then
      printf "      nabíjení: \e[36m$(printf "%5d" "$batteryPower") W\e[0m\n"
    else
      printf "      vybíjení: \e[31m$(printf "%5d" "$batteryPower") W\e[0m\n"
    fi
    echo "   dnes nabito: $(printf "%5.1f" "$totalChargedIn") kWh"
    echo "        vybito: $(printf "%5.1f" "$totalChargedOut") kWh"
    echo ""
    echo -ne "$divLine"
    echo -e "\033[3C STŘÍDAČ [${inverterModeMap[$inverterMode]}] "
    echo "                                       $(printf "%5d" "$inverterTemp") °C"
    echo "         výkon: $(printf "%5d" "$inverterPower") W   $(progress_bar $inverterPower $maxPower)"
    echo "dnes výroba AC: $(printf "%5.1f" "$totalProductionInclBatt") kWh"
    echo ""
    echo -ne "$divLine"
    echo -e "\033[3C DISTRIBUČNÍ SÍŤ "
    if ((feedInPower < 0)); then
      printf "         odběr: \e[31m$(printf "%5d" "$feedInPower") W\e[0m\n"
    else
      printf "       dodávka: \e[36m$(printf "%5d" "$feedInPower") W\e[0m\n"
    fi
    echo " dnes odebráno: $(printf "%5.2f" "$totalGridIn") kWh"
    echo "        dodáno: $(printf "%5.2f" "$totalGridOut") kWh"
    echo ""
    echo -ne "$divLine"
    echo -e "\033[3C DŮM "
    echo "aktuální odběr: $(printf "%5d" "$load") W   $(progress_bar $load $maxLoad)"
    echo " dnes spotřeba: $(printf "%5.1f" "$totalConsumption") kWh"
    echo "  soběstačnost:   $(printf "%3d" "$selfSufficiencyRate") %   $(progress_bar $selfSufficiencyRate 100)"
    echo ""

    symbols="/-\|"

    for ((w=0; w<$delay; w++)); do
      for ((i=0; i<${#symbols}; i++)); do
        echo -n "${symbols:$i:1}"
        sleep 0.25
        echo -ne "\r"
      done
    done
  else
    # Headless (systemd) – žádné TUI, jen čekání mezi vzorky
    sleep "$delay"
  fi
done
