# Nasazení rpiSolax na Raspberry

Vše leží v jednom adresáři `/var/www/html/rpiSolax` (vlastník `www-data`,
stejně jako galerie). Logger i agregace běží jako `www-data`.

## Struktura (v repu)

```
solax.sh              # logger (5s smyčka, TUI + volitelně log do CSV)
solax.conf            # konfigurace (na Pi log=1 + reálné url/sn)
solax-aggregate.sh    # denní agregace raw 5s -> 10min
web/                  # PHP aplikace + assety
systemd/              # 3 unit soubory
deploy/               # tento návod + Apache snippet
```

Na Pi se vše slije do `/var/www/html/rpiSolax/` (web nahoře + skripty vedle).

## 1. Nahrání souborů (rsync přímo do docrootu přes sudo)

```bash
# z Macu – web do kořene aplikace
rsync -a --rsync-path="sudo rsync" web/ michal@raspberrypi.local:/var/www/html/rpiSolax/
# z Macu – skripty + systemd + deploy vedle webu
rsync -a --rsync-path="sudo rsync" \
  solax.sh solax.conf solax-aggregate.sh systemd deploy \
  michal@raspberrypi.local:/var/www/html/rpiSolax/
```

Práva + složka na logy:

```bash
sudo chown -R www-data:www-data /var/www/html/rpiSolax
sudo chmod +x /var/www/html/rpiSolax/solax.sh /var/www/html/rpiSolax/solax-aggregate.sh
sudo -u www-data mkdir -p /var/www/html/rpiSolax/logs
```

## 2. Konfigurace

```bash
# solax.conf: nastav reálné url/sn a zapni logování
sudo nano /var/www/html/rpiSolax/solax.conf      # url=..., sn=..., log=1

# config.json (NENÍ v repu) z config.sample.json
sudo -u www-data cp /var/www/html/rpiSolax/config.sample.json /var/www/html/rpiSolax/config.json
# bcrypt hash hesla pro web:
php -r 'echo password_hash("TVOJE_HESLO", PASSWORD_DEFAULT), "\n";'
sudo nano /var/www/html/rpiSolax/config.json     # vlož hash k uživateli
```

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
