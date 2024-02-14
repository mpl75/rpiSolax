#!/bin/bash

# (c) 2024 Michal Politzer

source $(dirname "$0")/solax.conf

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

while true; do
  response=$(curl -s -d "optType=ReadRealTimeData&pwd=$sn" -X POST $url)
  data=$(echo "$response" | jq -r '[.Data[14], .Data[15], .Data[82] / 10, .Data[70] / 10, .Data[34], (.Data[93] * 65536 + .Data[92]) / 100, (.Data[91] * 65536 + .Data[90]) / 100, .Data[47], .Data[41], .Data[79] / 10, .Data[78] / 10, .Data[103], .Data[106] / 10, .Data[105], .Data[54], .Data[9]] | @tsv')
  read pv1Power pv2Power totalProduction totalProductionInclBatt feedInPower totalGridIn totalGridOut load batteryPower totalChargedIn totalChargedOut batterySoC batteryCap batteryTemp inverterTemp inverterPower <<< "$data"

  totalConsumption=$(echo "$totalGridIn + $totalProductionInclBatt" | bc)
  totalConsumption=${totalConsumption/./,}

  totalPower=$((pv1Power + pv2Power))
  totalPeak=$((peak1 + peak2))
  feedInPower=$(unsignedToSigned "$feedInPower")
  batteryPower=$(unsignedToSigned "$batteryPower")
  load=$(unsignedToSigned "$load")
  inverterPower=$(unsignedToSigned "$inverterPower")
  totalProduction=${totalProduction/./,}
  totalProductionInclBatt=${totalProductionInclBatt/./,}
  totalGridIn=${totalGridIn/./,}
  totalGridOut=${totalGridOut/./,}
  totalChargedIn=${totalChargedIn/./,}
  totalChargedOut=${totalChargedOut/./,}
  batteryCap=${batteryCap/./,}

  clear

  echo "--- PANELY --------------------------------------"
  echo "        celkem: $(printf "%5d" "$totalPower") W   $(progress_bar $totalPower $totalPeak)"
  echo "      string 1: $(printf "%5d" "$pv1Power") W   $(progress_bar $pv1Power $peak1)"
  echo "      string 2: $(printf "%5d" "$pv2Power") W   $(progress_bar $pv2Power $peak2)"
  echo "dnes výroba DC: $(printf "%5.1f" "$totalProduction") kWh"
  echo ""
  echo "--- BATERIE -------------------------------------"
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
  echo "--- STŘÍDAČ -------------------------------------"
  echo "         výkon: $(printf "%5d" "$inverterPower") W   $(progress_bar $inverterPower $maxPower)"
  echo "dnes výroba AC: $(printf "%5.1f" "$totalProductionInclBatt") kWh"
  echo "       teplota: $(printf "%5d" "$inverterTemp") °C"
  echo ""
  echo "--- DISTRIBUČNÍ SÍŤ -----------------------------"
  if ((feedInPower < 0)); then
    printf "         odběr: \e[31m$(printf "%5d" "$feedInPower") W\e[0m\n"
  else
    printf "       dodávka: \e[36m$(printf "%5d" "$feedInPower") W\e[0m\n"
  fi
  echo " dnes odebráno: $(printf "%5.2f" "$totalGridIn") kWh"
  echo "        dodáno: $(printf "%5.2f" "$totalGridOut") kWh"
  echo ""
  echo "--- DŮM  ----------------------------------------"
  echo "aktuální odběr: $(printf "%5d" "$load") W   $(progress_bar $load $maxLoad)"
  echo " dnes spotřeba: $(printf "%5.1f" "$totalConsumption") kWh"
  echo ""

  symbols="/-\|"

  for ((w=0; w<$delay; w++)); do
    for ((i=0; i<${#symbols}; i++)); do
      echo -n "${symbols:$i:1}"
      sleep 0.25
      echo -ne "\r"
    done
  done
done
