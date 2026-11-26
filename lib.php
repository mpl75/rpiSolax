<?php
// rpiSolax – sdílené pomocné funkce pro index.php, evse.php a alerts.php
// (čtení CSV logů od konce, načtení solax.conf). Musí běžet i na PHP 7.4.

declare(strict_types=1);

/** Přečte ze syrového CSV jen řádky s timestampem >= $fromTs (chronologicky).
 *  Čte soubor od konce po blocích, takže cena nezávisí na velikosti celého
 *  dne – toho využívá živý graf a dlaždice, které se dotazují v smyčce. */
function tailRows(string $file, int $fromTs): array {
    $fh = @fopen($file, 'rb');
    if (!$fh) return [];
    $pos = fstat($fh)['size'];
    $buf = '';
    while ($pos > 0) {
        $chunk = min(8192, $pos);
        $pos  -= $chunk;
        fseek($fh, $pos);
        $buf = fread($fh, $chunk) . $buf;
        // Jakmile je nejstarší kompletní řádek v bufferu starší než $fromTs,
        // zbytek souboru už není potřeba (řádky jsou chronologické).
        $nl = strpos($buf, "\n");
        if ($nl !== false && (int)substr($buf, $nl + 1, 12) < $fromTs) break;
    }
    fclose($fh);
    $rows = [];
    foreach (explode("\n", $buf) as $line) {
        $line = trim($line);
        // (int) vezme číslo do první čárky; hlavičku ("timestamp,...") i případný
        // neúplný první řádek to vyhodnotí < $fromTs a zahodí
        if ($line === '' || (int)$line < $fromTs) continue;
        $rows[] = explode(',', $line);
    }
    return $rows;
}

/** Poslední řádek syrového CSV (bez čtení celého souboru), null když není. */
function lastRow(string $file): ?array {
    $fh = @fopen($file, 'rb');
    if (!$fh) return null;
    $size = fstat($fh)['size'];
    fseek($fh, max(0, $size - 8192));
    $lines = explode("\n", fread($fh, 8192));
    fclose($fh);
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim($lines[$i]);
        if ($line !== '' && (int)$line > 0) return explode(',', $line);
    }
    return null;
}

/** Načte klíč=hodnota ze solax.conf (bashová syntaxe, komentáře #). */
function readShellConf(string $file): array {
    $out = [];
    if (!is_file($file)) return $out;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim(trim($v), "\"'");
    }
    return $out;
}
