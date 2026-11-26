<?php
// rpiSolax – hlídání provozu a upozornění do mobilu přes ntfy.
// Spouští ho systemd timer (systemd/solax-alerts.timer) každých 5 minut.
// Každá kontrola má stav ok/problém; zpráva odejde jen při změně stavu
// (problém začal / problém skončil), takže se nic neopakuje každých 5 minut.
// Stav se drží v logs/alerts-state.json.
//
//   php alerts.php          # jedna kontrola (to dělá timer)
//   php alerts.php --test   # zkušební zpráva – ověření odběru v appce ntfy
//   php alerts.php --dry    # jen vypíše stav kontrol, nic nepošle ani neuloží
//
// Musí běžet i na PHP 7.4 (Raspberry).

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/lib.php';   // lastRow, tailRows, readShellConf

const STALE_S        = 600;        // logger bez nového vzorku déle než 10 min = výpadek
const ZERO_FROM_H    = 10;         // okno, ve kterém nulová výroba znamená problém
const ZERO_TO_H      = 14;
const ZERO_WINDOW_S  = 3600;       // výroba 0 W souvisle aspoň hodinu
const INVERTER_FAULT = 4;          // inverterMode „Permanent Fault"
const EVSE_FAULT     = 4;          // stav wallboxu „Faulted"

$config = json_decode((string)@file_get_contents(__DIR__ . '/config.json'), true) ?: [];
$conf   = readShellConf(__DIR__ . '/solax.conf');
$logDir = ($conf['logdir'] ?? '') !== '' ? $conf['logdir'] : ($config['logDir'] ?? __DIR__ . '/logs');
$ntfy   = $config['notify']['ntfy'] ?? [];
$args   = array_slice($argv, 1);
$dry    = in_array('--dry', $args, true);

if (in_array('--test', $args, true)) {
    send($ntfy, 'Solax FVE – zkušební zpráva', 'Upozornění z rpiSolax fungují.', 3, ['white_check_mark']);
    echo "Zkušební zpráva odeslána.\n";
    exit(0);
}

// ---------- kontroly: true = problém, false = v pořádku, null = nelze posoudit (stav beze změny)
$now    = time();
$inv    = lastSample($logDir . '/raw', $now);
$checks = [];

$checks['inverterStale'] = [
    'bad'  => $inv === null || $now - (int)$inv[0] > STALE_S,
    'on'   => ['Solax: logger střídače nezapisuje', 'Žádný nový vzorek ze střídače déle než '
               . (STALE_S / 60) . ' min' . ($inv ? ' (poslední ' . date('j. n. H:i', (int)$inv[0]) . ').' : '.')
               . ' Zkontroluj solax-logger a střídač.'],
    'off'  => ['Solax: logger střídače zase zapisuje', 'Data ze střídače znovu přicházejí.'],
];

if ($inv !== null && $now - (int)$inv[0] <= STALE_S) {
    $checks['inverterFault'] = [
        'bad' => (int)($inv[20] ?? -1) === INVERTER_FAULT,
        'on'  => ['Solax: porucha střídače', 'Střídač hlásí režim Permanent Fault.'],
        'off' => ['Solax: střídač bez poruchy', 'Střídač už nehlásí poruchu.'],
    ];
    $checks['zeroProduction'] = [
        'bad' => zeroProduction($logDir . '/raw', $now),
        'on'  => ['Solax: přes den nulová výroba', 'Panely nevyrábějí už aspoň hodinu, i když je den. '
                  . 'Sníh na panelech, nebo porucha?'],
        'off' => ['Solax: panely zase vyrábějí', 'Výroba z panelů se obnovila.'],
    ];
}

if (($conf['evseHost'] ?? '') !== '') {
    $ev = lastSample($logDir . '/evse/raw', $now);
    $checks['evseStale'] = [
        'bad' => $ev === null || $now - (int)$ev[0] > STALE_S,
        'on'  => ['Solax: logger wallboxu nezapisuje', 'Žádný nový vzorek z wallboxu déle než '
                  . (STALE_S / 60) . ' min. Zkontroluj solax-evse a síť wallboxu.'],
        'off' => ['Solax: logger wallboxu zase zapisuje', 'Data z wallboxu znovu přicházejí.'],
    ];
    if ($ev !== null && $now - (int)$ev[0] <= STALE_S) {
        $checks['evseFault'] = [
            'bad' => (int)($ev[11] ?? -1) === EVSE_FAULT,
            'on'  => ['Solax: porucha wallboxu', 'Wallbox hlásí stav Faulted.'],
            'off' => ['Solax: wallbox bez poruchy', 'Wallbox už nehlásí poruchu.'],
        ];
    }
}

// ---------- porovnání s posledním stavem a odeslání změn
$stateFile = $logDir . '/alerts-state.json';
$state     = json_decode((string)@file_get_contents($stateFile), true) ?: [];

foreach ($checks as $key => $c) {
    $was = !empty($state[$key]['active']);
    if ($dry) {
        printf("%-15s %s%s\n", $key, $c['bad'] === null ? '?' : ($c['bad'] ? 'PROBLÉM' : 'ok'), $was ? ' (hlášeno)' : '');
        continue;
    }
    if ($c['bad'] === null || $c['bad'] === $was) continue;
    try {
        if ($c['bad']) {
            send($ntfy, $c['on'][0], $c['on'][1], 4, ['warning']);
            $state[$key] = ['active' => true, 'since' => $now];
        } else {
            $since = (int)($state[$key]['since'] ?? $now);
            send($ntfy, $c['off'][0], $c['off'][1] . ' Trvalo ' . duration($now - $since) . '.', 3, ['white_check_mark']);
            $state[$key] = ['active' => false, 'since' => $now];
        }
    } catch (RuntimeException $e) {
        // stav neuložíme – zkusí se znovu při dalším běhu
        fwrite(STDERR, 'alerts.php: ' . $e->getMessage() . "\n");
    }
}

if (!$dry) {
    file_put_contents($stateFile . '.tmp', json_encode($state, JSON_PRETTY_PRINT) . "\n");
    rename($stateFile . '.tmp', $stateFile);
}

// =====================================================================

/** Poslední řádek dnešního (popř. včerejšího) syrového logu, null když není. */
function lastSample(string $dir, int $now): ?array {
    foreach ([date('Y-m-d', $now), date('Y-m-d', $now - 86400)] as $d) {
        $row = lastRow("$dir/$d.csv");
        if ($row !== null) return $row;
    }
    return null;
}

/** Nulová výroba přes den: v okně ZERO_FROM_H–ZERO_TO_H je celkový výkon panelů
 *  poslední hodinu pořád 0 W. Mimo okno: problém trvá, dokud se výroba neobjeví
 *  (null = beze změny), aby večer nepřišlo falešné „vyrábějí". */
function zeroProduction(string $dir, int $now): ?bool {
    $rows   = tailRows("$dir/" . date('Y-m-d', $now) . '.csv', $now - ZERO_WINDOW_S);
    $maxPv  = null;
    foreach ($rows as $r) {
        $maxPv = max($maxPv ?? 0, (int)($r[3] ?? 0));   // sloupec totalPower
    }
    if ($maxPv === null) return null;                    // žádná data – řeší inverterStale
    if ($maxPv > 0) return false;
    $h = (int)date('G', $now);
    $inWindow = $h >= ZERO_FROM_H && $h < ZERO_TO_H && $now - ZERO_WINDOW_S >= strtotime(ZERO_FROM_H . ':00', $now);
    return $inWindow ? true : null;
}

/** Odeslání do ntfy (JSON na kořen serveru – titulek s diakritikou nejde přes hlavičku). */
function send(array $cfg, string $title, string $body, int $priority, array $tags): void {
    if (empty($cfg['enabled'])) throw new RuntimeException('ntfy je vypnuté (notify.ntfy.enabled)');
    $topic = (string)($cfg['topic'] ?? '');
    if ($topic === '' || strpos($topic, 'REPLACE') !== false) throw new RuntimeException('ntfy: v configu chybí topic');
    $server  = rtrim((string)($cfg['server'] ?? 'https://ntfy.sh'), '/');
    $headers = "Content-Type: application/json\r\n";
    if (($cfg['token'] ?? '') !== '') $headers .= 'Authorization: Bearer ' . $cfg['token'] . "\r\n";
    $payload = json_encode(['topic' => $topic, 'title' => $title, 'message' => $body,
                            'priority' => $priority, 'tags' => $tags], JSON_UNESCAPED_UNICODE);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => $headers, 'content' => $payload,
        'timeout' => 15, 'ignore_errors' => true,
    ]]);
    $resp   = @file_get_contents($server, false, $ctx);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\b/',$http_response_header[0], $m) ? (int)$m[1] : 0;
    if ($status !== 200) throw new RuntimeException("ntfy: HTTP $status " . substr(trim((string)$resp), 0, 200));
}

/** Délka výpadku česky: „25 min", „2 h 5 min", „3 d 4 h". */
function duration(int $s): string {
    $m = max(1, (int)round($s / 60));
    if ($m < 60) return "$m min";
    if ($m < 1440) return intdiv($m, 60) . ' h ' . ($m % 60) . ' min';
    $h = (int)round($m / 60);
    return intdiv($h, 24) . ' d ' . ($h % 24) . ' h';
}
