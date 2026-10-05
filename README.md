# rpiSolax

Monitoring for a Solax hybrid inverter on a Raspberry Pi — a terminal (TUI)
monitor, a CSV logger, and a small self-hosted web dashboard with history.

Reads real-time data straight from the inverter's local API
(`optType=ReadRealTimeData`), so it works fully offline on your LAN.

## Components

| File | What it does |
|------|--------------|
| `solax.sh` | Live TUI monitor (~5 s refresh); optionally logs each sample to CSV (`log=1`). |
| `solax-aggregate.sh` | Daily job: condenses raw ~5 s logs older than a day into ~10-min records and gzips the rest. |
| `index.php` + `assets/` | Web dashboard: current values + history charts (uPlot), login-protected. |
| `systemd/` | Units to run the logger and the daily aggregation on the Pi. |
| `deploy/` | Raspberry Pi / Apache setup guide + vhost snippet. |
| `sync-rpi.sh` | One-way mirror of this folder to the Pi (deploy). |
| `pull-from-rpi.sh` + `launchd/` | Daily pull of the Pi's `logs/` into `logs-from-pi/` on the Mac (archive for analysis). |

## Configuration

Two config files hold your private values and are **not** in git — create them
from the samples:

```bash
cp solax.conf.sample solax.conf          # inverter IP, serial, string peaks, log on/off
cp config.sample.json config.json        # web dashboard users (bcrypt) + authSecret
```

Edit `solax.conf`:
- `url` — inverter IP (e.g. `http://192.168.1.100`)
- `sn` — inverter serial number (used as the local-API password)
- `peak1` / `peak2` — string 1 / 2 peak power in W (for the bar gauges)
- `maxPower`, `maxLoad` — scale for the total-power / house-load bars
- `delay` — refresh seconds (default 4)
- `log` — `1` = write CSV (on the Pi), `0` = TUI only (e.g. on a laptop)

## Quick start (TUI only)

```bash
sudo apt install jq          # JSON parser used by the script
chmod +x solax.sh
./solax.sh                    # Ctrl+C to quit
```

## Web dashboard + logging on a Raspberry Pi

See **[deploy/README.md](deploy/README.md)** for the full walkthrough
(files layout, systemd units, Apache). The repo layout is identical to what
runs on the Pi, so deployment is a plain mirror:

```bash
./sync-rpi.sh -n             # dry run — show what would change
./sync-rpi.sh                # mirror this folder to the Pi
```

`sync-rpi.sh` never touches the server's `logs/` (live data) and keeps the real
`config.json` / `solax.conf` in sync (they stay out of git).

## Pulling data to the Mac

`pull-from-rpi.sh` copies the Pi's `logs/` into `./logs-from-pi/` (gitignored,
never synced back). It is incremental and never deletes anything that came from
the Pi — the only local cleanup is `raw/D.csv` once `raw/D.csv.gz` exists (the
Pi's aggregation gzips each finished raw day, so the `.csv` is just an
incomplete older copy). The full ~5 s resolution survives on the Pi as
`raw/*.csv.gz`, so pulling once a day loses nothing. The host is the `rpi`
alias from `~/.ssh/config` (key auth, no passwords in the script).

```bash
./pull-from-rpi.sh -n        # dry run
./pull-from-rpi.sh           # pull now; one line per run in logs-from-pi/_pull.log
```

Install the daily launchd agent (the first run, triggered on load, pulls the
whole history):

```bash
mkdir -p logs-from-pi
cp launchd/cz.politzer.rpisolax-pull.plist ~/Library/LaunchAgents/
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/cz.politzer.rpisolax-pull.plist
```

Check it is running:

```bash
launchctl print gui/$(id -u)/cz.politzer.rpisolax-pull | grep -E 'state|runs|last exit'
tail logs-from-pi/_pull.log
```

If the Mac is asleep when a run is due, launchd runs it once after wake-up
(missed runs are coalesced, not queued). If the Pi is unreachable the run
exits with code 2 and logs `FAIL`; the next run catches up. To remove:
`launchctl bootout gui/$(id -u)/cz.politzer.rpisolax-pull`.

## Credits

Charts use [uPlot](https://github.com/leeoniya/uPlot) (MIT), vendored in
`assets/` — see `assets/uplot-LICENSE.txt`.
