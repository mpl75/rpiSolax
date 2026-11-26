<?php
// rpiSolax – logger wallboxu Solax X3-HAC (Modbus TCP, jen čtení).
// Běží jako služba (systemd/solax-evse.service) vedle solax.sh: každých `delay`
// sekund vyčte stav nabíjení a připíše řádek do logs/evse/raw/RRRR-MM-DD.csv.
// S log=0 (Mac) zapisovat nebude, jen vypíše hodnoty na terminál.
//
// Mapa registrů podle integrace solax_modbus pro Home Assistant
// (plugin_solax_ev_charger.py, GEN2 = HAC), ověřeno proti živému wallboxu.
// Musí běžet i na PHP 7.4 (Raspberry).

declare(strict_types=1);

// Jen z příkazové řádky – přes web by nekonečná smyčka držela Apache proces
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/lib.php';   // readShellConf

// Pořadí sloupců – MUSÍ sedět s EVSE_FIELDS v index.php
const EVSE_HEADER = 'timestamp,power,powerL1,powerL2,powerL3,currentL1,currentL2,currentL3,'
    . 'voltageL1,voltageL2,voltageL3,state,sessionEnergy,sessionStart,sessionDuration,'
    . 'totalEnergy,temp,maxCurrent';

$conf    = readShellConf(__DIR__ . '/solax.conf');
$host    = $conf['evseHost'] ?? '';
$unit    = (int)($conf['evseUnit'] ?? 1);
$delay   = max(1, (int)($conf['delay'] ?? 4));
$log     = (int)($conf['log'] ?? 0) === 1;
$logDir  = ($conf['logdir'] ?? '') !== '' ? $conf['logdir'] : __DIR__ . '/logs';

if ($host === '') {
    fwrite(STDERR, "evse.php: v solax.conf chybí evseHost\n");
    exit(1);
}

$sock   = null;
$tid    = 0;
$failed = false;   // ať se výpadek do journalu hlásí jednou, ne každých 5 s

while (true) {
    try {
        if ($sock === null) $sock = mbConnect($host);
        // input 0x00–0x36: měření, stav, energie, začátek a délka nabíjení
        $in  = mbRead($sock, $tid, $unit, 4, 0x00, 0x37);
        // holding 0x61D: časový posun hodin wallboxu [min] (pro převod začátku nabíjení)
        $tz  = mbRead($sock, $tid, $unit, 3, 0x61D, 1);
        // holding 0x668: nastavený max. nabíjecí proud (pro škálování baru)
        $max = mbRead($sock, $tid, $unit, 3, 0x668, 1);
        if ($failed) { fwrite(STDERR, "evse.php: spojení s wallboxem obnoveno\n"); $failed = false; }

        $line = formatSample(time(), $in, $tz[0], $max[0]);
        if ($log) {
            appendCsv($logDir . '/evse/raw', $line);
        } else {
            echo EVSE_HEADER, "\n", $line, "\n\n";
        }
    } catch (RuntimeException $e) {
        if (!$failed) { fwrite(STDERR, 'evse.php: ' . $e->getMessage() . "\n"); $failed = true; }
        if ($sock !== null) { @fclose($sock); $sock = null; }
    }
    sleep($delay);
}

// ---------------------------------------------------------------------

/** Jeden řádek CSV ze surových registrů (desetinná tečka, bez locale). */
function formatSample(int $ts, array $r, int $tzRaw, int $maxRaw): string {
    $s16 = function (int $v): int { return $v > 32767 ? $v - 65536 : $v; };
    $u32 = function (int $lo, int $hi): int { return $hi * 65536 + $lo; };   // nižší slovo první

    // začátek nabíjení: sec, min, hod, den, měsíc, rok (2 číslice) v místním čase
    // wallboxu; na epoch převede posun jeho hodin (nezávisle na časové zóně PHP)
    $start = 0;
    if ($r[0x34] > 0 && $r[0x35] > 0) {
        $start = gmmktime($r[0x33], $r[0x32], $r[0x31], $r[0x35], $r[0x34], 2000 + $r[0x36]) - $s16($tzRaw) * 60;
    }

    $v = [
        $ts,
        $s16($r[0x0B]),                                   // výkon celkem [W]
        $s16($r[0x08]), $s16($r[0x09]), $s16($r[0x0A]),   // výkon L1–L3 [W]
        sprintf('%.2f', $s16($r[0x04]) / 100),            // proud L1–L3 [A]
        sprintf('%.2f', $s16($r[0x05]) / 100),
        sprintf('%.2f', $s16($r[0x06]) / 100),
        sprintf('%.1f', $r[0x00] / 100),                  // napětí L1–L3 [V]
        sprintf('%.1f', $r[0x01] / 100),
        sprintf('%.1f', $r[0x02] / 100),
        $r[0x1D],                                         // stav (0 volno, 2 nabíjí, 3 dokončeno…)
        sprintf('%.1f', $r[0x0F] / 10),                   // energie aktuálního/posledního nabití [kWh]
        $start,                                           // začátek nabití (epoch, 0 = žádné)
        $u32($r[0x2B], $r[0x2C]),                         // délka nabíjení [s]
        sprintf('%.1f', $u32($r[0x10], $r[0x11]) / 10),   // celkem nabito [kWh]
        $s16($r[0x1C]),                                   // teplota wallboxu [°C]
        sprintf('%.2f', $maxRaw / 100),                   // nastavený max. proud [A]
    ];
    return implode(',', $v);
}

function appendCsv(string $dir, string $line): void {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file = $dir . '/' . date('Y-m-d') . '.csv';
    if (!is_file($file)) file_put_contents($file, EVSE_HEADER . "\n");
    file_put_contents($file, $line . "\n", FILE_APPEND);
}

/** @return resource */
function mbConnect(string $host) {
    $sock = @fsockopen($host, 502, $errno, $errstr, 3.0);
    if (!$sock) throw new RuntimeException("nelze se připojit k $host:502 ($errstr)");
    stream_set_timeout($sock, 3);
    return $sock;
}

/** Modbus TCP čtení (FC3 holding / FC4 input), vrací pole 16bit hodnot. */
function mbRead($sock, int &$tid, int $unit, int $fc, int $addr, int $count): array {
    $tid = ($tid + 1) & 0xFFFF;
    fwrite($sock, pack('nnnCCnn', $tid, 0, 6, $unit, $fc, $addr, $count));
    $hdr = readExact($sock, 9);
    $h   = unpack('ntid/nproto/nlen/Cunit/Cfc/Cbytes', $hdr);
    if ($h['fc'] & 0x80) {
        throw new RuntimeException(sprintf('Modbus výjimka %d (FC%d, 0x%04X)', $h['bytes'], $fc, $addr));
    }
    if ($h['tid'] !== $tid || $h['bytes'] !== 2 * $count) {
        throw new RuntimeException('neočekávaná odpověď Modbus');
    }
    return array_values(unpack('n*', readExact($sock, $h['bytes'])));
}

function readExact($sock, int $n): string {
    $buf = '';
    while (strlen($buf) < $n) {
        $chunk = fread($sock, $n - strlen($buf));
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($sock);
            throw new RuntimeException(!empty($meta['timed_out']) ? 'wallbox neodpovídá (timeout)' : 'spojení přerušeno');
        }
        $buf .= $chunk;
    }
    return $buf;
}
