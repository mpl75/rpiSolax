# Nasazení rpiSolax na Raspberry

Vše leží v jednom adresáři `/var/www/html/rpiSolax` (vlastník `www-data`,
stejně jako galerie). Logger i agregace běží jako `www-data`.

## Struktura (plochá – repo je 1:1 se serverem)

Struktura repa je **identická** s tím, co běží na Pi – žádné přesouvání ani
zplošťování při nasazení. `index.php` hledá `config.json`, `solax.conf`
i `assets/` ve své vlastní složce, takže vše leží vedle sebe:

```
index.php             # PHP aplikace (jediný vstupní bod, servíruje i assety)
assets/               # app.js, style.css, uPlot, ikony, manifest.json
config.sample.json    # VZOR configu webu (v gitu); reálný config.json je gitignored
solax.conf.sample     # VZOR configu skriptu (v gitu); reálný solax.conf je gitignored
solax.sh              # logger (5s smyčka, TUI + volitelně log do CSV)
solax-aggregate.sh    # denní agregace raw 5s -> 10min
systemd/              # 3 unit soubory
deploy/               # tento návod + Apache snippet
.htaccess             # zákaz přímého přístupu k .json/.csv/.conf/.sh
```

Reálné (tajné) `config.json` a `solax.conf` **nejsou v gitu** – vzniknou
z `*.sample` souborů (viz níže). Lokálně je edituješ ty, na Pi je dostane sync.

## 1. Nahrání souborů

**Průběžný deploy (doporučeno):** ze svého repa spusť zrcadlící skript –
udělá z Pi přesnou kopii lokálu (kromě `logs/`, viz `sync-rpi.sh`):

```bash
./sync-rpi.sh -n     # dry-run: ukáže, co by se změnilo
./sync-rpi.sh        # ostrý sync
```

**První instalace na čistém Pi** (než existují reálné configy) – ruční varianta:

```bash
rsync -a --rsync-path="sudo rsync" \
  --exclude='.git/' --exclude='logs/' --exclude='*.csv*' \
  ./ michal@raspberrypi.local:/var/www/html/rpiSolax/
sudo chown -R www-data:www-data /var/www/html/rpiSolax
sudo chmod +x /var/www/html/rpiSolax/solax.sh /var/www/html/rpiSolax/solax-aggregate.sh
sudo -u www-data mkdir -p /var/www/html/rpiSolax/logs
```

## 2. Konfigurace (z *.sample vzorů)

```bash
cd /var/www/html/rpiSolax
# solax.conf: reálné url/sn, na Pi zapni logování (log=1)
sudo -u www-data cp solax.conf.sample solax.conf
sudo nano solax.conf                             # url=..., sn=..., log=1

# config.json: přihlašování do webu
sudo -u www-data cp config.sample.json config.json
php -r 'echo password_hash("TVOJE_HESLO", PASSWORD_DEFAULT), "\n";'   # bcrypt hash
sudo nano config.json                            # vlož hash + náhodný authSecret
```

> Pozn.: reálné `config.json` a `solax.conf` jsou gitignored. Když je jednou
> vytvoříš (tady i lokálně), `./sync-rpi.sh` je pak udržuje sladěné.

## 3. Systemd (logger + denní agregace 03:30)

```bash
sudo cp /var/www/html/rpiSolax/systemd/solax-logger.service /etc/systemd/system/
sudo cp /var/www/html/rpiSolax/systemd/solax-aggregate.{service,timer} /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now solax-logger.service
sudo systemctl enable --now solax-aggregate.timer
journalctl -u solax-logger -n 20 --no-pager
ls /var/www/html/rpiSolax/logs/raw/   # měl by přibýt dnešní .csv
```

## 4. Apache

Viz `apache-rpiSolax.snippet.conf` – přidat do `<VirtualHost *:443>`
v `rpi-gallery.conf`. **Nejdřív kontrola, pak reload:**

```bash
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Hotovo: `https://rpi.politzer.cz/solax`
