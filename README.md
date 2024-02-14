Bash script for Raspberry Pi command line for online monitor Solax inverter

## Instalation
- download to any directory (f.e. ~/rpiSolax)
- set solax.sh as executable (chmod 0775 solax.sh)
- edit solax.conf (use your Solax Inverter serial number, your local IP address, set string 1 and string 2 maximum power (kWp), set delay between refresh (default is 4 seconds)
- install command line JSON parser 'jq' if missing (sudo apt install jq)

## Usage
enter yourDirectory/solax.sh (f.e. ~/rpiSolax/solax.sh)
press Ctrl + C to end script
