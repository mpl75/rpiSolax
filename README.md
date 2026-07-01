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

## Credits

Charts use [uPlot](https://github.com/leeoniya/uPlot) (MIT), vendored in
`assets/` — see `assets/uplot-LICENSE.txt`.
