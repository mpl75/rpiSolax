<?php
// rpiSolax – vizualizace FVE. Jediný vstupní bod (po vzoru rpiGallery).
// Řeší: přihlášení (session + CSRF + rate-limit + "zapamatuj si mě" cookie),
// JSON API nad CSV logy a vykreslení dashboardu.

declare(strict_types=1);
session_start();

// ---------- Konfigurace ----------
$config = json_decode(@file_get_contents(__DIR__ . '/config.json'), true);
if (!is_array($config)) {
    $config = ['users' => []];
}
// Kde leží CSV logy (zapisuje je solax.sh). Lze přepsat v config.json.
$LOG_DIR = $config['logDir'] ?? '/var/www/html/rpiSolax/logs';

$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$secure = !empty($_SERVER['HTTPS']); // lokálně po HTTP funguje taky

// Pořadí sloupců v CSV (musí sedět se solax.sh a solax-aggregate.sh).
// Definováno nahoře, protože top-level `const` se v PHP nehoistuje.
const FIELDS = [
    'timestamp', 'pv1Power', 'pv2Power', 'totalPower', 'totalProduction',
    'totalProductionInclBatt', 'feedInPower', 'totalGridIn', 'totalGridOut',
    'load', 'batteryPower', 'totalChargedIn', 'totalChargedOut', 'batterySoC',
    'batteryCap', 'batteryTemp', 'inverterTemp', 'inverterPower', 'totalConsumption',
    'selfSufficiencyRate', 'inverterMode', 'batteryMode',
];

// ---------- Auth tokeny (HMAC, podepsaná "zapamatuj si mě" cookie) ----------
const TOKEN_TTL = 90 * 86400; // platnost tokenu (90 dní)

// Vyhrazený náhodný klíč z config.json; fallback na hash prvního uživatele
// (kompatibilita, aby chybějící authSecret nikoho nevyhodil).
function authSecret(array $config): string {
    $s = $config['authSecret'] ?? '';
    return $s !== '' ? $s : ($config['users'][0]['password'] ?? 'x');
}
function makeAuthToken(string $user, array $config): string {
    $exp     = time() + TOKEN_TTL;
    $payload = $user . '|' . $exp;
    $sig     = hash_hmac('sha256', $payload, authSecret($config));
    return base64_encode($payload . '|' . $sig);
}
// Vrátí ['user','admin'] jen pro platný, neexpirovaný token EXISTUJÍCÍHO uživatele.
// Práva (admin) se čtou z aktuálního configu, ne z tokenu.
function verifyAuthToken(string $token, array $config): ?array {
    $decoded = base64_decode($token, true);
    if (!$decoded) return null;
    $parts = explode('|', $decoded);
    if (count($parts) !== 3) return null;
    [$user, $exp, $sig] = $parts;
    $expected = hash_hmac('sha256', $user . '|' . $exp, authSecret($config));
    if (!hash_equals($expected, $sig)) return null;   // špatný podpis
    if ((int)$exp < time()) return null;              // expirovaný token
    foreach ($config['users'] as $u) {                // uživatel musí stále existovat
        if (($u['user'] ?? null) === $user) {
            return ['user' => $user, 'admin' => !empty($u['admin'])];
        }
    }
    return null;
}

// ---------- Odhlášení ----------
if (isset($_GET['logout'])) {
    session_destroy();
    setcookie('solax_auth', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: /solax');
    exit;
}

// ---------- Přihlášení ----------
$loginError = false;
if (isset($_POST['login_user'], $_POST['login_pass'])) {
    if (($_POST['csrf'] ?? '') !== $_SESSION['csrf']) {
        $loginError = true;
    } else {
        $ipHash   = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
        $failFile = sys_get_temp_dir() . '/solax_login_fails_' . $ipHash . '.json';
        $fails    = is_file($failFile) ? (json_decode(file_get_contents($failFile), true) ?: []) : [];
        $fails    = array_filter($fails, fn($t) => $t > time() - 900);
        if (count($fails) >= 5) {
            http_response_code(429);
            echo 'Příliš mnoho pokusů. Zkuste to za 15 minut.';
            exit;
        }
        $user = null;
        foreach ($config['users'] as $u) {
            if ($_POST['login_user'] === $u['user'] && password_verify($_POST['login_pass'], $u['password'])) {
                $user = $u;
                break;
            }
        }
        if ($user) {
            session_regenerate_id(true);
            $_SESSION['csrf']          = bin2hex(random_bytes(32));
            $_SESSION['authenticated'] = true;
            $_SESSION['user']          = $user['user'];
            $_SESSION['admin'] = !empty($user['admin']);
            $token = makeAuthToken($user['user'], $config);
            setcookie('solax_auth', $token, ['expires' => time() + TOKEN_TTL, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
            header('Location: /solax');
            exit;
        }
        $fails[] = time();
        file_put_contents($failFile, json_encode($fails));
        $loginError = true;
    }
}

// ---------- Obnova přihlášení z cookie ----------
if (empty($_SESSION['authenticated']) && isset($_COOKIE['solax_auth'])) {
    $authData = verifyAuthToken($_COOKIE['solax_auth'], $config);
    if ($authData) {
        $_SESSION['authenticated'] = true;
        $_SESSION['user']          = $authData['user'];
        $_SESSION['admin']         = $authData['admin'];
    }
}

// ---------- Router statických assetů (VEŘEJNÉ – manifest, ikony, css/js;
//            musí být PŘED bránou, aby šly načíst i pro PWA / přihlašovací stránku) ----------
if (isset($_GET['asset'])) {
    serveAsset($_GET['asset']);
    exit;
}

// ---------- Brána: bez přihlášení dál nepustíme ----------
if (empty($_SESSION['authenticated'])) {
    showLoginForm($loginError);
    exit;
}

// =====================================================================
//  Od tohoto místa je uživatel přihlášený
// =====================================================================

// ---------- JSON API ----------
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(handleApi($_GET, $LOG_DIR), JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Jinak: dashboard ----------
renderDashboard();
exit;


// =====================================================================
//  Funkce
// =====================================================================

/** Načte řádky jednoho dne. Preferuje agregát (10min), pak syrový (5s), pak .gz. */
function dayRows(string $logDir, string $date): array {
    foreach (["$logDir/agg/$date.csv", "$logDir/raw/$date.csv"] as $f) {
        if (is_file($f)) return readCsv($f);
    }
    $gz = "$logDir/raw/$date.csv.gz";
    if (is_file($gz)) return readCsv($gz, true);
    return [];
}

function readCsv(string $file, bool $gz = false): array {
    $rows = [];
    $fh   = $gz ? gzopen($file, 'rb') : fopen($file, 'rb');
    if (!$fh) return $rows;
    $first = true;
    while (($line = $gz ? gzgets($fh) : fgets($fh)) !== false) {
        if ($first) { $first = false; continue; } // přeskoč hlavičku
        $line = trim($line);
        if ($line === '') continue;
        $rows[] = explode(',', $line);
    }
    $gz ? gzclose($fh) : fclose($fh);
    return $rows;
}

function handleApi(array $q, string $logDir): array {
    $api = $q['api'];

    if ($api === 'current') {
        // poslední vzorek z dnešního (popř. včerejšího) syrového logu
        foreach ([date('Y-m-d'), date('Y-m-d', time() - 86400)] as $d) {
            $rows = dayRows($logDir, $d);
            if ($rows) {
                $last = end($rows);
                $obj  = [];
                foreach (FIELDS as $i => $name) {
                    $obj[$name] = isset($last[$i]) ? $last[$i] + 0 : null;
                }
                $obj['age'] = time() - (int)$obj['timestamp'];
                return ['ok' => true, 'sample' => $obj];
            }
        }
        return ['ok' => true, 'sample' => null];
    }

    if ($api === 'series') {
        $range = $q['range'] ?? 'day';
        $now   = time();

        // Volitelné konkrétní datum pro režim "day" (navigace po dnech).
        // Striktní validace formátu + checkdate = zároveň ochrana proti
        // path-traversal, protože dayRows() skládá cestu z $date.
        $date = null;
        if (isset($q['date']) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $q['date'], $m)
            && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            $date = $q['date'];
        }

        if ($range === 'day' && $date !== null) {
            $from = strtotime($date . ' 00:00:00');
            $to   = min($now, $from + 86400 - 1);   // budoucí dny se ořežou na teď
        } else {
            switch ($range) {
                case 'live':  $from = $now - 1800;       break; // 30 min
                case 'day':   $from = strtotime('today'); break;
                case 'week':  $from = $now - 7 * 86400;  break;
                case 'month': $from = $now - 30 * 86400; break;
                default:      $from = strtotime('today');
            }
            $to = $now;
        }

        // posbírej dny v rozsahu
        $cols = array_fill(0, count(FIELDS), []);
        $startDay = strtotime(date('Y-m-d', $from));
        for ($d = $startDay; $d <= $to; $d += 86400) {
            $rows = dayRows($logDir, date('Y-m-d', $d));
            foreach ($rows as $r) {
                $ts = (int)($r[0] ?? 0);
                if ($ts < $from || $ts > $to) continue;
                foreach (array_keys(FIELDS) as $i) {
                    $cols[$i][] = isset($r[$i]) ? $r[$i] + 0 : null;
                }
            }
        }
        return ['ok' => true, 'fields' => FIELDS, 'data' => $cols, 'count' => count($cols[0])];
    }

    return ['ok' => false, 'error' => 'unknown api'];
}

function serveAsset(string $name): void {
    $map = [
        'app.js'        => 'application/javascript; charset=utf-8',
        'style.css'     => 'text/css; charset=utf-8',
        'uplot.js'      => 'application/javascript; charset=utf-8',
        'uplot.css'     => 'text/css; charset=utf-8',
        'manifest.json' => 'application/manifest+json; charset=utf-8',
        'icon.svg'      => 'image/svg+xml',
        'icon-192.png'  => 'image/png',
        'icon-512.png'  => 'image/png',
        'icon-180.png'  => 'image/png',
    ];
    if (!isset($map[$name])) { http_response_code(404); exit; }
    $path = __DIR__ . '/assets/' . $name;
    if (!is_file($path)) { http_response_code(404); exit; }
    header('Content-Type: ' . $map[$name]);
    header('Cache-Control: public, max-age=3600');
    readfile($path);
}

function showLoginForm(bool $error): void {
    header('Content-Type: text/html; charset=utf-8');
    $csrf = htmlspecialchars($_SESSION['csrf']);
    $err  = $error ? '<div class="err">Nesprávné přihlašovací údaje</div>' : '';
    echo <<<HTML
<!DOCTYPE html><html lang="cs"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Solax – přihlášení</title>
<meta name="theme-color" content="#0e1116">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Solax">
<link rel="manifest" href="?asset=manifest.json">
<link rel="apple-touch-icon" href="?asset=icon-180.png">
<link rel="icon" type="image/png" href="?asset=icon-192.png">
<style>
  :root { color-scheme: dark; }
  body { margin:0; min-height:100vh; display:grid; place-items:center;
         font-family:system-ui,sans-serif; background:#0e1116; color:#e6edf3; }
  form { background:#161b22; padding:2rem; border-radius:14px; width:280px;
         box-shadow:0 10px 40px rgba(0,0,0,.5); }
  h1 { font-size:1.1rem; margin:0 0 1rem; text-align:center; }
  input { width:100%; box-sizing:border-box; margin:.4rem 0; padding:.7rem;
          border:1px solid #30363d; border-radius:8px; background:#0e1116; color:#e6edf3; }
  button { width:100%; margin-top:.6rem; padding:.7rem; border:0; border-radius:8px;
           background:#f5b301; color:#1a1a1a; font-weight:600; cursor:pointer; }
  .err { color:#ff6b6b; font-size:.85rem; text-align:center; margin-bottom:.5rem; }
</style></head><body>
<form method="post">
  <h1>☀️ Solax FVE</h1>
  $err
  <input type="hidden" name="csrf" value="$csrf">
  <input type="text" name="login_user" placeholder="Uživatel" autocomplete="username" required autofocus>
  <input type="password" name="login_pass" placeholder="Heslo" autocomplete="current-password" required>
  <button type="submit">Přihlásit</button>
</form>
</body></html>
HTML;
}

// Načte meze pro ukazatele (bary) ze solax.conf vedle index.php.
function readConf(): array {
    $limits = ['peak1' => 5000, 'peak2' => 5000, 'maxPower' => 10000, 'maxLoad' => 16000];
    $f = __DIR__ . '/solax.conf';
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k); $v = trim($v);
            if (isset($limits[$k]) && is_numeric($v)) $limits[$k] = (int)$v;
        }
    }
    return $limits;
}

function renderDashboard(): void {
    $user   = htmlspecialchars($_SESSION['user'] ?? '');
    $limits = json_encode(readConf());
    echo <<<HTML
<!DOCTYPE html><html lang="cs"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Solax FVE</title>
<meta name="theme-color" content="#0e1116">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Solax">
<link rel="manifest" href="?asset=manifest.json">
<link rel="apple-touch-icon" href="?asset=icon-180.png">
<link rel="icon" type="image/png" href="?asset=icon-192.png">
<link rel="stylesheet" href="?asset=uplot.css">
<link rel="stylesheet" href="?asset=style.css">
</head><body>
<header>
  <h1>☀️ Solax FVE</h1>
  <div class="head-right">
    <span id="status" class="status">–</span>
    <span class="user">$user</span>
    <a href="?logout" class="logout">Odhlásit</a>
  </div>
</header>

<main>
  <section class="tiles" id="tiles"><!-- živé hodnoty doplní JS --></section>

  <section class="charts">
    <div class="range">
      <button data-range="live" class="active">Živě</button>
      <button data-range="day">Den</button>
      <button data-range="week">Týden</button>
      <button data-range="month">Měsíc</button>
    </div>
    <div class="daynav" id="daynav" hidden>
      <button id="dayPrev" type="button" aria-label="Předchozí den">‹</button>
      <input type="date" id="dayPick" aria-label="Vybrat datum">
      <button id="dayNext" type="button" aria-label="Další den">›</button>
    </div>
    <div class="chart-card"><h2>Výkon (W)</h2><div id="chartPower"></div></div>
    <div class="chart-card"><h2>Stav baterie (%)</h2><div id="chartSoc"></div></div>
  </section>
</main>

<script>window.LIMITS = $limits;</script>
<script src="?asset=uplot.js"></script>
<script src="?asset=app.js"></script>
</body></html>
HTML;
}
