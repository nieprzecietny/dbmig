#!/usr/bin/env php
<?php
/**
 * dbmig.php - masowa migracja baz MySQL stron na hostingu wspoldzielonym
 *             (cyber_Folks / DirectAdmin) na nowa instancje MySQL.
 *
 * Uruchamiac NA SERWERZE przez SSH:   php dbmig.php <komenda> [opcje]
 *
 * Komendy:
 *   scan           przeszukuje katalogi stron, wyciaga dane dostepowe do baz z plikow
 *                  konfiguracyjnych, zapisuje inventory.csv / inventory.json oraz
 *                  szablon mapping.conf (do recznego uzupelnienia danych NOWYCH baz)
 *   check          sprawdza mapping.conf: polaczenia do starych i nowych baz, pliki konfig.
 *   show           pokazuje szczegoly wiersza (co i gdzie zostanie podmienione)
 *   dump           zrzut starych baz do <workdir>/dumps (mysqldump)
 *   import         import zrzutow do nowych baz (mysql)
 *   verify         porownanie listy tabel i liczby wierszy: stara vs nowa baza
 *   update-config  podmiana danych dostepowych w plikach konfiguracyjnych (z backupem)
 *   migrate        wszystko po kolei: check -> dump -> import -> verify -> update-config -> test HTTP
 *   rollback       przywraca pliki konfiguracyjne z backupu
 *   clean-new      usuwa wszystkie tabele w NOWEJ bazie (do ponowienia importu)
 *   status         pokazuje stan migracji
 *   reset          kasuje stan (state.json) dla wybranych wierszy
 *
 * Zasady bezpieczenstwa:
 *   - skrypt NIGDY nie modyfikuje ani nie kasuje starej bazy (tylko czyta - mysqldump),
 *   - kazdy plik konfiguracyjny jest kopiowany do backupu przed zmiana,
 *   - po zmianie pliku: php -l, ponowny odczyt wartosci, test HTTP strony; przy bledzie
 *     automatyczny rollback pliku,
 *   - nic nie dzieje sie bez `action = migrate` w mapping.conf i bez --site/--all,
 *   - --dry-run pokazuje dokladnie co by sie stalo, nic nie zmieniajac.
 *
 * Wymagania: PHP >= 7.2 CLI (z mysqli), mysqldump, mysql, curl (opcjonalnie).
 */

define('DBMIG_VERSION', '1.4.2');

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "dbmig.php: uruchom z linii polecen (CLI).\n"); exit(2); }
if (version_compare(PHP_VERSION, '7.2.0', '<')) {
    fwrite(STDERR, "dbmig.php: wymagane PHP >= 7.2, masz " . PHP_VERSION . ". Sprobuj np.: php82 dbmig.php ...\n");
    exit(2);
}
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
ini_set('memory_limit', '1024M');
set_time_limit(0);
if (!ini_get('date.timezone')) { date_default_timezone_set('Europe/Warsaw'); }
if (!function_exists('token_get_all')) { fwrite(STDERR, "dbmig.php: PHP bez rozszerzenia 'tokenizer' - sprobuj innego binarium php (np. php82).\n"); exit(2); }
if (!function_exists('mb_strlen')) { function mb_strlen($s) { return strlen((string)$s); } }

// =====================================================================
//  CLI / logowanie / pomocnicze
// =====================================================================

final class Cli
{
    public static $cmd = null;
    public static $opts = [];
    public static $args = [];

    const WITH_VALUE = ['workdir', 'root', 'site', 'depth', 'mapping', 'mysql-bin', 'mysqldump-bin',
        'charset', 'backup', 'exclude', 'php-bin', 'url', 'curl-bin', 'http-delay',
        'suffix', 'new-host', 'action', 'da-url', 'da-user', 'da-pass', 'da-extra', 'da-cmd', 'da-fields', 'da-preset', 'da-instance', 'da-comment'];
    const MULTI = ['site', 'exclude'];

    public static function parse(array $argv)
    {
        for ($i = 1; $i < count($argv); $i++) {
            $a = $argv[$i];
            if ($a === '--') { self::$args = array_merge(self::$args, array_slice($argv, $i + 1)); break; }
            if (substr($a, 0, 2) === '--' && strlen($a) > 2) {
                $name = substr($a, 2);
                $val = true;
                if (($p = strpos($name, '=')) !== false) {
                    $val = substr($name, $p + 1);
                    $name = substr($name, 0, $p);
                } elseif (in_array($name, self::WITH_VALUE, true) && isset($argv[$i + 1]) && substr($argv[$i + 1], 0, 2) !== '--') {
                    $val = $argv[++$i];
                }
                if (in_array($name, self::MULTI, true)) {
                    if (!isset(self::$opts[$name])) self::$opts[$name] = [];
                    self::$opts[$name][] = $val;
                } else {
                    self::$opts[$name] = $val;
                }
            } elseif (self::$cmd === null) {
                self::$cmd = $a;
            } else {
                self::$args[] = $a;
            }
        }
    }
    public static function has($n) { return array_key_exists($n, self::$opts); }
    public static function get($n, $d = null) { return self::$opts[$n] ?? $d; }
    /** lista wartosci (opcja powtarzalna lub rozdzielona przecinkami) */
    public static function listOf($n)
    {
        $v = self::$opts[$n] ?? [];
        $v = is_array($v) ? $v : [$v];
        $out = [];
        foreach ($v as $x) foreach (explode(',', (string)$x) as $p) if (trim($p) !== '') $out[] = trim($p);
        return $out;
    }
}

final class Log
{
    public static $file = null;
    public static $color = true;
    public static $verbose = false;
    public static $counts = ['WARN' => 0, 'ERR' => 0];
    private static $colors = ['OK' => "\033[32m", 'WARN' => "\033[33m", 'ERR' => "\033[31;1m", 'DEBUG' => "\033[90m", 'STEP' => "\033[36;1m", 'INFO' => ''];

    public static function w($lvl, $msg)
    {
        $ts = date('Y-m-d H:i:s');
        if (isset(self::$counts[$lvl])) self::$counts[$lvl]++;
        self::append("$ts [$lvl] $msg");
        if ($lvl === 'DEBUG' && !self::$verbose) return;
        $pre = (self::$color && self::$colors[$lvl] !== '') ? self::$colors[$lvl] : '';
        $post = $pre !== '' ? "\033[0m" : '';
        fwrite(STDOUT, $pre . str_pad("[$lvl]", 7) . $post . ' ' . $msg . "\n");
    }
    public static function append($line) { if (self::$file) @file_put_contents(self::$file, $line . "\n", FILE_APPEND | LOCK_EX); }
    public static function info($m)  { self::w('INFO', $m); }
    public static function ok($m)    { self::w('OK', $m); }
    public static function warn($m)  { self::w('WARN', $m); }
    public static function err($m)   { self::w('ERR', $m); }
    public static function debug($m) { self::w('DEBUG', $m); }
    public static function step($m)  { self::w('STEP', $m); }
    public static function raw($m)   { fwrite(STDOUT, $m . "\n"); }
}

class DbmigError extends RuntimeException {}

function fail($msg, $code = 1) { Log::err($msg); exit($code); }

function mask($s)
{
    if (Cli::has('show-secrets')) return (string)$s;
    $s = (string)$s;
    if ($s === '') return '';
    if (strlen($s) <= 3) return '***';
    return substr($s, 0, 2) . str_repeat('*', max(3, strlen($s) - 2));
}

function human_bytes($b)
{
    $b = (float)$b;
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) { if ($b < 1024 || $u === 'TB') return round($b, $u === 'B' ? 0 : 1) . ' ' . $u; $b /= 1024; }
    return '';
}

function print_table(array $headers, array $rows)
{
    $w = [];
    foreach ($headers as $i => $h) $w[$i] = mb_strlen((string)$h);
    foreach ($rows as $r) foreach ($r as $i => $c) $w[$i] = max($w[$i] ?? 0, mb_strlen((string)$c));
    $line = function ($r) use ($w) {
        $cells = [];
        foreach ($w as $i => $width) {
            $c = (string)($r[$i] ?? '');
            $cells[] = $c . str_repeat(' ', max(0, $width - mb_strlen($c)));
        }
        return '  ' . rtrim(implode('  ', $cells));
    };
    Log::raw($line($headers));
    Log::raw('  ' . implode('  ', array_map(function ($x) { return str_repeat('-', $x); }, $w)));
    foreach ($rows as $r) Log::raw($line($r));
}

function confirm($prompt, $expected = 'tak')
{
    if (Cli::has('yes')) return true;
    if (function_exists('posix_isatty') && !posix_isatty(STDIN)) {
        Log::err("Brak terminala (STDIN nie jest TTY) - dodaj --yes aby potwierdzic automatycznie.");
        return false;
    }
    fwrite(STDOUT, $prompt . " [wpisz: $expected] ");
    $in = trim((string)fgets(STDIN));
    return $in === $expected;
}

function now_ts() { return date('Ymd-His'); }

function run_proc(array $cmd, $stdinFile = null, $stdoutFile = null, $stderrFile = null, $env = null)
{
    $cmdline = implode(' ', array_map('escapeshellarg', $cmd));
    $desc = [
        0 => $stdinFile ? ['file', $stdinFile, 'r'] : ['file', '/dev/null', 'r'],
        1 => $stdoutFile ? ['file', $stdoutFile, 'w'] : ['pipe', 'w'],
        2 => $stderrFile ? ['file', $stderrFile, 'w'] : ['pipe', 'w'],
    ];
    $p = proc_open($cmdline, $desc, $pipes, null, $env);
    if (!is_resource($p)) throw new DbmigError("Nie moge uruchomic: " . $cmd[0]);
    $out = ''; $err = '';
    if (!$stdoutFile) { $out = stream_get_contents($pipes[1]); fclose($pipes[1]); }
    if (!$stderrFile) { $err = stream_get_contents($pipes[2]); fclose($pipes[2]); }
    $code = proc_close($p);
    return ['code' => $code, 'out' => $out, 'err' => $err];
}

function which($bin)
{
    if ($bin === '' || $bin === null) return null;
    if (strpos($bin, '/') !== false) return is_executable($bin) ? $bin : null;
    foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $d) {
        if ($d === '') continue;
        $c = rtrim($d, '/') . '/' . $bin;
        if (is_executable($c)) return $c;
    }
    return null;
}

function php_binary()
{
    $b = Cli::get('php-bin');
    if ($b) return $b;
    if (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY)) return PHP_BINARY;
    return which('php') ?: 'php';
}

// =====================================================================
//  Katalog roboczy i stan
// =====================================================================

final class Work
{
    public static $dir;

    public static function init()
    {
        $dir = Cli::get('workdir') ?: (getenv('DBMIG_WORKDIR') ?: dirname(__FILE__) . '/dbmig-work');
        $dir = rtrim($dir, '/');
        if (preg_match('#(^|/)public_html(/|$)#', $dir)) {
            fail("Katalog roboczy ($dir) nie moze lezec w public_html - hasla i zrzuty bylyby dostepne z WWW.");
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) fail("Nie moge utworzyc katalogu roboczego: $dir");
        @chmod($dir, 0700);
        foreach (['dumps', 'backups', 'logs'] as $s) if (!is_dir("$dir/$s")) @mkdir("$dir/$s", 0700, true);
        self::$dir = realpath($dir);
        Log::$file = self::$dir . '/logs/dbmig.log';
    }
    public static function p($rel) { return self::$dir . '/' . $rel; }
    public static function mappingFile() { return Cli::get('mapping') ?: self::p('mapping.conf'); }
}

final class State
{
    public static $d = ['dbs' => [], 'rows' => []];
    private static $loaded = false;

    public static function load()
    {
        if (self::$loaded) return;
        self::$loaded = true;
        $f = Work::p('state.json');
        if (is_file($f)) {
            $j = json_decode((string)file_get_contents($f), true);
            if (is_array($j)) self::$d = array_merge(['dbs' => [], 'rows' => []], $j);
        }
    }
    public static function save()
    {
        $f = Work::p('state.json');
        file_put_contents($f, json_encode(self::$d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @chmod($f, 0600);
    }
    public static function db($key) { self::load(); return self::$d['dbs'][$key] ?? []; }
    public static function setDb($key, array $patch) { self::load(); self::$d['dbs'][$key] = array_merge(self::$d['dbs'][$key] ?? [], $patch); self::save(); }
    public static function row($id) { self::load(); return self::$d['rows'][$id] ?? []; }
    public static function setRow($id, array $patch) { self::load(); self::$d['rows'][$id] = array_merge(self::$d['rows'][$id] ?? [], $patch); self::save(); }
    public static function unsetRow($id) { self::load(); unset(self::$d['rows'][$id]); self::save(); }
    public static function unsetDb($key) { self::load(); unset(self::$d['dbs'][$key]); self::save(); }
}

// =====================================================================
//  Parsowanie plikow PHP na poziomie tokenow (bez wykonywania kodu)
// =====================================================================

final class PhpFile
{
    public $path;
    public $src;
    /** @var array lista znaczacych tokenow: t=typ, s=tekst, o=offset bajtowy, l=dlugosc, n=linia */
    public $tok = [];
    private static $cache = [];

    public static function open($path)
    {
        $rp = realpath($path) ?: $path;
        if (!isset(self::$cache[$rp])) self::$cache[$rp] = new PhpFile($rp);
        return self::$cache[$rp];
    }
    public static function forget($path) { $rp = realpath($path) ?: $path; unset(self::$cache[$rp]); }

    public function __construct($path)
    {
        $this->path = $path;
        $this->src = (string)@file_get_contents($path);
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG, T_INLINE_HTML];
        $off = 0;
        foreach (@token_get_all($this->src) as $t) {
            if (is_array($t)) { $type = $t[0]; $text = $t[1]; $line = $t[2]; }
            else { $type = -1; $text = $t; $line = 0; }
            $len = strlen($text);
            if (!in_array($type, $skip, true)) {
                $this->tok[] = ['t' => $type, 's' => $text, 'o' => $off, 'l' => $len, 'n' => $line];
            }
            $off += $len;
        }
    }

    public function count() { return count($this->tok); }
    public function is($i, $type, $text = null)
    {
        if (!isset($this->tok[$i])) return false;
        $t = $this->tok[$i];
        if ($type !== null && $t['t'] !== $type) return false;
        if ($text !== null && strcasecmp($t['s'], $text) !== 0) return false;
        return true;
    }
    public function isPunct($i, $ch) { return isset($this->tok[$i]) && $this->tok[$i]['t'] === -1 && $this->tok[$i]['s'] === $ch; }
    public function isStr($i) { return isset($this->tok[$i]) && $this->tok[$i]['t'] === T_CONSTANT_ENCAPSED_STRING; }
    public function strVal($i) { return self::decodeLiteral($this->tok[$i]['s']); }
    public function text($i) { return isset($this->tok[$i]) ? $this->tok[$i]['s'] : null; }

    /** czy token otwiera nawias */
    public function opens($i)
    {
        if (!isset($this->tok[$i])) return false;
        $t = $this->tok[$i];
        if ($t['t'] === -1) return in_array($t['s'], ['(', '[', '{'], true);
        if ($t['t'] === T_CURLY_OPEN || $t['t'] === T_DOLLAR_OPEN_CURLY_BRACES) return true;
        if (defined('T_ATTRIBUTE') && $t['t'] === T_ATTRIBUTE) return true;
        return false;
    }
    public function closes($i)
    {
        return isset($this->tok[$i]) && $this->tok[$i]['t'] === -1 && in_array($this->tok[$i]['s'], [')', ']', '}'], true);
    }
    /** indeks tokenu zamykajacego nawias otwarty w $i (lub null) */
    public function matchClose($i)
    {
        if (!$this->opens($i)) return null;
        $d = 0;
        for ($j = $i, $n = count($this->tok); $j < $n; $j++) {
            if ($this->opens($j)) $d++;
            elseif ($this->closes($j)) { $d--; if ($d === 0) return $j; }
        }
        return null;
    }
    /** indeks ';' konczacego instrukcje zaczynajaca sie w $i (na glebokosci 0) */
    public function stmtEnd($i)
    {
        $d = 0;
        for ($j = $i, $n = count($this->tok); $j < $n; $j++) {
            if ($this->opens($j)) $d++;
            elseif ($this->closes($j)) $d--;
            elseif ($d <= 0 && $this->isPunct($j, ';')) return $j;
        }
        return count($this->tok) - 1;
    }
    /** dzieli zakres (a..b) na argumenty po przecinkach na glebokosci 0; zwraca [[start,end],...] */
    public function splitArgs($a, $b)
    {
        $args = []; $d = 0; $s = $a;
        for ($j = $a; $j <= $b; $j++) {
            if ($this->opens($j)) $d++;
            elseif ($this->closes($j)) $d--;
            elseif ($d === 0 && $this->isPunct($j, ',')) { $args[] = [$s, $j - 1]; $s = $j + 1; }
        }
        if ($s <= $b) $args[] = [$s, $b];
        return $args;
    }

    /** wartosc literalu T_CONSTANT_ENCAPSED_STRING -> string */
    public static function decodeLiteral($lit)
    {
        $q = $lit[0];
        $inner = substr($lit, 1, -1);
        if ($q === "'") {
            return preg_replace_callback('/\\\\([\\\\\'])/', function ($m) { return $m[1]; }, $inner);
        }
        // double quoted (bez interpolacji - to gwarantuje typ tokenu)
        return preg_replace_callback('/\\\\(n|t|r|v|e|f|\\\\|\\$|"|[0-7]{1,3}|x[0-9A-Fa-f]{1,2}|u\{[0-9A-Fa-f]+\})/',
            function ($m) {
                $e = $m[1];
                switch ($e) {
                    case 'n': return "\n"; case 't': return "\t"; case 'r': return "\r";
                    case 'v': return "\v"; case 'e': return "\e"; case 'f': return "\f";
                    case '\\': return '\\'; case '$': return '$'; case '"': return '"';
                }
                if ($e[0] === 'x') return chr(hexdec(substr($e, 1)));
                if ($e[0] === 'u') return html_entity_decode('&#x' . substr($e, 2, -1) . ';', ENT_QUOTES, 'UTF-8');
                return chr(octdec($e));
            }, $inner);
    }
    /** string -> literal PHP w pojedynczych cudzyslowach */
    public static function encodeLiteral($v)
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$v) . "'";
    }
}

/** info o wartosci: i=indeks tokenu wartosci (lub null), v=wartosc, dyn=czy nie-literal */
function tokinfo(PhpFile $f, $valIdx)
{
    if ($valIdx !== null && $f->isStr($valIdx)) return ['i' => $valIdx, 'v' => $f->strVal($valIdx), 'dyn' => false];
    return ['i' => $valIdx, 'v' => null, 'dyn' => true];
}

/** define('KEY', 'val') -> [KEY => tokinfo] (pierwsze wystapienie) */
function php_find_defines(PhpFile $f)
{
    $r = [];
    for ($i = 0, $n = $f->count(); $i < $n; $i++) {
        if ($f->is($i, T_STRING, 'define') && $f->isPunct($i + 1, '(') && $f->isStr($i + 2) && $f->isPunct($i + 3, ',')) {
            if ($f->is($i - 1, T_OBJECT_OPERATOR) || $f->is($i - 1, T_DOUBLE_COLON) || $f->is($i - 1, T_FUNCTION)) continue;
            $key = $f->strVal($i + 2);
            if (isset($r[$key])) continue;
            if ($f->isStr($i + 4) && $f->isPunct($i + 5, ')')) $r[$key] = tokinfo($f, $i + 4);
            else $r[$key] = tokinfo($f, null);
        }
    }
    return $r;
}

/** $var = 'val';  (takze public/var/private $x = 'val';) -> ['$var' => tokinfo] */
function php_find_assignments(PhpFile $f)
{
    $r = [];
    for ($i = 0, $n = $f->count(); $i < $n; $i++) {
        if ($f->is($i, T_VARIABLE) && $f->isPunct($i + 1, '=')) {
            if ($f->is($i - 1, T_OBJECT_OPERATOR) || $f->is($i - 1, T_DOUBLE_COLON)) continue;
            $name = $f->text($i);
            if (isset($r[$name])) continue;
            if ($f->isStr($i + 2) && $f->isPunct($i + 3, ';')) $r[$name] = tokinfo($f, $i + 2);
            else $r[$name] = tokinfo($f, null);
        }
    }
    return $r;
}

/** $CFG->klucz = 'val'; -> [klucz => tokinfo] */
function php_find_object_props(PhpFile $f, $var = '$CFG')
{
    $r = [];
    for ($i = 0, $n = $f->count(); $i < $n; $i++) {
        if ($f->is($i, T_VARIABLE, $var) && $f->is($i + 1, T_OBJECT_OPERATOR) && $f->is($i + 2, T_STRING) && $f->isPunct($i + 3, '=')) {
            $key = $f->text($i + 2);
            if (isset($r[$key])) continue;
            if ($f->isStr($i + 4) && $f->isPunct($i + 5, ';')) $r[$key] = tokinfo($f, $i + 4);
            else $r[$key] = tokinfo($f, null);
        }
    }
    return $r;
}

/** 'klucz' => 'val' w zakresie tokenow -> lista [['k'=>, 'ki'=>, 'i'=>, 'v'=>, 'dyn'=>]] (wszystkie wystapienia) */
function php_find_array_keys(PhpFile $f, $from = 0, $to = null)
{
    $r = [];
    $to = $to === null ? $f->count() - 1 : $to;
    for ($i = $from; $i <= $to; $i++) {
        if ($f->isStr($i) && $f->is($i + 1, T_DOUBLE_ARROW)) {
            $key = $f->strVal($i);
            $info = ($f->isStr($i + 2) && ($f->isPunct($i + 3, ',') || $f->closes($i + 3))) ? tokinfo($f, $i + 2) : tokinfo($f, null);
            $info['k'] = $key; $info['ki'] = $i;
            $info['arr'] = $f->opens($i + 2) || $f->is($i + 2, T_ARRAY);
            $r[] = $info;
        }
    }
    return $r;
}

/** pierwsze wystapienie klucza z listy z php_find_array_keys */
function arr_first(array $list, $key)
{
    foreach ($list as $e) if ($e['k'] === $key) return $e;
    return null;
}

/** zakres tokenow wartosci tablicowej po 'klucz' => [ ... ] lub array( ... ); zwraca [from,to] lub null */
function php_array_value_range(PhpFile $f, $keyIdx)
{
    $j = $keyIdx + 2;
    if ($f->is($j, T_ARRAY)) $j++;
    if (!$f->opens($j)) return null;
    $c = $f->matchClose($j);
    return $c === null ? null : [$j, $c];
}

/**
 * wywolania funkcji/konstruktorow: nazwy case-insensitive, zwraca
 * [['name'=>, 'i'=>, 'args'=>[[a,b],...]]]
 */
function php_find_calls(PhpFile $f, array $names)
{
    $r = [];
    $names = array_map('strtolower', $names);
    for ($i = 0, $n = $f->count(); $i < $n; $i++) {
        $t = $f->tok[$i];
        $nm = null;
        if ($t['t'] === T_STRING) $nm = strtolower($t['s']);
        elseif (defined('T_NAME_FULLY_QUALIFIED') && $t['t'] === T_NAME_FULLY_QUALIFIED) $nm = strtolower(ltrim($t['s'], '\\'));
        elseif (defined('T_NAME_QUALIFIED') && $t['t'] === T_NAME_QUALIFIED) $nm = strtolower($t['s']);
        if ($nm === null || !in_array($nm, $names, true)) continue;
        if (!$f->isPunct($i + 1, '(')) continue;
        if ($f->is($i - 1, T_OBJECT_OPERATOR) || $f->is($i - 1, T_DOUBLE_COLON) || $f->is($i - 1, T_FUNCTION)) continue;
        if (defined('T_NULLSAFE_OBJECT_OPERATOR') && $f->is($i - 1, T_NULLSAFE_OBJECT_OPERATOR)) continue;
        $close = $f->matchClose($i + 1);
        if ($close === null) continue;
        $args = ($close > $i + 2) ? $f->splitArgs($i + 2, $close - 1) : [];
        $r[] = ['name' => $nm, 'i' => $i, 'args' => $args];
    }
    return $r;
}

/** sciezki plikow dolaczanych przez include/require z literalnych sciezek (1 poziom) */
function php_find_includes(PhpFile $f)
{
    $r = [];
    $dir = dirname($f->path);
    for ($i = 0, $n = $f->count(); $i < $n; $i++) {
        if (!in_array($f->tok[$i]['t'], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) continue;
        $j = $i + 1;
        if ($f->isPunct($j, '(')) $j++;
        $path = null;
        if ($f->isStr($j)) {
            $path = $f->strVal($j);
        } elseif ($f->is($j, T_DIR) && $f->isPunct($j + 1, '.') && $f->isStr($j + 2)) {
            $path = $dir . $f->strVal($j + 2);
        } elseif ($f->is($j, T_STRING, 'dirname') && $f->isPunct($j + 1, '(') && $f->is($j + 2, T_FILE) && $f->isPunct($j + 3, ')') && $f->isPunct($j + 4, '.') && $f->isStr($j + 5)) {
            $path = $dir . $f->strVal($j + 5);
        }
        if ($path === null) continue;
        if ($path[0] !== '/') $path = $dir . '/' . $path;
        $rp = realpath($path);
        if ($rp && is_file($rp) && $rp !== realpath($f->path)) $r[] = $rp;
    }
    return array_values(array_unique($r));
}

/**
 * rozwiazuje argument wywolania (zakres tokenow a..b) do literalu:
 * bezposredni literal, zmienna przypisana literalem (w tym pliku lub dolaczonym),
 * stala z define(). Zwraca ['f'=>PhpFile, 'i'=>idx, 'v'=>, 'dyn'=>bool].
 */
function php_resolve_arg(PhpFile $f, $a, $b, array &$ctx)
{
    if ($a === $b) {
        if ($f->isStr($a)) return ['f' => $f, 'i' => $a, 'v' => $f->strVal($a), 'dyn' => false];
        if ($f->is($a, T_LNUMBER)) return ['f' => $f, 'i' => $a, 'v' => $f->text($a), 'dyn' => false, 'int' => true];
        if ($f->is($a, T_VARIABLE) || $f->is($a, T_STRING)) {
            $name = $f->text($a);
            $kind = $f->is($a, T_VARIABLE) ? 'vars' : 'consts';
            foreach ($ctx['files'] as $pf) {
                $map = $kind === 'vars' ? $ctx['vars'][$pf->path] : $ctx['consts'][$pf->path];
                if (isset($map[$name])) {
                    $e = $map[$name];
                    if ($e['dyn']) return ['f' => $pf, 'i' => null, 'v' => null, 'dyn' => true];
                    return ['f' => $pf, 'i' => $e['i'], 'v' => $e['v'], 'dyn' => false];
                }
            }
        }
    }
    return ['f' => $f, 'i' => null, 'v' => null, 'dyn' => true];
}

/** kontekst rozwiazywania: ten plik + pliki dolaczane (1 poziom) */
function php_resolve_context(PhpFile $f)
{
    $files = [$f];
    foreach (php_find_includes($f) as $inc) {
        if (filesize($inc) > 1024 * 1024) continue;
        $files[] = PhpFile::open($inc);
    }
    $ctx = ['files' => $files, 'vars' => [], 'consts' => []];
    foreach ($files as $pf) {
        $ctx['vars'][$pf->path] = php_find_assignments($pf);
        $ctx['consts'][$pf->path] = php_find_defines($pf);
    }
    return $ctx;
}

// =====================================================================
//  "Sloty" = miejsca w plikach konfiguracyjnych, ktore trzymaja dane bazy
//  slot: file, type (host|hostport|port|name|user|pass|dsn|url), enc (php|env|yml),
//        o (offset), l (dlugosc), v (wartosc), line, label, dyn (nie-literal)
// =====================================================================

function slot_php(PhpFile $f, array $info, $type, $label)
{
    if (!empty($info['dyn']) || $info['i'] === null) {
        return ['file' => $f->path, 'type' => $type, 'enc' => 'php', 'dyn' => true, 'v' => null, 'o' => null, 'l' => 0,
            'line' => ($info['i'] !== null ? $f->tok[$info['i']]['n'] : 0), 'label' => $label];
    }
    $t = $f->tok[$info['i']];
    return ['file' => $f->path, 'type' => $type, 'enc' => 'php', 'dyn' => false, 'v' => $info['v'],
        'o' => $t['o'], 'l' => $t['l'], 'line' => $t['n'], 'label' => $label];
}

function parse_result($cms, $file, array $slots, array $notes = [], array $extra = [], $root = null)
{
    $files = [];
    foreach ($slots as $s) $files[$s['file']] = true;
    $files[$file] = true;
    return ['cms' => $cms, 'file' => $file, 'root' => $root ?: dirname($file), 'slots' => $slots,
        'notes' => $notes, 'extra' => $extra, 'files' => array_keys($files)];
}

function parse_defines_cms($path, $cms, array $map, array $required, $prefixKey = null, $root = null)
{
    $f = PhpFile::open($path);
    $d = php_find_defines($f);
    foreach ($required as $k) if (!isset($d[$k])) return null;
    $slots = []; $notes = [];
    foreach ($map as $k => $type) {
        if (!isset($d[$k])) { if ($type !== 'port') $notes[] = "brak define('$k')"; continue; }
        $slots[] = slot_php($f, $d[$k], $type, $k);
    }
    $extra = [];
    if ($prefixKey && isset($d[$prefixKey]) && !$d[$prefixKey]['dyn']) $extra['prefix'] = $d[$prefixKey]['v'];
    return parse_result($cms, $path, $slots, $notes, $extra, $root);
}

function parse_wordpress($path)
{
    $r = parse_defines_cms($path, 'wordpress',
        ['DB_HOST' => 'hostport', 'DB_NAME' => 'name', 'DB_USER' => 'user', 'DB_PASSWORD' => 'pass'], ['DB_NAME']);
    if ($r) {
        $as = php_find_assignments(PhpFile::open($path));
        if (isset($as['$table_prefix']) && !$as['$table_prefix']['dyn']) $r['extra']['prefix'] = $as['$table_prefix']['v'];
        // wp-config.php moze lezec katalog wyzej niz instalacja
        $d = dirname($path);
        if (!is_file("$d/wp-settings.php") && is_dir("$d/public_html") && is_file("$d/public_html/wp-settings.php")) $r['root'] = "$d/public_html";
    }
    return $r;
}

function parse_opencart($path)
{
    $root = (basename(dirname($path)) === 'admin') ? dirname(dirname($path)) : dirname($path);
    return parse_defines_cms($path, 'opencart',
        ['DB_HOSTNAME' => 'host', 'DB_PORT' => 'port', 'DB_DATABASE' => 'name', 'DB_USERNAME' => 'user', 'DB_PASSWORD' => 'pass'],
        ['DB_HOSTNAME', 'DB_DATABASE'], 'DB_PREFIX', $root);
}

function parse_prestashop16($path)
{
    return parse_defines_cms($path, 'prestashop16',
        ['_DB_SERVER_' => 'hostport', '_DB_NAME_' => 'name', '_DB_USER_' => 'user', '_DB_PASSWD_' => 'pass'],
        ['_DB_SERVER_', '_DB_NAME_'], '_DB_PREFIX_', dirname(dirname($path)));
}

function parse_joomla($path)
{
    $f = PhpFile::open($path);
    if (stripos($f->src, 'JConfig') === false && stripos($f->src, '$dbprefix') === false) return null;
    $as = php_find_assignments($f);
    if (!isset($as['$db']) || !isset($as['$host'])) return null;
    $slots = []; $notes = [];
    foreach (['$host' => 'hostport', '$db' => 'name', '$user' => 'user', '$password' => 'pass'] as $k => $t) {
        if (!isset($as[$k])) { $notes[] = "brak $k"; continue; }
        $slots[] = slot_php($f, $as[$k], $t, $k);
    }
    $extra = [];
    if (isset($as['$dbprefix']) && !$as['$dbprefix']['dyn']) $extra['prefix'] = $as['$dbprefix']['v'];
    if (isset($as['$dbtype']) && !$as['$dbtype']['dyn'] && !in_array(strtolower($as['$dbtype']['v']), ['mysql', 'mysqli', 'pdomysql'], true)) {
        $notes[] = 'dbtype=' . $as['$dbtype']['v'] . ' (nie MySQL)';
    }
    return parse_result('joomla', $path, $slots, $notes, $extra);
}

function parse_moodle($path)
{
    $f = PhpFile::open($path);
    $p = php_find_object_props($f, '$CFG');
    if (!isset($p['dbhost']) || !isset($p['dbname'])) return null;
    $slots = []; $notes = [];
    foreach (['dbhost' => 'host', 'dbname' => 'name', 'dbuser' => 'user', 'dbpass' => 'pass'] as $k => $t) {
        if (!isset($p[$k])) { $notes[] = "brak \$CFG->$k"; continue; }
        $slots[] = slot_php($f, $p[$k], $t, '$CFG->' . $k);
    }
    $port = arr_first(php_find_array_keys($f), 'dbport');
    if ($port) $slots[] = slot_php($f, $port, 'port', 'dboptions[dbport]');
    $extra = [];
    if (isset($p['prefix']) && !$p['prefix']['dyn']) $extra['prefix'] = $p['prefix']['v'];
    if (isset($p['dbtype']) && !$p['dbtype']['dyn'] && !in_array(strtolower($p['dbtype']['v']), ['mysqli', 'mariadb', 'auroramysql'], true)) {
        $notes[] = 'dbtype=' . $p['dbtype']['v'] . ' (nie MySQL)';
    }
    return parse_result('moodle', $path, $slots, $notes, $extra);
}

function parse_drupal($path)
{
    $f = PhpFile::open($path);
    // pierwsza instrukcja $databases..., ktora faktycznie zawiera klucz 'database'
    $start = null; $end = null; $others = 0;
    for ($i = 0, $n = $f->count(); $i < $n; $i++) {
        if (!$f->is($i, T_VARIABLE, '$databases')) continue;
        $e = $f->stmtEnd($i);
        $k = php_find_array_keys($f, $i, $e);
        if (arr_first($k, 'database')) {
            if ($start === null) { $start = $i; $end = $e; } else $others++;
        }
        $i = $e;
    }
    if ($start === null) return null;
    $keys = php_find_array_keys($f, $start, $end);
    $slots = []; $notes = []; $extra = [];
    foreach (['host' => 'host', 'port' => 'port', 'database' => 'name', 'username' => 'user', 'password' => 'pass'] as $k => $t) {
        $e = arr_first($keys, $k);
        if (!$e) { if ($t !== 'port') $notes[] = "brak klucza '$k' w \$databases"; continue; }
        $slots[] = slot_php($f, $e, $t, "'$k'");
    }
    $drv = arr_first($keys, 'driver');
    if ($drv && !$drv['dyn'] && strtolower($drv['v']) !== 'mysql') $notes[] = "driver=" . $drv['v'] . " (nie MySQL)";
    $pre = arr_first($keys, 'prefix');
    if ($pre && !$pre['dyn']) $extra['prefix'] = $pre['v'];
    if ($others) $notes[] = "wiecej niz jedna definicja \$databases ($others dodatkowych) - obslugiwana tylko pierwsza";
    $root = dirname(dirname(dirname($path)));
    $r = parse_result('drupal', $path, $slots, $notes, $extra, $root);
    $r['port_insert'] = !arr_first($keys, 'port');
    return $r;
}

function parse_prestashop17($path)
{
    $f = PhpFile::open($path);
    $keys = php_find_array_keys($f);
    if (!arr_first($keys, 'database_host') || !arr_first($keys, 'database_name')) return null;
    $slots = []; $notes = []; $extra = [];
    foreach (['database_host' => 'host', 'database_port' => 'port', 'database_name' => 'name', 'database_user' => 'user', 'database_password' => 'pass'] as $k => $t) {
        $e = arr_first($keys, $k);
        if (!$e) { if ($t !== 'port') $notes[] = "brak klucza '$k'"; continue; }
        $slots[] = slot_php($f, $e, $t, "'$k'");
    }
    $pre = arr_first($keys, 'database_prefix');
    if ($pre && !$pre['dyn']) $extra['prefix'] = $pre['v'];
    return parse_result('prestashop', $path, $slots, $notes, $extra, dirname(dirname(dirname($path))));
}

function parse_magento2($path)
{
    $f = PhpFile::open($path);
    $all = php_find_array_keys($f);
    $db = arr_first($all, 'db');
    if (!$db || empty($db['arr'])) return null;
    $range = php_array_value_range($f, $db['ki']);
    if (!$range) return null;
    $keys = php_find_array_keys($f, $range[0], $range[1]);
    $slots = []; $notes = []; $extra = [];
    $map = ['host' => 'hostport', 'dbname' => 'name', 'username' => 'user', 'password' => 'pass'];
    foreach ($keys as $e) {
        if (isset($map[$e['k']])) $slots[] = slot_php($f, $e, $map[$e['k']], "db/'" . $e['k'] . "'");
        elseif ($e['k'] === 'table_prefix' && !$e['dyn'] && !isset($extra['prefix'])) $extra['prefix'] = $e['v'];
    }
    if (!$slots) return null;
    return parse_result('magento2', $path, $slots, $notes, $extra, dirname(dirname(dirname($path))));
}

// ---------- pliki .env / .yml (liniowe) ----------

/** parsuje wartosc .env od poczatku $rest; zwraca [wartosc, dlugosc_surowa, dynamiczna] */
function env_parse_value($rest)
{
    if ($rest === '') return ['', 0, false];
    $q = $rest[0];
    if ($q === '"') {
        $v = ''; $i = 1; $n = strlen($rest);
        while ($i < $n) {
            $c = $rest[$i];
            if ($c === '\\' && $i + 1 < $n) {
                $d = $rest[$i + 1];
                $v .= ($d === 'n') ? "\n" : (($d === 't') ? "\t" : $d);
                $i += 2; continue;
            }
            if ($c === '"') return [$v, $i + 1, strpos($v, '${') !== false];
            $v .= $c; $i++;
        }
        return [$v, $n, false];
    }
    if ($q === "'") {
        $p = strpos($rest, "'", 1);
        if ($p === false) return [substr($rest, 1), strlen($rest), false];
        return [substr($rest, 1, $p - 1), $p + 1, false];
    }
    $v = $rest;
    if (preg_match('/^(.*?)(\s+#.*)$/', $v, $m)) $v = $m[1];
    $v = rtrim($v);
    return [$v, strlen($v), strpos($v, '${') !== false || strpos($v, '$') === 0];
}

function env_encode($v)
{
    $v = (string)$v;
    if ($v === '') return '';
    if (preg_match('#^[A-Za-z0-9_./@:+,=-]+$#', $v)) return $v;
    if (strpos($v, "'") === false && strpos($v, "\n") === false) return "'" . $v . "'";
    return '"' . str_replace(['\\', '"', "\n", '$'], ['\\\\', '\\"', '\\n', '\\$'], $v) . '"';
}

/** mapa KEY => ['o','l','v','line','dyn'] */
function env_read_vars($path)
{
    $src = (string)file_get_contents($path);
    $vars = []; $off = 0; $n = 0; $dups = [];
    foreach (preg_split('/(?<=\n)/', $src) as $line) {
        $n++;
        $body = rtrim($line, "\r\n");
        if (preg_match('/^(\s*(?:export\s+)?)([A-Za-z_][A-Za-z0-9_.]*)(\s*=\s*)(.*)$/', $body, $m)) {
            $key = $m[2];
            $vs = $off + strlen($m[1]) + strlen($key) + strlen($m[3]);
            list($val, $len, $dyn) = env_parse_value($m[4]);
            if (isset($vars[$key])) $dups[] = $key;
            else $vars[$key] = ['o' => $vs, 'l' => $len, 'v' => $val, 'line' => $n, 'dyn' => $dyn, 'eol' => $off + strlen($body)];
        }
        $off += strlen($line);
    }
    return [$vars, $dups];
}

function slot_env($path, $key, array $e, $type)
{
    return ['file' => $path, 'type' => $type, 'enc' => 'env', 'dyn' => !empty($e['dyn']), 'v' => $e['dyn'] ? null : $e['v'],
        'o' => $e['o'], 'l' => $e['l'], 'line' => $e['line'], 'label' => $key, 'eol' => $e['eol'] ?? null];
}

function parse_env($path)
{
    list($vars, $dups) = env_read_vars($path);
    $notes = [];
    if (isset($vars['DB_CONNECTION']) && !$vars['DB_CONNECTION']['dyn'] && !in_array(strtolower((string)$vars['DB_CONNECTION']['v']), ['mysql', 'mariadb'], true)) return null;
    $slots = []; $cms = 'env';
    if (isset($vars['DATABASE_URL'])) {
        $u = $vars['DATABASE_URL']['v'];
        if (preg_match('#^(mysql|mariadb|mysql2|pdo_mysql)://#i', (string)$u) || $vars['DATABASE_URL']['dyn']) {
            $slots[] = slot_env($path, 'DATABASE_URL', $vars['DATABASE_URL'], 'url');
            $cms = 'symfony';
        } else {
            $notes[] = 'DATABASE_URL nie jest mysql://';
        }
    }
    if (isset($vars['DB_DATABASE']) || isset($vars['DB_USERNAME'])) {   // Laravel
        $cms = 'laravel';
        foreach (['DB_HOST' => 'host', 'DB_PORT' => 'port', 'DB_DATABASE' => 'name', 'DB_USERNAME' => 'user', 'DB_PASSWORD' => 'pass'] as $k => $t) {
            if (isset($vars[$k])) $slots[] = slot_env($path, $k, $vars[$k], $t);
            elseif ($t !== 'port') $notes[] = "brak $k";
        }
    } elseif (isset($vars['DB_NAME']) && isset($vars['DB_USER'])) {      // Bedrock / WP .env
        $cms = 'wp-env';
        foreach (['DB_HOST' => 'hostport', 'DB_NAME' => 'name', 'DB_USER' => 'user', 'DB_PASSWORD' => 'pass'] as $k => $t) {
            if (isset($vars[$k])) $slots[] = slot_env($path, $k, $vars[$k], $t);
            else $notes[] = "brak $k";
        }
    }
    if (!$slots) return null;
    foreach (array_unique($dups) as $d) if (in_array($d, ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_NAME', 'DB_USER', 'DATABASE_URL'], true)) $notes[] = "zduplikowany klucz $d (uzyto pierwszego)";
    return parse_result($cms, $path, $slots, $notes);
}

function yml_decode($raw)
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '~' || strtolower($raw) === 'null') return ['', false];
    if ($raw[0] === "'") { $p = strrpos($raw, "'"); return [str_replace("''", "'", substr($raw, 1, max(0, $p - 1))), false]; }
    if ($raw[0] === '"') { $p = strrpos($raw, '"'); return [stripcslashes(substr($raw, 1, max(0, $p - 1))), false]; }
    if (preg_match('/^(.*?)\s+#.*$/', $raw, $m)) $raw = $m[1];
    return [$raw, strpos($raw, '%') === 0];
}
function yml_encode($v) { return "'" . str_replace("'", "''", (string)$v) . "'"; }

function parse_prestashop_yml($path)
{
    $src = (string)file_get_contents($path);
    $vars = []; $off = 0; $n = 0;
    foreach (preg_split('/(?<=\n)/', $src) as $line) {
        $n++;
        $body = rtrim($line, "\r\n");
        if (preg_match('/^(\s*)(database_host|database_port|database_name|database_user|database_password|database_prefix)(\s*:\s*)(.*)$/', $body, $m)) {
            $raw = rtrim($m[4]);
            list($val, $dyn) = yml_decode($raw);
            $vars[$m[2]] = ['o' => $off + strlen($m[1]) + strlen($m[2]) + strlen($m[3]), 'l' => strlen($raw), 'v' => $val, 'line' => $n, 'dyn' => $dyn];
        }
        $off += strlen($line);
    }
    if (!isset($vars['database_host']) || !isset($vars['database_name'])) return null;
    $slots = [];
    foreach (['database_host' => 'host', 'database_port' => 'port', 'database_name' => 'name', 'database_user' => 'user', 'database_password' => 'pass'] as $k => $t) {
        if (!isset($vars[$k])) continue;
        $s = slot_env($path, $k, $vars[$k], $t); $s['enc'] = 'yml';
        $slots[] = $s;
    }
    $extra = isset($vars['database_prefix']) ? ['prefix' => $vars['database_prefix']['v']] : [];
    return parse_result('prestashop-yml', $path, $slots, [], $extra, dirname(dirname(dirname($path))));
}

/** Symfony: .env.local.php z `composer dump-env prod` - tablica PHP, ma pierwszenstwo przed .env */
function parse_env_local_php($path)
{
    $f = PhpFile::open($path);
    $keys = php_find_array_keys($f);
    $slots = []; $notes = []; $cms = 'symfony-dumped';
    $url = arr_first($keys, 'DATABASE_URL');
    if ($url) {
        if (!$url['dyn'] && !preg_match('#^(mysql|mariadb|mysql2|pdo_mysql)://#i', (string)$url['v'])) return null;
        $slots[] = slot_php($f, $url, 'url', 'DATABASE_URL');
    }
    foreach (['DB_HOST' => 'host', 'DB_PORT' => 'port', 'DB_DATABASE' => 'name', 'DB_USERNAME' => 'user', 'DB_PASSWORD' => 'pass'] as $k => $t) {
        $e = arr_first($keys, $k);
        if ($e) $slots[] = slot_php($f, $e, $t, $k);
    }
    if (!$slots) return null;
    $notes[] = 'plik z composer dump-env - ma pierwszenstwo przed .env; po migracji mozesz tez uruchomic: composer dump-env prod';
    return parse_result($cms, $path, $slots, $notes);
}

// ---------- wlasne skrypty PHP (mysqli / PDO / mysql_connect) ----------

const CUSTOM_CALL_RE = '/\b(?:mysqli_connect|mysql_connect|mysql_pconnect)\s*\(|new\s+\\\\?(?:mysqli|PDO)\s*\(/i';

function slot_resolved(array $res, $type, $label)
{
    if ($res['dyn'] || $res['i'] === null) {
        return ['file' => $res['f']->path, 'type' => $type, 'enc' => 'php', 'dyn' => true, 'v' => null, 'o' => null, 'l' => 0, 'line' => 0, 'label' => $label];
    }
    $t = $res['f']->tok[$res['i']];
    return ['file' => $res['f']->path, 'type' => $type, 'enc' => 'php', 'dyn' => false, 'v' => $res['v'],
        'o' => $t['o'], 'l' => $t['l'], 'line' => $t['n'], 'label' => $label, 'int' => !empty($res['int'])];
}

function parse_custom($path)
{
    $f = PhpFile::open($path);
    if (!preg_match(CUSTOM_CALL_RE, $f->src)) return null;
    $calls = php_find_calls($f, ['mysqli_connect', 'mysqli', 'pdo', 'mysql_connect', 'mysql_pconnect', 'mysql_select_db']);
    if (!$calls) return null;
    $ctx = php_resolve_context($f);
    $slots = []; $notes = []; $found = null; $extraConns = 0; $portInsert = null;
    $selectDb = null;
    foreach ($calls as $c) {
        $isNew = $f->is($c['i'] - 1, T_NEW) || ($f->is($c['i'] - 1, T_NS_SEPARATOR) && $f->is($c['i'] - 2, T_NEW));
        if (($c['name'] === 'mysqli' || $c['name'] === 'pdo') && !$isNew) continue;
        if ($c['name'] === 'mysql_select_db') { if ($selectDb === null && $c['args']) $selectDb = $c['args'][0]; continue; }
        if ($found !== null) { $extraConns++; continue; }
        $found = $c['name'];
        $a = $c['args'];
        if ($c['name'] === 'mysqli_connect' || $c['name'] === 'mysqli') {
            $types = ['host', 'user', 'pass', 'name', 'port'];
            foreach ($types as $k => $t) {
                if (!isset($a[$k])) { if ($t !== 'port') $notes[] = "brak argumentu $t"; continue; }
                $slots[] = slot_resolved(php_resolve_arg($f, $a[$k][0], $a[$k][1], $ctx), $t, "$found() arg" . ($k + 1));
            }
            if (count($a) === 4) { $last = $f->tok[$a[3][1]]; $portInsert = ['file' => $f->path, 'o' => $last['o'] + $last['l'], 'line' => $last['n']]; }
        } elseif ($c['name'] === 'pdo') {
            $dsn = isset($a[0]) ? php_resolve_arg($f, $a[0][0], $a[0][1], $ctx) : null;
            if ($dsn && !$dsn['dyn'] && !preg_match('/^mysql:/i', (string)$dsn['v'])) { $found = null; $notes[] = 'PDO DSN nie jest mysql: - pomijam'; continue; }
            if ($dsn) $slots[] = slot_resolved($dsn, 'dsn', 'PDO DSN');
            if (isset($a[1])) $slots[] = slot_resolved(php_resolve_arg($f, $a[1][0], $a[1][1], $ctx), 'user', 'PDO user');
            if (isset($a[2])) $slots[] = slot_resolved(php_resolve_arg($f, $a[2][0], $a[2][1], $ctx), 'pass', 'PDO pass');
        } else { // mysql_connect
            foreach (['hostport', 'user', 'pass'] as $k => $t) {
                if (!isset($a[$k])) { $notes[] = "brak argumentu $t"; continue; }
                $slots[] = slot_resolved(php_resolve_arg($f, $a[$k][0], $a[$k][1], $ctx), $t, "$found() arg" . ($k + 1));
            }
        }
    }
    if ($found === null) return null;
    if (in_array($found, ['mysql_connect', 'mysql_pconnect'], true)) {
        if ($selectDb) $slots[] = slot_resolved(php_resolve_arg($f, $selectDb[0], $selectDb[1], $ctx), 'name', 'mysql_select_db()');
        else $notes[] = 'brak mysql_select_db() - nazwa bazy nieznana';
        $notes[] = 'UWAGA: uzywa funkcji mysql_* usunietych w PHP 7 - strona dziala tylko na PHP 5.x (sprawdz handler w .htaccess)';
    }
    if ($extraConns) $notes[] = "w pliku jest wiecej polaczen ($extraConns) - obsluzono tylko pierwsze";
    $r = parse_result('custom', $path, $slots, $notes);
    if ($portInsert) $r['port_insert_custom'] = $portInsert;
    return $r;
}

/** ponowne parsowanie pliku wg typu (do weryfikacji po zapisie) */
function parse_by_cms($cms, $path)
{
    PhpFile::forget($path);
    switch ($cms) {
        case 'wordpress': return parse_wordpress($path);
        case 'opencart': return parse_opencart($path);
        case 'prestashop16': return parse_prestashop16($path);
        case 'prestashop': return parse_prestashop17($path);
        case 'prestashop-yml': return parse_prestashop_yml($path);
        case 'joomla': return parse_joomla($path);
        case 'moodle': return parse_moodle($path);
        case 'drupal': return parse_drupal($path);
        case 'magento2': return parse_magento2($path);
        case 'laravel': case 'symfony': case 'wp-env': case 'env': return parse_env($path);
        case 'symfony-dumped': return parse_env_local_php($path);
        case 'custom': return parse_custom($path);
    }
    return null;
}

// ---------- pola logiczne z slotow ----------

function split_hostport($v)
{
    $v = (string)$v;
    if (preg_match('/^(.*):(\d+)$/', $v, $m)) return [$m[1], $m[2], null];
    if (preg_match('/^(.*):(\/.*)$/', $v, $m)) return [$m[1], '', $m[2]];
    return [$v, '', null];
}

function dsn_parse($dsn)
{
    $r = ['scheme' => '', 'host' => '', 'port' => '', 'name' => '', 'socket' => '', 'parts' => []];
    $p = strpos($dsn, ':');
    if ($p === false) return $r;
    $r['scheme'] = substr($dsn, 0, $p);
    foreach (explode(';', substr($dsn, $p + 1)) as $kv) {
        if (trim($kv) === '') continue;
        $e = explode('=', $kv, 2);
        $k = strtolower(trim($e[0])); $v = isset($e[1]) ? trim($e[1]) : '';
        $r['parts'][] = [$k, $v];
        if ($k === 'host') $r['host'] = $v; elseif ($k === 'port') $r['port'] = $v;
        elseif ($k === 'dbname') $r['name'] = $v; elseif ($k === 'unix_socket') $r['socket'] = $v;
    }
    return $r;
}

function dsn_compose($dsn, $host, $port, $name, $socket = '')
{
    $d = dsn_parse($dsn);
    $out = []; $hasHost = $hasPort = $hasName = $hasSock = false;
    foreach ($d['parts'] as $kv) {
        list($k, $v) = $kv;
        if ($k === 'host') { if ($socket !== '') continue; $v = $host; $hasHost = true; }
        elseif ($k === 'port') { if ($port === '' || $socket !== '') continue; $v = $port; $hasPort = true; }
        elseif ($k === 'dbname') { $v = $name; $hasName = true; }
        elseif ($k === 'unix_socket') { if ($socket === '') continue; $v = $socket; $hasSock = true; }
        $out[] = "$k=$v";
    }
    if ($socket !== '' && !$hasSock) array_unshift($out, "unix_socket=$socket");
    if ($socket === '' && !$hasHost && $host !== '') array_unshift($out, "host=$host");
    if ($socket === '' && !$hasPort && $port !== '') $out[] = "port=$port";
    if (!$hasName) $out[] = "dbname=$name";
    return $d['scheme'] . ':' . implode(';', $out);
}

function url_parse_db($url)
{
    $u = @parse_url((string)$url);
    if (!$u || empty($u['scheme']) || empty($u['host'])) return null;
    return ['scheme' => $u['scheme'], 'host' => $u['host'], 'port' => isset($u['port']) ? (string)$u['port'] : '',
        'name' => isset($u['path']) ? ltrim($u['path'], '/') : '', 'user' => isset($u['user']) ? rawurldecode($u['user']) : '',
        'pass' => isset($u['pass']) ? rawurldecode($u['pass']) : '', 'query' => $u['query'] ?? null, 'fragment' => $u['fragment'] ?? null];
}

/** wersja serwera w formacie Doctrine (serverVersion=): 10.11.19-MariaDB -> mariadb-10.11.19, 8.0.46 -> 8.0.46 */
function doctrine_server_version($v)
{
    $v = (string)$v;
    if ($v === '') return '';
    $num = preg_replace('/[^0-9.].*$/', '', $v);
    return (stripos($v, 'maria') !== false ? 'mariadb-' : '') . $num;
}

/** kodowanie user/haslo w URL: jak rawurlencode, ale ! i * zostaja (sa dozwolone w userinfo, a Symfony resolve: nie lubi %) */
function url_userinfo_encode($v) { return strtr(rawurlencode((string)$v), ['%21' => '!', '%2A' => '*']); }

function url_compose_db($url, array $e)
{
    $u = url_parse_db($url);
    if (!$u) return $url;
    $s = $u['scheme'] . '://';
    if ($e['user'] !== '') { $s .= url_userinfo_encode($e['user']); if ($e['pass'] !== '') $s .= ':' . url_userinfo_encode($e['pass']); $s .= '@'; }
    $s .= $e['host'] . ($e['port'] !== '' ? ':' . $e['port'] : '') . '/' . $e['name'];
    $query = $u['query'];
    if ($query !== null && !empty($e['server_version']) && preg_match('/(^|&)serverVersion=/', $query)) {
        $query = preg_replace('/(^|&)serverVersion=[^&]*/', '$1serverVersion=' . rawurlencode(doctrine_server_version($e['server_version'])), $query);
    }
    if ($query !== null) $s .= '?' . $query;
    if ($u['fragment'] !== null) $s .= '#' . $u['fragment'];
    return $s;
}

const DB_FIELDS = ['host', 'port', 'name', 'user', 'pass', 'socket'];

/** sloty -> ['fields'=>[host,port,name,user,pass,socket], 'dyn'=>[etykiety], 'socket'=>] */
function fields_from_slots(array $slots)
{
    $f = ['host' => null, 'port' => null, 'name' => null, 'user' => null, 'pass' => null, 'socket' => null];
    $dyn = []; $socket = null;
    $set = function ($k, $v) use (&$f) { if ($f[$k] === null) $f[$k] = (string)$v; };
    foreach ($slots as $s) {
        if (!empty($s['dyn'])) { $dyn[] = $s['label'] . ' [' . $s['type'] . ']'; continue; }
        switch ($s['type']) {
            case 'host': case 'name': case 'user': case 'pass': case 'port':
                $set($s['type'], $s['v']); break;
            case 'hostport':
                list($h, $p, $sock) = split_hostport($s['v']);
                $set('host', $h); if ($p !== '') $set('port', $p); if ($sock) { $socket = $sock; $set('socket', $sock); }
                break;
            case 'dsn':
                $d = dsn_parse($s['v']);
                $set('host', $d['host']); if ($d['port'] !== '') $set('port', $d['port']); $set('name', $d['name']);
                if ($d['socket'] !== '') { $socket = $d['socket']; $set('socket', $d['socket']); }
                break;
            case 'url':
                $u = url_parse_db($s['v']);
                if (!$u) { $dyn[] = $s['label'] . ' [url nieczytelny]'; break; }
                $set('host', $u['host']); if ($u['port'] !== '') $set('port', $u['port']);
                $set('name', $u['name']); $set('user', $u['user']); $set('pass', $u['pass']);
                break;
        }
    }
    foreach ($f as $k => $v) if ($v === null) $f[$k] = '';
    return ['fields' => $f, 'dyn' => $dyn, 'socket' => $socket];
}

// =====================================================================
//  Skaner katalogow stron
// =====================================================================

final class Scanner
{
    public static $excludes = ['.git', '.svn', '.hg', 'node_modules', 'vendor', 'cache', 'caches', 'tmp', 'temp', 'logs', 'log',
        'wp-content', 'wp-includes', 'wp-admin', 'administrator', 'components', 'modules', 'plugins', 'templates', 'libraries',
        'media', 'images', 'img', 'upload', 'uploads', 'files', 'storage', 'var', 'core', 'themes', 'classes', 'controllers',
        'src', 'translations', 'mails', 'localization', 'js', 'css', 'fonts', 'assets', 'download', 'pdf', 'tools', 'webservice',
        'override', 'lib', 'language', 'languages', 'bin', 'docs', 'doc', 'tests', 'test', 'sessions', 'session', 'stats',
        'awstats', 'cgi-bin', '.well-known', 'backup', 'backups', 'bak', 'node', 'vendors', 'bundles', 'generated', 'pub', 'setup',
        'dev', 'update', 'install', 'installation', 'mail', 'imap', 'Maildir', 'ssl', 'domains_bak', 'stats_bak'];
    public static $maxFileSize = 524288;
    public static $customScan = true;
    public static $results = [];   // file => parse result
    public static $dirsVisited = 0;
    public static $filesRead = 0;

    public static function run($root, $maxDepth)
    {
        self::$results = [];
        self::walk(rtrim($root, '/'), 0, $maxDepth, false);
        return array_values(self::$results);
    }

    private static function add($res)
    {
        if ($res && !isset(self::$results[$res['file']])) self::$results[$res['file']] = $res;
        return (bool)$res;
    }

    private static function tryParse($fn, $path)
    {
        try {
            return self::add($fn($path));
        } catch (Throwable $e) {
            Log::warn("Blad parsowania $path: " . $e->getMessage());
            return false;
        }
    }

    private static function walk($dir, $depth, $maxDepth, $insideCms)
    {
        self::$dirsVisited++;
        $entries = @scandir($dir);
        if ($entries === false) { Log::debug("pomijam (brak dostepu): $dir"); return; }
        $base = basename($dir);
        $parent = basename(dirname($dir));
        $isRootHere = false;      // czy ten katalog jest korzeniem jakiegos CMS
        $identified = [];         // pliki rozpoznane jako konfiguracja

        // markery korzenia CMS, zanim zejdziemy nizej
        if (is_file("$dir/app/etc/env.php") || is_file("$dir/app/config/parameters.php") || is_file("$dir/config/settings.inc.php")
            || is_file("$dir/sites/default/settings.php") || is_file("$dir/core/lib/Drupal.php") || is_file("$dir/artisan")
            || is_file("$dir/bin/console") || is_file("$dir/wp-settings.php") || is_file("$dir/includes/defines.php")) {
            $isRootHere = true;
        }

        $have = array_flip($entries);
        if (isset($have['wp-config.php']) && self::tryParse('parse_wordpress', "$dir/wp-config.php")) { $identified[] = 'wp-config.php'; $isRootHere = true; }
        if (isset($have['configuration.php']) && self::tryParse('parse_joomla', "$dir/configuration.php")) { $identified[] = 'configuration.php'; $isRootHere = true; }
        if (isset($have['settings.php']) && $parent === 'sites' && self::tryParse('parse_drupal', "$dir/settings.php")) $identified[] = 'settings.php';
        if (isset($have['parameters.php']) && $base === 'config' && self::tryParse('parse_prestashop17', "$dir/parameters.php")) $identified[] = 'parameters.php';
        elseif (isset($have['parameters.yml']) && $base === 'config' && self::tryParse('parse_prestashop_yml', "$dir/parameters.yml")) $identified[] = 'parameters.yml';
        if (isset($have['settings.inc.php']) && $base === 'config' && self::tryParse('parse_prestashop16', "$dir/settings.inc.php")) $identified[] = 'settings.inc.php';
        if (isset($have['env.php']) && $base === 'etc' && self::tryParse('parse_magento2', "$dir/env.php")) $identified[] = 'env.php';
        if (isset($have['config.php'])) {
            if (self::tryParse('parse_moodle', "$dir/config.php")) { $identified[] = 'config.php'; $isRootHere = true; }
            elseif (self::tryParse('parse_opencart', "$dir/config.php")) { $identified[] = 'config.php'; $isRootHere = $isRootHere || $base !== 'admin'; }
        }
        foreach (['.env', '.env.local', '.env.production'] as $envf) {
            if (isset($have[$envf]) && is_file("$dir/$envf") && self::tryParse('parse_env', "$dir/$envf")) { $identified[] = $envf; $isRootHere = true; }
        }
        if (in_array('.env', $identified, true) && in_array('.env.local', $identified, true) && isset(self::$results["$dir/.env"])) {
            self::$results["$dir/.env"]['notes'][] = 'obok jest .env.local, ktory NADPISUJE te wartosci (Symfony) - zwykle ten wiersz: action = skip';
        }
        if (isset($have['.env.local.php']) && is_file("$dir/.env.local.php") && self::tryParse('parse_env_local_php', "$dir/.env.local.php")) {
            $identified[] = '.env.local.php';
            foreach (['.env', '.env.local'] as $ef) if (isset(self::$results["$dir/$ef"])) self::$results["$dir/$ef"]['notes'][] = 'UWAGA: obok jest .env.local.php (composer dump-env), ktory ma PIERWSZENSTWO - migruj oba wiersze';
        }

        // wlasne skrypty PHP: tylko poza drzewem rozpoznanego CMS (korzen CMS tez sprawdzamy)
        if (self::$customScan && !$insideCms) {
            foreach ($entries as $e) {
                if (in_array($e, $identified, true) || substr($e, -4) !== '.php') continue;
                $p = "$dir/$e";
                if (is_link($p) || !is_file($p)) continue;
                $sz = @filesize($p);
                if ($sz === false || $sz > self::$maxFileSize || $sz < 20) continue;
                $head = (string)@file_get_contents($p);
                self::$filesRead++;
                if (!preg_match(CUSTOM_CALL_RE, $head)) continue;
                self::tryParse('parse_custom', $p);
            }
        }

        if ($depth >= $maxDepth) return;
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = "$dir/$e";
            if (!is_dir($p) || is_link($p)) continue;
            if (in_array($e, self::$excludes, true) || $e === 'dbmig-work') continue;
            if (Work::$dir && realpath($p) === Work::$dir) continue;   // katalog roboczy (backupy konfigow!)
            self::walk($p, $depth + 1, $maxDepth, $insideCms || $isRootHere);
        }
    }
}

/** id wiersza na podstawie sciezki pliku wzgledem korzenia skanowania */
function row_id($root, $file)
{
    $rel = $file;
    $root = rtrim($root, '/') . '/';
    if (strpos($file, $root) === 0) $rel = substr($file, strlen($root));
    $id = str_replace('/', '__', trim($rel, '/'));
    $id = preg_replace('/[^A-Za-z0-9._-]/', '_', $id);
    return $id;
}

/** zgadywanie URL strony: <root>/<domena>/public_html[/podkatalog] */
function guess_url($root, $siteRoot)
{
    $root = rtrim($root, '/');
    $siteRoot = rtrim($siteRoot, '/');
    if (preg_match('#^' . preg_quote($root, '#') . '/([^/]+)/public_html(?:/(.*))?$#', $siteRoot, $m)) {
        $sub = isset($m[2]) && $m[2] !== '' ? trim($m[2], '/') . '/' : '';
        return 'https://' . $m[1] . '/' . $sub;
    }
    if (preg_match('#^' . preg_quote($root, '#') . '/([^/]+)/#', $siteRoot . '/', $m) && strpos($m[1], '.') !== false) {
        return 'https://' . $m[1] . '/';
    }
    return '';
}

// =====================================================================
//  Warstwa bazy danych (mysqli, awaryjnie klient mysql CLI)
// =====================================================================

function db_cfg_normalize(array $c)
{
    $host = (string)($c['host'] ?? ''); $port = (string)($c['port'] ?? ''); $socket = (string)($c['socket'] ?? '') ?: null;
    if (strpos($host, ':') !== false) {
        list($h, $p, $sock) = split_hostport($host);
        $host = $h; if ($p !== '') $port = $p; if ($sock) $socket = $sock;
    }
    if ($host === '') $host = 'localhost';
    return ['host' => $host, 'port' => $port, 'socket' => $socket, 'name' => (string)($c['name'] ?? ''),
        'user' => (string)($c['user'] ?? ''), 'pass' => (string)($c['pass'] ?? '')];
}

/** klucz identyfikujacy baze (port 3306 == brak portu; localhost == 127.0.0.1) */
function db_key(array $c)
{
    $n = db_cfg_normalize($c);
    $host = ($n['host'] === '127.0.0.1') ? 'localhost' : $n['host'];
    $port = ($n['port'] === '3306') ? '' : $n['port'];
    if ($n['socket']) return 'localhost[' . $n['socket'] . ']/' . $n['name'];
    return $host . ($port !== '' ? ':' . $port : '') . '/' . $n['name'];
}

/** dodatkowe opcje klienta zalezne od wersji narzedzia (np. MariaDB 11.4+ domyslnie weryfikuje certyfikat TLS) */
function client_extra_opts($bin)
{
    $o = [];
    if (tool_supports($bin, 'ssl-verify-server-cert')) $o[] = '--skip-ssl-verify-server-cert';
    return $o;
}

final class Tmp
{
    private static $files = [];
    public static function file($prefix, $content, $mode = 0600)
    {
        $dir = Work::p('tmp');
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $f = $dir . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        file_put_contents($f, $content);
        chmod($f, $mode);
        self::$files[] = $f;
        if (count(self::$files) === 1) register_shutdown_function(['Tmp', 'cleanup']);
        return $f;
    }
    public static function cleanup() { foreach (self::$files as $f) @unlink($f); self::$files = []; }
}

/** plik opcji dla klientow mysql/mysqldump (haslo nie trafia do linii polecen ani ps) */
function mysql_defaults_file(array $cfg)
{
    $n = db_cfg_normalize($cfg);
    $q = function ($v) { return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"'; };
    $s = "[client]\nuser=" . $q($n['user']) . "\npassword=" . $q($n['pass']) . "\n";
    if ($n['socket']) { $s .= "socket=" . $q($n['socket']) . "\n"; }
    else {
        $host = $n['host'];
        if ($n['port'] !== '') { if ($host === 'localhost') $host = '127.0.0.1'; $s .= "port=" . (int)$n['port'] . "\nprotocol=TCP\n"; }
        $s .= "host=" . $q($host) . "\n";
    }
    return Tmp::file('my', $s);
}

/**
 * Uwierzytelnianie klientow mysql/mysqldump: domyslnie --defaults-extra-file (haslo w pliku 0600).
 * Na hostingach, gdzie /usr/bin/mysqldump to wrapper dokladajacy wlasne opcje PRZED naszymi,
 * klient odpowiada "unknown variable 'defaults-extra-file'" - wtedy przechodzimy na MYSQL_PWD w srodowisku.
 */
final class MyAuth
{
    public static $useEnv = false;

    /** zwraca ['args'=>[], 'env'=>array|null, 'file'=>sciezka|null] */
    public static function build(array $cfg)
    {
        $n = db_cfg_normalize($cfg);
        if (!self::$useEnv) {
            $def = mysql_defaults_file($cfg);
            return ['args' => ["--defaults-extra-file=$def"], 'env' => null, 'file' => $def];
        }
        $args = ['--user=' . $n['user']];
        if ($n['socket']) {
            $args[] = '--socket=' . $n['socket'];
        } else {
            $host = $n['host'];
            if ($n['port'] !== '') { if ($host === 'localhost') $host = '127.0.0.1'; $args[] = '--port=' . (int)$n['port']; $args[] = '--protocol=TCP'; }
            $args[] = '--host=' . $host;
        }
        $env = getenv();
        if (!is_array($env)) $env = [];
        $env['MYSQL_PWD'] = $n['pass'];
        return ['args' => $args, 'env' => $env, 'file' => null];
    }

    /** czy blad oznacza, ze trzeba przejsc na MYSQL_PWD; jesli tak - przelacza i zwraca true */
    public static function switchIfNeeded($err)
    {
        if (!self::$useEnv && preg_match("/unknown (variable|option) '?(--)?defaults-extra-file/i", $err)) {
            self::$useEnv = true;
            Log::warn("klient mysql to wrapper, ktory nie przyjmuje --defaults-extra-file - przechodze na uwierzytelnianie przez MYSQL_PWD (ponawiam)");
            return true;
        }
        return false;
    }
}

final class Db
{
    public $cfg;
    public $version = '';
    private $m = null;
    private $cli = false;

    public function __construct(array $cfg)
    {
        $this->cfg = db_cfg_normalize($cfg);
        $n = $this->cfg;
        if (function_exists('mysqli_init') && !Cli::has('db-cli')) {
            mysqli_report(MYSQLI_REPORT_OFF);
            $m = mysqli_init();
            $m->options(MYSQLI_OPT_CONNECT_TIMEOUT, 20);
            $host = $n['host']; $port = (int)$n['port'];
            if ($port && $host === 'localhost') $host = '127.0.0.1';
            if (!$port) $port = (int)ini_get('mysqli.default_port') ?: 3306;
            $ok = @$m->real_connect($host, $n['user'], $n['pass'], $n['name'], $port, $n['socket']);
            if (!$ok) throw new DbmigError("polaczenie MySQL nieudane [" . $m->connect_errno . "] " . $m->connect_error);
            @$m->set_charset('utf8mb4');
            $this->m = $m;
        } else {
            $this->cli = true;
        }
        $v = $this->query('SELECT VERSION()');
        $this->version = (string)($v[0][0] ?? '');
    }

    public function isMariaDb() { return stripos($this->version, 'maria') !== false; }
    public function isCli() { return $this->cli; }

    /** zwraca wiersze jako tablice numeryczne */
    public function query($sql)
    {
        if ($this->m) {
            $res = @$this->m->query($sql);
            if ($res === false) throw new DbmigError("SQL [" . $this->m->errno . "] " . $this->m->error . " -- " . substr($sql, 0, 120));
            if ($res === true) return [];
            $rows = [];
            while ($r = $res->fetch_row()) $rows[] = $r;
            $res->free();
            return $rows;
        }
        for ($try = 0; $try < 2; $try++) {
            $auth = MyAuth::build($this->cfg);
            $cmd = array_merge([mysql_bin()], $auth['args'], client_extra_opts(mysql_bin()), ['-N', '-B', '--raw', '-e', $sql]);
            if ($this->cfg['name'] !== '') $cmd[] = $this->cfg['name'];
            $r = run_proc($cmd, null, null, null, $auth['env']);
            if ($auth['file']) @unlink($auth['file']);
            if ($r['code'] === 0) break;
            if (MyAuth::switchIfNeeded($r['err'])) continue;
            throw new DbmigError("mysql CLI: " . trim($r['err']));
        }
        $rows = [];
        foreach (explode("\n", rtrim($r['out'], "\n")) as $line) { if ($line === '' ) continue; $rows[] = explode("\t", $line); }
        return $rows;
    }

    public function exec($sql) { $this->query($sql); }

    /** [nazwa => 'BASE TABLE'|'VIEW'] */
    public function tables()
    {
        $out = [];
        foreach ($this->query('SHOW FULL TABLES') as $r) $out[$r[0]] = $r[1];
        ksort($out);
        return $out;
    }
    public function rowCount($table) { $r = $this->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`'); return (int)($r[0][0] ?? 0); }
    public function sizeBytes()
    {
        $r = $this->query("SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = DATABASE()");
        return (int)($r[0][0] ?? 0);
    }
    public function charsetInfo()
    {
        $r = $this->query("SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()");
        return $r ? $r[0][0] . '/' . $r[0][1] : '';
    }
    public function grants()
    {
        try { return array_map(function ($r) { return $r[0]; }, $this->query('SHOW GRANTS')); } catch (Throwable $e) { return []; }
    }
    public function dropAllTables()
    {
        $t = $this->tables();
        $this->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($t as $name => $type) {
            $q = '`' . str_replace('`', '``', $name) . '`';
            $this->exec(($type === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . $q);
        }
        $this->exec('SET FOREIGN_KEY_CHECKS=1');
        return count($t);
    }
    public function close() { if ($this->m) { @$this->m->close(); $this->m = null; } }
}

function mysql_bin()
{
    static $p = null;
    if ($p === null) {
        $b = Cli::get('mysql-bin') ?: (getenv('DBMIG_MYSQL') ?: 'mysql');
        $p = which($b) ?: (which('mariadb') ?: false);
        if (!$p) throw new DbmigError("Nie znaleziono klienta mysql ($b). Podaj --mysql-bin=/sciezka/do/mysql");
    }
    return $p;
}
function mysqldump_bin()
{
    static $p = null;
    if ($p === null) {
        $b = Cli::get('mysqldump-bin') ?: (getenv('DBMIG_MYSQLDUMP') ?: 'mysqldump');
        $p = which($b) ?: (which('mariadb-dump') ?: false);
        if (!$p) throw new DbmigError("Nie znaleziono mysqldump ($b). Podaj --mysqldump-bin=/sciezka/do/mysqldump");
    }
    return $p;
}
function tool_supports($bin, $flag)
{
    static $cache = [];
    if (!isset($cache[$bin])) { $r = run_proc([$bin, '--help']); $cache[$bin] = $r['out'] . "\n" . $r['err']; }
    return strpos($cache[$bin], $flag) !== false;
}
function tool_version($bin)
{
    $r = run_proc([$bin, '--version']);
    return trim(preg_replace('/\s+/', ' ', $r['out'] !== '' ? $r['out'] : $r['err']));
}

// =====================================================================
//  Dump / import / weryfikacja
// =====================================================================

function dump_filename(array $old)
{
    $n = db_cfg_normalize($old);
    return Work::p('dumps/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $n['name']) . '__' . substr(sha1(db_key($old)), 0, 8) . '.sql');
}

/**
 * mysqldump starej bazy -> plik; w locie: usuwanie DEFINER, podmiana odwolan `stara`.
 * zwraca ['bytes'=>, 'definers'=>, 'renamed'=>]
 */
function do_dump(array $old, $file, $newName = null)
{
    $bin = mysqldump_bin();
    $n = db_cfg_normalize($old);
    $cs = Cli::get('charset', 'utf8mb4');
    $stripDefiner = !Cli::has('keep-definer');
    $renameFrom = ($newName !== null && $newName !== '' && $newName !== $n['name'] && !Cli::has('no-rename-refs')) ? '`' . $n['name'] . '`.' : null;
    $renameTo = $renameFrom ? '`' . $newName . '`.' : null;
    $skipRoutines = Cli::has('no-routines');
    $skipEvents = Cli::has('no-events');
    $tmp = $file . '.part';
    $errf = $file . '.stderr';

    while (true) {
        $auth = MyAuth::build($old);
        $cmd = array_merge([$bin], $auth['args'], client_extra_opts($bin), ['--single-transaction', '--quick', '--hex-blob',
            '--default-character-set=' . $cs, '--skip-lock-tables', '--add-drop-table', Cli::has('no-triggers') ? '--skip-triggers' : '--triggers']);
        if (!$skipRoutines) $cmd[] = '--routines';
        if (!$skipEvents) $cmd[] = '--events';
        if (tool_supports($bin, 'no-tablespaces')) $cmd[] = '--no-tablespaces';
        if (tool_supports($bin, 'column-statistics')) $cmd[] = '--column-statistics=0';
        if (tool_supports($bin, 'set-gtid-purged')) $cmd[] = '--set-gtid-purged=OFF';
        $cmd[] = $n['name'];

        $out = fopen($tmp, 'w');
        if (!$out) throw new DbmigError("Nie moge zapisac $tmp");
        $cmdline = implode(' ', array_map('escapeshellarg', $cmd));
        $p = proc_open($cmdline, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errf, 'w']], $pipes, null, $auth['env']);
        if (!is_resource($p)) { fclose($out); throw new DbmigError("Nie moge uruchomic mysqldump"); }
        $bytes = 0; $definers = 0; $renamed = 0; $nextReport = 100 * 1024 * 1024; $inData = false;
        while (($line = fgets($pipes[1])) !== false) {
            // INSERT/REPLACE moze byc wieloliniowy (MariaDB: wiersz na linie) - konczy sie srednikiem na koncu linii
            if (!$inData && (strncmp($line, 'INSERT ', 7) === 0 || strncmp($line, 'REPLACE ', 8) === 0)) $inData = true;
            $isData = $inData;
            if ($inData && substr(rtrim($line), -1) === ';') $inData = false;
            if ($isData) { fwrite($out, $line); $bytes += strlen($line); continue; }
            if ($stripDefiner && strpos($line, 'DEFINER=') !== false) {
                $line = preg_replace('/\s?DEFINER=`[^`]*`@`[^`]*`/', '', $line, -1, $c);
                $definers += $c;
            }
            // odwolania `stara_baza`. tylko w DDL (widoki/triggery/procedury), nigdy w danych
            if ($renameFrom !== null && strpos($line, $renameFrom) !== false) {
                $line = str_replace($renameFrom, $renameTo, $line, $c);
                $renamed += $c;
            }
            fwrite($out, $line);
            $bytes += strlen($line);
            if ($bytes >= $nextReport) { Log::info("  ... zrzucono " . human_bytes($bytes)); $nextReport += 100 * 1024 * 1024; }
        }
        fclose($pipes[1]);
        $code = proc_close($p);
        fclose($out);
        if ($auth['file']) @unlink($auth['file']);
        $err = trim((string)@file_get_contents($errf));
        if ($code === 0) break;
        @unlink($tmp);
        if (MyAuth::switchIfNeeded($err)) continue;
        // typowe na hostingu wspoldzielonym: brak uprawnien do procedur/eventow -> ponow bez nich
        if (!$skipRoutines && preg_match('/SHOW CREATE (PROCEDURE|FUNCTION)|mysql\.proc|insufficient privileges/i', $err)) {
            $skipRoutines = true;
            Log::warn("mysqldump: brak uprawnien do procedur/funkcji - ponawiam BEZ --routines (procedury trzeba przeniesc recznie): " . substr($err, -200));
            continue;
        }
        if (!$skipEvents && preg_match('/EVENT|events/i', $err) && preg_match('/denied|privilege/i', $err)) {
            $skipEvents = true;
            Log::warn("mysqldump: brak uprawnien do eventow - ponawiam BEZ --events: " . substr($err, -200));
            continue;
        }
        throw new DbmigError("mysqldump zakonczyl sie kodem $code: " . substr($err, -600));
    }
    if ($err !== '') Log::warn("mysqldump ostrzezenia: " . substr($err, -400));
    @unlink($errf);
    if ($bytes < 100) { @unlink($tmp); throw new DbmigError("mysqldump zwrocil pusty plik"); }
    if (!rename($tmp, $file)) throw new DbmigError("Nie moge zmienic nazwy $tmp -> $file");
    @chmod($file, 0600);
    return ['bytes' => $bytes, 'definers' => $definers, 'renamed' => $renamed, 'routines_skipped' => $skipRoutines && !Cli::has('no-routines'), 'events_skipped' => $skipEvents && !Cli::has('no-events')];
}

function do_import(array $new, $file)
{
    $bin = mysql_bin();
    $n = db_cfg_normalize($new);
    $cs = Cli::get('charset', 'utf8mb4');
    $logf = $file . '.import.log';
    for ($try = 0; $try < 2; $try++) {
        $auth = MyAuth::build($new);
        $cmd = array_merge([$bin], $auth['args'], client_extra_opts($bin), ['--default-character-set=' . $cs, $n['name']]);
        $r = run_proc($cmd, $file, $logf, $logf, $auth['env']);
        if ($auth['file']) @unlink($auth['file']);
        $log = trim((string)@file_get_contents($logf));
        if ($r['code'] === 0 || !MyAuth::switchIfNeeded($log)) break;
    }
    if ($r['code'] !== 0) {
        $hint = '';
        if (stripos($log, 'gone away') !== false) $hint = ' (wskazowka: za duzy pakiet - max_allowed_packet na nowym serwerze)';
        if (stripos($log, 'Unknown collation') !== false) $hint = ' (wskazowka: nowy serwer nie zna tej kolacji; MySQL8 -> MariaDB?)';
        if (stripos($log, 'Access denied') !== false) $hint = ' (wskazowka: uzytkownik nowej bazy nie ma uprawnien; SUPER/TRIGGER?)';
        if (strpos($log, 'ERROR 1419') !== false) $hint = ' (wskazowka: nowy serwer wymaga SUPER do tworzenia triggerow/funkcji, bo ma binlog i log_bin_trust_function_creators=0 - zglos do hostingu; obejscie: --no-triggers / --no-routines i zaloz je recznie)';
        if (strpos($log, 'ERROR 1227') !== false) $hint = ' (wskazowka: brak SUPER - zrzut zawiera DEFINER/zmienne globalne; nie uzywaj --keep-definer)';
        $hint .= "\n  nowa baza moze byc CZESCIOWO zaimportowana - przed ponowieniem wyczysc ja: php dbmig.php clean-new --site <id>";
        throw new DbmigError("import zakonczyl sie kodem " . $r['code'] . ": " . substr($log, -600) . $hint);
    }
    if ($log !== '') Log::warn("import - komunikaty: " . substr($log, -400));
    else @unlink($logf);
    return true;
}

/** porownanie tabel i liczby wierszy; zwraca ['missing'=>[], 'extra'=>[], 'diff'=>[[t,old,new]], 'tables'=>n, 'views'=>n] */
function do_verify(Db $old, Db $new, $fast = false)
{
    $ot = $old->tables(); $nt = $new->tables();
    $missing = array_values(array_diff(array_keys($ot), array_keys($nt)));
    $extra = array_values(array_diff(array_keys($nt), array_keys($ot)));
    $diff = []; $tables = 0; $views = 0;
    foreach ($ot as $t => $type) {
        if (!isset($nt[$t])) continue;
        if ($type === 'VIEW') { $views++; continue; }
        $tables++;
        if ($fast) continue;
        try { $a = $old->rowCount($t); } catch (Throwable $e) { $a = -1; }
        try { $b = $new->rowCount($t); } catch (Throwable $e) { $b = -1; }
        if ($a !== $b) $diff[] = [$t, $a, $b];
    }
    return ['missing' => $missing, 'extra' => $extra, 'diff' => $diff, 'tables' => $tables, 'views' => $views];
}

// =====================================================================
//  Podmiana danych w plikach konfiguracyjnych
// =====================================================================

/** nowa wartosc slotu dla docelowych pol $eff (host,port,name,user,pass) */
function slot_compose(array $s, array $eff)
{
    $sock = (string)($eff['socket'] ?? '');
    switch ($s['type']) {
        case 'host': return $eff['host'];
        case 'hostport': return $eff['host'] . ($sock !== '' ? ':' . $sock : ($eff['port'] !== '' ? ':' . $eff['port'] : ''));
        case 'port': return $eff['port'];
        case 'name': return $eff['name'];
        case 'user': return $eff['user'];
        case 'pass': return $eff['pass'];
        case 'dsn': return dsn_compose($s['v'], $eff['host'], $eff['port'], $eff['name'], $sock);
        case 'url': return url_compose_db($s['v'], $eff);
    }
    return null;
}

function slot_encode(array $s, $v)
{
    switch ($s['enc']) {
        case 'php': return (!empty($s['int']) && ctype_digit((string)$v)) ? (string)$v : PhpFile::encodeLiteral($v);
        case 'env': return env_encode($v);
        case 'yml': return yml_encode($v);
    }
    return $v;
}

/**
 * planuje edycje: zwraca ['edits'=>[file=>[[o,l,text,label,old,new],...]], 'warnings'=>[], 'errors'=>[]]
 */
function build_edits(array $parsed, array $eff)
{
    $edits = []; $warnings = []; $errors = [];
    $hasPortSlot = false; $hostSlot = null;
    foreach ($parsed['slots'] as $s) {
        if ($s['type'] === 'port') $hasPortSlot = true;
        if ($s['type'] === 'host' && empty($s['dyn'])) $hostSlot = $s;
    }
    $sock = (string)($eff['socket'] ?? '');
    foreach ($parsed['slots'] as $s) {
        if (!empty($s['dyn'])) { $warnings[] = "{$s['label']} nie jest literalem (wartosc dynamiczna) - do recznej edycji: {$s['file']}" . ($s['line'] ? ':' . $s['line'] : ''); continue; }
        $nv = slot_compose($s, $eff);
        if ($nv === null || $nv === $s['v']) continue;
        if ($s['type'] === 'dsn' && dsn_parse($s['v'])['socket'] !== '' && $sock === '') $warnings[] = "DSN zawieral unix_socket - usunieto go, bo nowy host jest po TCP";
        if ($s['type'] === 'url' && stripos($s['v'], 'serverVersion=') !== false && array_key_exists('server_version', $eff) && $eff['server_version'] === '') $warnings[] = "{$s['label']} zawiera serverVersion=... - po migracji zaktualizuj go recznie do wersji nowego serwera (Doctrine)";
        if ($sock !== '' && in_array($s['type'], ['host', 'url'], true)) $warnings[] = "{$s['label']}: ten format nie pozwala zapisac sciezki socketu ($sock) - wpisze sam host; sprawdz recznie";
        $edits[$s['file']][] = ['o' => $s['o'], 'l' => $s['l'], 'text' => slot_encode($s, $nv), 'label' => $s['label'], 'old' => $s['v'], 'new' => $nv, 'line' => $s['line']];
    }
    if ($eff['port'] !== '' && !$hasPortSlot) {
        $hostTypes = array_column($parsed['slots'], 'type');
        if (in_array('host', $hostTypes, true) && !in_array('hostport', $hostTypes, true) && !in_array('dsn', $hostTypes, true) && !in_array('url', $hostTypes, true)) {
            if (!empty($parsed['port_insert']) && $hostSlot) {
                $edits[$hostSlot['file']][] = ['o' => $hostSlot['o'] + $hostSlot['l'], 'l' => 0, 'text' => ", 'port' => " . PhpFile::encodeLiteral($eff['port']),
                    'label' => "'port' (nowy klucz)", 'old' => '', 'new' => $eff['port'], 'line' => $hostSlot['line']];
            } elseif (!empty($parsed['port_insert_custom'])) {
                $pi = $parsed['port_insert_custom'];
                $edits[$pi['file']][] = ['o' => $pi['o'], 'l' => 0, 'text' => ', ' . (int)$eff['port'], 'label' => 'mysqli port (nowy 5. argument)', 'old' => '', 'new' => $eff['port'], 'line' => $pi['line']];
            } elseif ($parsed['cms'] === 'laravel' && $hostSlot && $hostSlot['enc'] === 'env' && isset($hostSlot['eol'])) {
                $edits[$hostSlot['file']][] = ['o' => $hostSlot['eol'], 'l' => 0, 'text' => "\nDB_PORT=" . env_encode($eff['port']),
                    'label' => 'DB_PORT (nowy klucz)', 'old' => '', 'new' => $eff['port'], 'line' => $hostSlot['line']];
            } else {
                $errors[] = "podano port ({$eff['port']}), ale ten plik nie ma osobnego pola portu i nie umiem go dopisac - dopisz recznie i uzyj action = db-only";
            }
        }
    }
    return ['edits' => $edits, 'warnings' => $warnings, 'errors' => $errors];
}

function apply_edits_to_content($content, array $edits)
{
    usort($edits, function ($a, $b) { return $b['o'] - $a['o']; });
    $lastStart = PHP_INT_MAX;
    foreach ($edits as $e) {
        if ($e['o'] + $e['l'] > $lastStart) throw new DbmigError("nakladajace sie edycje w pliku");
        $content = substr($content, 0, $e['o']) . $e['text'] . substr($content, $e['o'] + $e['l']);
        $lastStart = $e['o'];
    }
    return $content;
}

// ---------- backup / restore ----------

function backup_files($id, array $files)
{
    $base = Work::p('backups/' . $id . '/' . now_ts());
    $dir = $base;
    for ($i = 2; is_dir($dir) && $i < 100; $i++) $dir = $base . '-' . $i;
    if (!@mkdir($dir, 0700, true)) throw new DbmigError("Nie moge utworzyc katalogu backupu $dir");
    $manifest = [];
    $k = 0;
    foreach ($files as $f) {
        $k++;
        $dst = $dir . '/' . $k . '_' . basename($f);
        if (!copy($f, $dst)) throw new DbmigError("Nie moge skopiowac $f do backupu");
        @chmod($dst, 0600);
        $manifest[] = ['orig' => $f, 'copy' => $dst, 'sha1' => sha1_file($f), 'mode' => fileperms($f) & 0777];
    }
    file_put_contents($dir . '/manifest.json', json_encode(['id' => $id, 'created' => date('c'), 'files' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $dir;
}

function restore_backup($dir)
{
    $m = json_decode((string)@file_get_contents($dir . '/manifest.json'), true);
    if (!$m || empty($m['files'])) throw new DbmigError("Brak manifest.json w $dir");
    $restored = [];
    foreach ($m['files'] as $f) {
        if (!is_file($f['copy'])) throw new DbmigError("Brak kopii " . $f['copy']);
        write_file_atomic($f['orig'], (string)file_get_contents($f['copy']), $f['mode'] ?? null);
        PhpFile::forget($f['orig']);
        $restored[] = $f['orig'];
    }
    return $restored;
}

function latest_backup_dir($id)
{
    $base = Work::p('backups/' . $id);
    if (!is_dir($base)) return null;
    $dirs = array_filter(scandir($base), function ($d) use ($base) { return $d[0] !== '.' && is_dir("$base/$d") && is_file("$base/$d/manifest.json"); });
    if (!$dirs) return null;
    rsort($dirs);
    return $base . '/' . reset($dirs);
}

function write_file_atomic($path, $content, $mode = null)
{
    $tmp = $path . '.dbmig-tmp';
    if (file_put_contents($tmp, $content) === false) throw new DbmigError("Nie moge zapisac $tmp");
    if ($mode === null) $mode = @fileperms($path) & 0777;
    if ($mode) @chmod($tmp, $mode);
    if (!rename($tmp, $path)) { @unlink($tmp); throw new DbmigError("Nie moge podmienic $path"); }
    clearstatcache(true, $path);
}

function php_lint($path)
{
    $r = run_proc([php_binary(), '-l', $path]);
    return $r['code'] === 0 ? true : trim($r['out'] . "\n" . $r['err']);
}

// ---------- test HTTP ----------

const DB_ERROR_PATTERNS = [
    'Error establishing a database connection', 'nawiązywania połączenia z bazą danych', 'nawiazywania polaczenia z baza',
    'Could not connect to MySQL', 'Database connection error', 'Link to database cannot be established',
    'SQLSTATE[', 'Access denied for user', 'Unknown database', "Can't connect to MySQL", "Can't connect to local MySQL",
    'Connection refused', 'Database Error', 'Database connection failed', 'mysqli::real_connect', 'mysqli_connect()',
    'mysqli_real_connect', 'No such file or directory', 'Error: Database connection failed', 'PDOException',
    'Unable to connect to the database', 'error-database',
];

function http_probe($url)
{
    $u = $url . (strpos($url, '?') === false ? '?' : '&') . 'dbmig=' . substr(md5((string)microtime(true)), 0, 8);
    $res = ['code' => 0, 'db_error' => false, 'error' => null, 'ms' => 0, 'match' => null];
    $t0 = microtime(true);
    $body = '';
    if (function_exists('curl_init') && !Cli::has('curl-cli')) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'dbmig/' . DBMIG_VERSION . ' (migration check)', CURLOPT_HTTPHEADER => ['Cache-Control: no-cache', 'Pragma: no-cache']]);
        $body = (string)curl_exec($ch);
        $res['code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) $res['error'] = curl_error($ch);
        curl_close($ch);
    } else {
        $curl = which(Cli::get('curl-bin', 'curl'));
        if (!$curl) { $res['error'] = 'brak curl (rozszerzenie PHP i program)'; return $res; }
        $bodyf = Tmp::file('http', '');
        $r = run_proc([$curl, '-sS', '-k', '-L', '--max-redirs', '5', '--max-time', '45', '-A', 'dbmig/' . DBMIG_VERSION, '-H', 'Cache-Control: no-cache',
            '-o', $bodyf, '-w', '%{http_code}', $u]);
        $res['code'] = (int)trim($r['out']);
        if ($r['code'] !== 0) $res['error'] = trim($r['err']);
        $body = (string)@file_get_contents($bodyf);
    }
    $res['ms'] = (int)((microtime(true) - $t0) * 1000);
    foreach (DB_ERROR_PATTERNS as $p) {
        if (stripos($body, $p) !== false) { $res['db_error'] = true; $res['match'] = $p; break; }
    }
    return $res;
}

function http_probe_str(array $r)
{
    $s = 'HTTP ' . $r['code'] . ' (' . $r['ms'] . ' ms)';
    if ($r['error']) $s .= ' blad: ' . $r['error'];
    if ($r['db_error']) $s .= ' BLAD BAZY w tresci ("' . $r['match'] . '")';
    return $s;
}

// =====================================================================
//  mapping.conf (format INI-podobny, przyjazny do edycji w nano/vim)
// =====================================================================

const ROW_KEYS = ['cms', 'file', 'url', 'old_host', 'old_port', 'old_name', 'old_user', 'old_pass', 'old_socket',
    'action', 'new_host', 'new_port', 'new_name', 'new_user', 'new_pass', 'notes'];
const DEFAULTABLE = ['action', 'new_host', 'new_port', 'new_name', 'new_user', 'new_pass'];
const ACTIONS = ['skip', 'migrate', 'db-only', 'config-only', 'manual'];

function mapping_q($v)
{
    $v = (string)$v;
    if ($v === '') return '';
    if (preg_match('/^\s|\s$|^"|[\r\n]/', $v)) return '"' . addcslashes($v, "\\\"\r\n\t") . '"';
    return $v;
}
function mapping_unq($v)
{
    $v = trim($v);
    if (strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"') return stripcslashes(substr($v, 1, -1));
    return $v;
}

function mapping_read($file)
{
    $out = ['defaults' => [], 'rows' => []];
    if (!is_file($file)) return $out;
    $sec = null;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $ln => $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#' || $t[0] === ';') continue;
        if (preg_match('/^\[(.+)\]$/', $t, $m)) {
            $sec = trim($m[1]);
            if ($sec !== 'defaults' && !isset($out['rows'][$sec])) $out['rows'][$sec] = [];
            continue;
        }
        if ($sec === null) continue;
        $p = strpos($line, '=');
        if ($p === false) { Log::warn("mapping.conf linia " . ($ln + 1) . ": brak '=' - pomijam"); continue; }
        $k = trim(substr($line, 0, $p));
        $v = mapping_unq(substr($line, $p + 1));
        if ($sec === 'defaults') $out['defaults'][$k] = $v; else $out['rows'][$sec][$k] = $v;
    }
    return $out;
}

function mapping_write($file, array $defaults, array $rows)
{
    $s = "# dbmig mapping.conf  (wygenerowano " . date('Y-m-d H:i') . ", dbmig " . DBMIG_VERSION . ")\n";
    $s .= "#\n";
    $s .= "# Dla kazdej strony uzupelnij dane NOWEJ bazy (zalozonej w panelu na nowej instancji MySQL)\n";
    $s .= "# i ustaw  action = migrate.  Puste new_* = zostaw stara wartosc.  Wartosc '-' = wyczysc (np. port).\n";
    $s .= "#   action: skip        - nie ruszaj (domyslnie)\n";
    $s .= "#           migrate     - dump -> import -> weryfikacja -> podmiana konfiguracji -> test HTTP\n";
    $s .= "#           db-only     - tylko dump/import/weryfikacja (konfiguracje zmienisz recznie)\n";
    $s .= "#           config-only - tylko podmiana konfiguracji (baza juz przeniesiona np. przez panel)\n";
    $s .= "#           manual      - do recznej obslugi (skrypt pomija, ale pokazuje w status)\n";
    $s .= "# new_host moze zawierac port: 127.0.0.1:3307  (NIE uzywaj 'localhost:port' - PHP zignoruje port i uzyje socketu)\n";
    $s .= "#          albo socket:       localhost:/sciezka/do/mysql.sock\n";
    $s .= "# Sekcja [defaults] uzupelnia puste pola we wszystkich wierszach (np. wspolny new_host).\n";
    $s .= "# Wartosci ze spacjami na koncu/poczatku lub znakami nowej linii ujmij w \"cudzyslowy\" (escape: \\\" \\\\).\n";
    $s .= "# UWAGA: plik zawiera hasla - trzymaj go poza public_html, chmod 600.\n\n";
    $s .= "[defaults]\n";
    foreach (DEFAULTABLE as $k) $s .= "$k = " . mapping_q($defaults[$k] ?? ($k === 'action' ? 'skip' : '')) . "\n";
    $s .= "\n";
    foreach ($rows as $id => $r) {
        $s .= "[$id]\n";
        foreach (ROW_KEYS as $k) {
            if (($k === 'notes' || $k === 'old_socket') && ($r[$k] ?? '') === '') continue;
            $v = mapping_q($r[$k] ?? '');
            $s .= "$k =" . ($v === '' ? '' : ' ' . $v) . "\n";
        }
        $s .= "\n";
    }
    file_put_contents($file, $s);
    @chmod($file, 0600);
}

/** docelowe pola na podstawie starych + new_* z mapping (puste = bez zmian, '-' = wyczysc) */
function row_eff(array $old, array $new)
{
    $eff = $old;
    if (!isset($eff['socket'])) $eff['socket'] = '';
    if ($new['host'] !== '') {
        list($h, $p2, $sock) = split_hostport($new['host'] === '-' ? '' : $new['host']);
        $eff['host'] = $h;
        $eff['socket'] = $sock ? $sock : '';          // nowy host bez ':/sciezka/socket' = polaczenie TCP
        if ($p2 !== '') $eff['port'] = $p2;
    }
    foreach (['port', 'name', 'user', 'pass'] as $k) {
        if ($new[$k] !== '') $eff[$k] = ($new[$k] === '-') ? '' : $new[$k];
    }
    return $eff;
}

// =====================================================================
//  Wiersze: mapping + zywe parsowanie plikow
// =====================================================================

function load_rows()
{
    $mf = Work::mappingFile();
    if (!is_file($mf)) fail("Brak pliku $mf - najpierw uruchom: php dbmig.php scan");
    if ((@fileperms($mf) & 0077) !== 0) { @chmod($mf, 0600); Log::debug("ustawiono chmod 600 na $mf"); }
    $m = mapping_read($mf);
    $rows = [];
    foreach ($m['rows'] as $id => $r) {
        foreach (DEFAULTABLE as $k) if (($r[$k] ?? '') === '' && ($m['defaults'][$k] ?? '') !== '') $r[$k] = $m['defaults'][$k];
        $row = ['id' => $id, 'cms' => $r['cms'] ?? '', 'file' => $r['file'] ?? '', 'url' => $r['url'] ?? '', 'notes' => $r['notes'] ?? '',
            'action' => strtolower(trim($r['action'] ?? 'skip')) ?: 'skip',
            'map_old' => ['host' => $r['old_host'] ?? '', 'port' => $r['old_port'] ?? '', 'name' => $r['old_name'] ?? '', 'user' => $r['old_user'] ?? '', 'pass' => $r['old_pass'] ?? '', 'socket' => $r['old_socket'] ?? ''],
            'new' => ['host' => $r['new_host'] ?? '', 'port' => $r['new_port'] ?? '', 'name' => $r['new_name'] ?? '', 'user' => $r['new_user'] ?? '', 'pass' => $r['new_pass'] ?? ''],
            'domain' => explode('__', $id)[0],
        ];
        if (Cli::has('url') && count(Cli::listOf('site')) === 1) $row['url'] = Cli::get('url');
        $rows[$id] = $row;
    }
    return $rows;
}

/** parsuje plik wiersza i wylicza pola stare (live) i docelowe (eff) */
function row_prepare(array &$row)
{
    $row['parsed'] = null; $row['live'] = null; $row['eff'] = null; $row['dyn'] = []; $row['stale'] = false; $row['done'] = false; $row['error'] = null;
    if ($row['file'] === '' || !is_file($row['file'])) { $row['error'] = "plik nie istnieje: " . $row['file']; return; }
    try {
        $p = parse_by_cms($row['cms'], $row['file']);
    } catch (Throwable $e) {
        $row['error'] = "blad parsowania: " . $e->getMessage(); return;
    }
    if (!$p) { $row['error'] = "nie rozpoznano konfiguracji typu '{$row['cms']}' w pliku"; return; }
    $row['parsed'] = $p;
    $fs = fields_from_slots($p['slots']);
    $row['live'] = $fs['fields'];
    $row['dyn'] = $fs['dyn'];
    $row['socket'] = $fs['socket'];
    $row['eff'] = row_eff($row['live'], $row['new']);
    $effMap = row_eff($row['map_old'], $row['new']);
    $liveIsOld = true; $liveIsNew = true;
    foreach (DB_FIELDS as $k) {
        if ((string)$row['map_old'][$k] !== (string)$row['live'][$k]) $liveIsOld = false;
        if ((string)$effMap[$k] !== (string)$row['live'][$k]) $liveIsNew = false;
    }
    // plik juz zawiera nowe dane (migracja wykonana) -> "done"; inne rozbieznosci -> "stale"
    if (!$liveIsOld && $liveIsNew) $row['done'] = true;
    elseif (!$liveIsOld) $row['stale'] = true;
}

function row_is_active(array $row) { return in_array($row['action'], ['migrate', 'db-only', 'config-only'], true); }
function row_needs_db(array $row) { return in_array($row['action'], ['migrate', 'db-only'], true); }
function row_needs_config(array $row) { return in_array($row['action'], ['migrate', 'config-only'], true); }

/** wybor wierszy wg --site / --all */
function select_rows(array $rows, $requireSelection = true)
{
    $sites = Cli::listOf('site');
    if (!$sites) {
        if (Cli::has('all') || !$requireSelection) return $rows;
        fail("Podaj --site <id|domena|wzorzec> (mozna kilka, po przecinku) albo --all.");
    }
    $out = [];
    foreach ($rows as $id => $r) {
        foreach ($sites as $s) {
            if ($id === $s || strpos($id, $s . '__') === 0 || $r['domain'] === $s || fnmatch($s, $id)) { $out[$id] = $r; break; }
        }
    }
    if (!$out) fail("--site nie pasuje do zadnego wiersza w mapping.conf (sprawdz: php dbmig.php status)");
    return $out;
}

/** grupowanie wierszy po starej bazie: [key => ['old'=>, 'new'=>, 'rows'=>[ids], 'needs_db'=>bool]] */
function build_jobs(array $rows)
{
    $jobs = []; $errors = [];
    foreach ($rows as $id => $r) {
        if (!row_is_active($r) || $r['live'] === null || !empty($r['invalid']) || !empty($r['done']) || $r['live']['name'] === '') continue;
        $key = db_key($r['live']);
        if (!isset($jobs[$key])) $jobs[$key] = ['key' => $key, 'old' => $r['live'], 'new' => $r['eff'], 'rows' => [], 'needs_db' => false];
        $j =& $jobs[$key];
        $j['rows'][] = $id;
        if (row_needs_db($r)) $j['needs_db'] = true;
        if (conn_sig($j['new']) !== conn_sig($r['eff'])) {
            $errors[] = "$id: inne dane NOWEJ bazy (" . fmt_conn($r['eff']) . ") niz w wierszu " . $j['rows'][0] . " (" . fmt_conn($j['new']) . ") dla tej samej starej bazy $key";
        }
        unset($j);
    }
    return [$jobs, $errors];
}

/** sygnatura polaczenia do porownan (port 3306 == brak portu) */
function conn_sig(array $c) { return db_key($c) . '|' . $c['user'] . '|' . $c['pass']; }

function fmt_conn(array $c, $withUser = true)
{
    $s = $c['host'] . ($c['port'] !== '' ? ':' . $c['port'] : '') . '/' . $c['name'];
    if ($withUser) $s .= ' (' . $c['user'] . ' / ' . mask($c['pass']) . ')';
    return $s;
}

// =====================================================================
//  Komendy
// =====================================================================

function cmd_scan()
{
    $home = (string)getenv('HOME');
    $root = Cli::get('root');
    if (!$root) $root = ($home && is_dir("$home/domains")) ? "$home/domains" : getcwd();
    $rootR = realpath($root);
    if (!$rootR || !is_dir($rootR)) fail("Katalog do skanowania nie istnieje: $root (podaj --root=/sciezka)");
    $root = $rootR;
    $depth = (int)Cli::get('depth', 7);
    Scanner::$customScan = !Cli::has('no-custom');
    foreach (Cli::listOf('exclude') as $x) Scanner::$excludes[] = $x;

    Log::step("Skanowanie: $root (max glebokosc $depth" . (Scanner::$customScan ? ', z wlasnymi skryptami PHP' : '') . ")");
    $t0 = microtime(true);
    $found = Scanner::run($root, $depth);
    usort($found, function ($a, $b) { return strcmp($a['file'], $b['file']); });
    Log::info(sprintf("Przejrzano %d katalogow, %d plikow PHP pod katem wlasnych polaczen, czas %.1fs. Znaleziono konfiguracji: %d",
        Scanner::$dirsVisited, Scanner::$filesRead, microtime(true) - $t0, count($found)));

    $testDb = !Cli::has('no-db-test');
    $inv = []; $table = [];
    $mf = Work::mappingFile();
    $existing = mapping_read($mf);
    $defaults = $existing['defaults'] ?: ['action' => 'skip'];
    $mrows = $existing['rows'];
    $seen = [];

    foreach ($found as $p) {
        $id = row_id($root, $p['file']);
        $url = guess_url($root, $p['root']);
        $fs = fields_from_slots($p['slots']);
        $f = $fs['fields'];
        $notes = $p['notes'];
        foreach ($fs['dyn'] as $d) $notes[] = "wartosc dynamiczna: $d";
        if ($fs['socket']) $notes[] = "socket: " . $fs['socket'];
        $conn = '-'; $ver = ''; $tables = ''; $size = '';
        if ($testDb && $f['name'] !== '' && !$fs['dyn']) {
            try {
                $db = new Db($f);
                $conn = 'OK'; $ver = $db->version;
                $tables = count($db->tables()); $size = human_bytes($db->sizeBytes());
                $db->close();
            } catch (Throwable $e) {
                $conn = 'BLAD'; $notes[] = 'polaczenie: ' . $e->getMessage();
            }
        }
        $row = ['id' => $id, 'cms' => $p['cms'], 'file' => $p['file'], 'url' => $url, 'host' => $f['host'], 'port' => $f['port'],
            'name' => $f['name'], 'user' => $f['user'], 'pass' => $f['pass'], 'prefix' => $p['extra']['prefix'] ?? '',
            'conn' => $conn, 'server' => $ver, 'tables' => $tables, 'size' => $size, 'notes' => implode('; ', $notes),
            'files' => $p['files']];
        $inv[] = $row;
        $table[] = [$id, $p['cms'], $f['host'] . ($f['port'] !== '' ? ':' . $f['port'] : ''), $f['name'], $f['user'], mask($f['pass']), $conn, $ver, $tables, $size, implode('; ', $notes)];

        $seen[$id] = true;
        $isNew = !isset($mrows[$id]);
        $mr = $mrows[$id] ?? [];
        $changed = []; $migrated = false;
        if (!$isNew) {
            // plik zawiera juz dane NOWEJ bazy (migracja wykonana)? -> nie nadpisuj old_*
            $mapOld = []; $mapNew = [];
            foreach (DB_FIELDS as $k) $mapOld[$k] = $mr["old_$k"] ?? '';
            foreach (['host', 'port', 'name', 'user', 'pass'] as $k) $mapNew[$k] = $mr["new_$k"] ?? '';
            $effMap = row_eff($mapOld, $mapNew);
            $migrated = ($mapNew['name'] !== '' || $mapNew['host'] !== '');
            foreach (DB_FIELDS as $k) {
                if ((string)$effMap[$k] !== (string)$f[$k]) $migrated = false;
                if (($mr["old_$k"] ?? '') !== $f[$k]) $changed[] = $k;
            }
        }
        $mr['cms'] = $p['cms']; $mr['file'] = $p['file'];
        if (($mr['url'] ?? '') === '') $mr['url'] = $url;
        if ($migrated) { Log::info("$id: plik zawiera juz dane nowej bazy (zmigrowany) - old_* w mapping.conf bez zmian"); $changed = []; }
        else foreach (DB_FIELDS as $k) $mr["old_$k"] = $f[$k];
        if ($isNew) { foreach (['action', 'new_host', 'new_port', 'new_name', 'new_user', 'new_pass'] as $k) $mr[$k] = ''; }
        $mr['notes'] = implode('; ', $notes);
        $mrows[$id] = $mr;
        if ($changed) Log::warn("$id: dane bazy w pliku zmienily sie od poprzedniego skanu (" . implode(', ', $changed) . ") - zaktualizowano old_* w mapping.conf");
    }

    // inventory.csv / .json
    $csv = Work::p('inventory.csv');
    $fh = fopen($csv, 'w');
    fputcsv($fh, ['id', 'cms', 'config_file', 'url', 'db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'table_prefix', 'connection', 'server_version', 'tables', 'size', 'notes']);
    foreach ($inv as $r) fputcsv($fh, [$r['id'], $r['cms'], $r['file'], $r['url'], $r['host'], $r['port'], $r['name'], $r['user'], $r['pass'], $r['prefix'], $r['conn'], $r['server'], $r['tables'], $r['size'], $r['notes']]);
    fclose($fh);
    @chmod($csv, 0600);
    $js = Work::p('inventory.json');
    file_put_contents($js, json_encode(['generated' => date('c'), 'root' => $root, 'sites' => $inv], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    @chmod($js, 0600);

    $missing = array_diff(array_keys($mrows), array_keys($seen));
    foreach ($missing as $id) Log::warn("$id: jest w mapping.conf, ale nie znaleziono go w tym skanie (plik usuniety? inny --root?)");
    mapping_write($mf, $defaults, $mrows);

    Log::raw('');
    print_table(['id', 'cms', 'host', 'baza', 'user', 'haslo', 'polacz.', 'serwer', 'tabel', 'rozmiar', 'uwagi'], $table);
    Log::raw('');
    Log::ok("Zapisano: $csv, $js");
    Log::ok("Mapping: $mf (" . count($mrows) . " wierszy" . ($existing['rows'] ? ', istniejace wpisy new_*/action zachowane' : '') . ")");
    Log::raw('');
    Log::raw("Nastepne kroki:");
    Log::raw("  1. Zaloz NOWE bazy w panelu (Bazy danych -> Zarzadzanie MySQL -> instancja: nowa wersja).");
    Log::raw("  2. Uzupelnij w $mf pola new_host/new_name/new_user/new_pass i ustaw action = migrate.");
    Log::raw("  3. php dbmig.php check            (sprawdzenie polaczen i plikow, nic nie zmienia)");
    Log::raw("  4. php dbmig.php migrate --site <id> --dry-run   a potem bez --dry-run; na koncu --all");
    return 0;
}

// ---------- check ----------

/**
 * sprawdza wiersze; tryb: full|dump|import|verify|config
 * zwraca ['errors'=>n, 'warnings'=>n, 'jobs'=>[...]]
 */
function check_rows(array &$rows, $mode = 'full')
{
    $errors = 0; $warnings = 0;
    $E = function ($m) use (&$errors) { Log::err($m); $errors++; };
    $W = function ($m) use (&$warnings) { Log::warn($m); $warnings++; };
    $force = Cli::has('force');
    $needOld = in_array($mode, ['full', 'dump', 'verify'], true);
    $needNew = in_array($mode, ['full', 'import', 'verify', 'config'], true);
    $wantConfig = in_array($mode, ['full', 'config'], true);

    foreach ($rows as $id => &$r) {
        if (!in_array($r['action'], ACTIONS, true)) { $E("$id: nieznane action='{$r['action']}' (dozwolone: " . implode(', ', ACTIONS) . ")"); continue; }
        if (!row_is_active($r)) continue;
        row_prepare($r);
        if ($r['error']) { $E("$id: " . $r['error']); $r['invalid'] = true; continue; }
        if ($r['done']) { Log::info("$id: plik juz zawiera dane nowej bazy (migracja wykonana) - pomijam"); $r['invalid'] = true; continue; }
        if ($r['stale']) { $E("$id: dane w pliku ({$r['file']}) roznia sie od old_* w mapping.conf - uruchom ponownie 'scan' i sprawdz wpis"); $r['invalid'] = true; }
        foreach (['name', 'user'] as $k) if ($r['live'][$k] === '') { $E("$id: nie udalo sie odczytac pola '$k' z pliku"); $r['invalid'] = true; }
        if (!empty($r['invalid'])) continue;
        if ($r['eff']['host'] !== $r['live']['host'] && $r['new']['port'] === '' && $r['eff']['port'] !== '' && $r['eff']['port'] !== '3306'
            && strpos($r['new']['host'], ':') === false) {
            $W("$id: zostaje stary, niestandardowy port {$r['eff']['port']} przy nowym hoscie - jesli nowa instancja ma inny port, podaj new_port (albo '-' aby wyczyscic)");
        }
        if ($r['dyn'] && ($wantConfig && row_needs_config($r))) $E("$id: wartosci dynamiczne (nie literal) - nie moge bezpiecznie podmienic: " . implode(', ', $r['dyn']) . " -> ustaw action = db-only lub manual");
        elseif ($r['dyn']) $W("$id: wartosci dynamiczne: " . implode(', ', $r['dyn']));

        $same = true;
        foreach (DB_FIELDS as $k) if ((string)$r['eff'][$k] !== (string)$r['live'][$k]) $same = false;
        if ($same) $E("$id: nowe dane (new_*) sa puste lub identyczne ze starymi - nic by sie nie zmienilo");
        if ($r['eff']['host'] === 'localhost' && $r['eff']['port'] !== '') $W("$id: new_host=localhost z portem - PHP uzyje socketu i zignoruje port; wpisz 127.0.0.1:{$r['eff']['port']}");
        if ($r['eff']['name'] === '' || $r['eff']['user'] === '') { $E("$id: nowa baza: brak nazwy lub uzytkownika"); $r['invalid'] = true; continue; }

        if ($wantConfig && row_needs_config($r)) {
            $be = build_edits($r['parsed'], $r['eff']);
            foreach ($be['warnings'] as $w) $W("$id: $w");
            foreach ($be['errors'] as $e) { $E("$id: $e"); $r['invalid'] = true; }
            if (!$be['edits']) $E("$id: brak edycji do wykonania w plikach konfiguracyjnych");
            $r['planned'] = $be;
            foreach ($r['parsed']['files'] as $f) if (!is_writable($f)) $E("$id: brak prawa zapisu do $f");
            if ($r['url'] !== '' && !Cli::has('no-http-check')) {
                $pr = http_probe($r['url']);
                $r['http_before'] = $pr;
                if ($pr['error'] || $pr['code'] === 0) $W("$id: test HTTP {$r['url']} nieudany (" . http_probe_str($pr) . ") - po zmianie nie sprawdze strony automatycznie");
                elseif ($pr['db_error']) $W("$id: strona {$r['url']} JUZ TERAZ zglasza blad bazy: " . http_probe_str($pr));
                else Log::info("$id: strona odpowiada: " . http_probe_str($pr));
            } elseif ($r['url'] === '') {
                $W("$id: brak url w mapping.conf - pomijam test HTTP po zmianie");
            }
        }
    }
    unset($r);

    list($jobs, $jerr) = build_jobs($rows);
    foreach ($jerr as $e) $E($e);

    if ($jobs && ($needOld || ($mode === 'full'))) {
        foreach (['mysqldump' => 'mysqldump_bin', 'mysql' => 'mysql_bin'] as $n => $fn) {
            try { $b = $fn(); Log::info("$n: $b (" . tool_version($b) . ")"); } catch (Throwable $e) { $E($e->getMessage()); }
        }
        if (!function_exists('mysqli_init')) $W("PHP bez rozszerzenia mysqli - uzywam klienta mysql CLI (wolniej). Sprobuj innego PHP, np. php82 dbmig.php ...");
    }

    foreach ($jobs as $key => &$j) {
        $ids = implode(', ', $j['rows']);
        if (db_key($j['old']) === db_key($j['new'])) { $E("[$key] nowa baza to ta sama baza co stara ($ids)"); continue; }
        if ($needNew) { try { $tmpDb = new Db($j['new']); $j['new_ver'] = $tmpDb->version; $tmpDb->close(); foreach ($j['rows'] as $rid) if (isset($rows[$rid]['eff'])) $rows[$rid]['eff']['server_version'] = $j['new_ver']; } catch (Throwable $e) {} }
        $st = State::db($key);
        $j['old_db'] = null; $j['new_db'] = null;
        if ($needOld && ($j['needs_db'] || $mode === 'full')) {
            try {
                $db = new Db($j['old']);
                $j['old_ver'] = $db->version; $j['old_tables'] = count($db->tables()); $j['old_size'] = $db->sizeBytes(); $j['old_cs'] = $db->charsetInfo();
                Log::ok("[$key] stara baza OK: " . $db->version . ", tabel: {$j['old_tables']}, rozmiar: " . human_bytes($j['old_size']) . ", " . $j['old_cs']);
                $db->close();
                $free = @disk_free_space(Work::p('dumps'));
                if ($free !== false && $j['needs_db'] && $free < $j['old_size'] * 1.5 + 50 * 1024 * 1024) $W("[$key] malo miejsca na dysku dla zrzutu: wolne " . human_bytes($free) . ", baza " . human_bytes($j['old_size']));
            } catch (Throwable $e) { $E("[$key] stara baza: " . $e->getMessage() . " ($ids)"); }
        }
        if ($needNew) {
            try {
                $db = new Db($j['new']);
                $nt = count($db->tables());
                $j['new_ver'] = $db->version; $j['new_tables'] = $nt;
                Log::ok("[$key] nowa baza OK: " . fmt_conn($j['new'], false) . " -> " . $db->version . ", tabel: $nt");
                $db->close();
                $importedByUs = !empty($st['imported_at']);
                if (in_array($mode, ['full', 'import'], true) && $j['needs_db'] && $nt > 0 && !$importedByUs && !Cli::has('redo')) {
                    $force ? $W("[$key] nowa baza NIE jest pusta ($nt tabel) - --force: import nadpisze tabele o tych samych nazwach") : $E("[$key] nowa baza NIE jest pusta ($nt tabel). Wyczysc ja (php dbmig.php clean-new --site ...) albo uzyj --force");
                }
                if ($nt > 0 && $importedByUs && Cli::has('redo')) $E("[$key] --redo: nowa baza ma $nt tabel - najpierw: php dbmig.php clean-new --site " . $j['rows'][0]);
                if ($mode === 'config' && $nt === 0) {
                    $force ? $W("[$key] nowa baza jest pusta, a chcesz przelaczyc na nia konfiguracje (--force)") : $E("[$key] nowa baza jest PUSTA - strona by sie wywalila. Najpierw import, albo --force");
                }
                if ($mode === 'verify' && $nt === 0) $E("[$key] nowa baza jest pusta - nie ma czego weryfikowac");
                if (isset($j['old_ver'])) {
                    $oM = stripos($j['old_ver'], 'maria') !== false; $nM = stripos($j['new_ver'], 'maria') !== false;
                    Log::info("[$key] wersje: {$j['old_ver']} -> {$j['new_ver']}");
                    if ($oM !== $nM) $W("[$key] zmiana silnika MariaDB<->MySQL - mozliwe problemy z kolacjami/JSON; sprawdz import uwaznie");
                    if (!$oM && !$nM && version_compare($j['new_ver'], $j['old_ver'], '<')) $W("[$key] nowa baza ma NIZSZA wersje niz stara");
                }
            } catch (Throwable $e) {
                $E("[$key] nowa baza " . fmt_conn($j['new']) . ": " . $e->getMessage() . " ($ids)");
            }
        }
        if ($mode === 'import' || $mode === 'verify') {
            $df = $st['dump_file'] ?? dump_filename($j['old']);
            if ($mode === 'import' && !is_file($df)) $E("[$key] brak zrzutu $df - najpierw: php dbmig.php dump --site ...");
        }
    }
    unset($j);
    foreach ($rows as $id => &$r) {
        if (empty($r['planned'])) continue;
        $hasUrl = false;
        foreach ($r['parsed']['slots'] as $sl) if ($sl['type'] === 'url' && empty($sl['dyn']) && stripos((string)$sl['v'], 'serverVersion=') !== false) $hasUrl = true;
        if (!$hasUrl) continue;
        if (!isset($r['eff']['server_version'])) $r['eff']['server_version'] = '';
        $r['planned'] = build_edits($r['parsed'], $r['eff']);
        if ($r['eff']['server_version'] !== '') Log::info("$id: serverVersion w DATABASE_URL zostanie ustawiony na " . doctrine_server_version($r['eff']['server_version']));
        else foreach ($r['planned']['warnings'] as $w) if (stripos($w, 'serverVersion') !== false) $W("$id: $w");
    }
    unset($r);
    return ['errors' => $errors, 'warnings' => $warnings, 'jobs' => $jobs];
}

function cmd_check()
{
    $rows = select_rows(load_rows(), false);
    $active = array_filter($rows, 'row_is_active');
    if (!$active) { Log::warn("Zaden wiersz nie ma action = migrate / db-only / config-only. Uzupelnij mapping.conf."); return 1; }
    Log::step("Sprawdzam " . count($active) . " wierszy (nic nie zmieniam)");
    $res = check_rows($rows, 'full');
    Log::raw('');
    if ($res['errors']) { Log::err("Bledow: {$res['errors']}, ostrzezen: {$res['warnings']}. Popraw mapping.conf / pliki i uruchom check ponownie."); return 1; }
    $todo = 0; foreach ($rows as $r) if (row_is_active($r) && empty($r['invalid'])) $todo++;
    Log::ok("Wszystko gotowe (ostrzezen: {$res['warnings']}). Zadan bazodanowych: " . count($res['jobs']) . ", wierszy do zrobienia: $todo" . ($todo < count($active) ? " (juz zmigrowanych: " . (count($active) - $todo) . ")" : ''));
    return 0;
}

function cmd_show()
{
    $rows = select_rows(load_rows(), false);
    foreach ($rows as $id => $r) {
        row_prepare($r);
        Log::raw("== $id  [{$r['cms']}]  action={$r['action']}");
        Log::raw("   plik: {$r['file']}" . ($r['url'] ? "   url: {$r['url']}" : ''));
        if ($r['error']) { Log::raw("   BLAD: " . $r['error']); continue; }
        Log::raw("   stara baza: " . fmt_conn($r['live']));
        Log::raw("   nowa baza:  " . fmt_conn($r['eff']));
        foreach ($r['parsed']['slots'] as $s) {
            $v = $s['dyn'] ? '<dynamiczne>' : (in_array($s['type'], ['pass', 'url', 'dsn'], true) && !Cli::has('show-secrets') ? mask($s['v']) : $s['v']);
            Log::raw(sprintf("   slot %-9s %-22s %s:%d = %s", $s['type'], $s['label'], basename($s['file']), $s['line'], $v));
        }
        foreach ($r['parsed']['notes'] as $n) Log::raw("   uwaga: $n");
        $be = build_edits($r['parsed'], $r['eff']);
        foreach ($be['edits'] as $f => $list) foreach ($list as $e) {
            $o = in_array($e['label'], ['DB_PASSWORD', 'PDO pass', "'password'", '$password', '$CFG->dbpass', "'database_password'", 'database_password', 'DATABASE_URL', 'PDO DSN'], true) || stripos($e['label'], 'pass') !== false;
            Log::raw("   zmiana " . basename($f) . ":{$e['line']} {$e['label']}: " . ($o ? mask($e['old']) : $e['old']) . "  ->  " . ($o ? mask($e['new']) : $e['new']));
        }
        foreach ($be['warnings'] as $w) Log::raw("   OSTRZEZENIE: $w");
        foreach ($be['errors'] as $w) Log::raw("   BLAD: $w");
        $st = State::row($id);
        if ($st) Log::raw("   stan: " . json_encode($st, JSON_UNESCAPED_SLASHES));
        Log::raw('');
    }
    return 0;
}

// ---------- operacje ----------

function job_dump(array &$j)
{
    $key = $j['key'];
    $st = State::db($key);
    $df = dump_filename($j['old']);
    if (!Cli::has('redo') && !empty($st['dumped_at']) && is_file($st['dump_file'] ?? '')) {
        Log::info("[$key] zrzut juz istnieje z {$st['dumped_at']}: {$st['dump_file']} (" . human_bytes(filesize($st['dump_file'])) . ") - pomijam (--redo aby powtorzyc)");
        return $st['dump_file'];
    }
    Log::step("[$key] mysqldump " . fmt_conn($j['old'], false) . " -> $df");
    $t0 = microtime(true);
    $r = do_dump($j['old'], $df, $j['new']['name']);
    State::setDb($key, ['dumped_at' => date('c'), 'dump_file' => $df, 'dump_bytes' => $r['bytes'], 'imported_at' => null, 'verified_at' => null,
        'routines_skipped' => $r['routines_skipped'], 'events_skipped' => $r['events_skipped']]);
    Log::ok(sprintf("[$key] zrzut gotowy: %s w %.1fs; usunieto DEFINER: %d; podmieniono odwolan `%s`. -> `%s`.: %d",
        human_bytes($r['bytes']), microtime(true) - $t0, $r['definers'], $j['old']['name'], $j['new']['name'], $r['renamed']));
    return $df;
}

function job_import(array &$j, $df)
{
    $key = $j['key'];
    $st = State::db($key);
    if (!Cli::has('redo') && !empty($st['imported_at'])) {
        Log::info("[$key] import juz wykonany {$st['imported_at']} - pomijam (--redo aby powtorzyc po clean-new)");
        return;
    }
    $db = new Db($j['new']);
    $nt = count($db->tables());
    $db->close();
    if ($nt > 0 && !Cli::has('force')) throw new DbmigError("[$key] nowa baza nie jest pusta ($nt tabel) - przerywam import. Wyczysc: php dbmig.php clean-new --site {$j['rows'][0]}");
    Log::step("[$key] import " . human_bytes(filesize($df)) . " -> " . fmt_conn($j['new'], false));
    $t0 = microtime(true);
    do_import($j['new'], $df);
    State::setDb($key, ['imported_at' => date('c'), 'verified_at' => null]);
    Log::ok(sprintf("[$key] import zakonczony w %.1fs", microtime(true) - $t0));
}

function job_verify(array &$j)
{
    $key = $j['key'];
    Log::step("[$key] weryfikacja: tabele i liczba wierszy");
    $o = new Db($j['old']); $n = new Db($j['new']);
    $v = do_verify($o, $n, Cli::has('fast-verify'));
    $o->close(); $n->close();
    $ok = true;
    if ($v['missing']) { Log::err("[$key] BRAK w nowej bazie tabel: " . implode(', ', array_slice($v['missing'], 0, 20)) . (count($v['missing']) > 20 ? ' ...' : '')); $ok = false; }
    if ($v['extra']) Log::warn("[$key] nowa baza ma dodatkowe tabele: " . implode(', ', array_slice($v['extra'], 0, 20)));
    if ($v['diff']) {
        $lines = array_map(function ($d) { return "{$d[0]} ({$d[1]} -> {$d[2]})"; }, array_slice($v['diff'], 0, 15));
        $msg = "[$key] rozna liczba wierszy w " . count($v['diff']) . " tabelach: " . implode(', ', $lines) . (count($v['diff']) > 15 ? ' ...' : '');
        if (Cli::has('strict-verify')) { Log::err($msg); $ok = false; }
        else Log::warn($msg . "  (strona dziala w trakcie migracji? sesje/logi/cache - OK; duze roznice = problem; --strict-verify aby traktowac jako blad)");
    }
    State::setDb($key, ['verified_at' => date('c'), 'verify_ok' => $ok, 'verify' => ['missing' => count($v['missing']), 'extra' => count($v['extra']), 'diff' => count($v['diff'])]]);
    if ($ok) Log::ok("[$key] weryfikacja OK: tabel {$v['tables']}, widokow {$v['views']}" . ($v['diff'] ? ", roznic w liczbie wierszy: " . count($v['diff']) : ''));
    if (!$ok) throw new DbmigError("[$key] weryfikacja nieudana - konfiguracja NIE zostanie zmieniona");
}

function row_update_config(array $r, $dryRun = false)
{
    $id = $r['id'];
    $be = $r['planned'] ?? build_edits($r['parsed'], $r['eff']);
    foreach ($be['warnings'] as $w) Log::warn("$id: $w");
    if ($be['errors']) throw new DbmigError("$id: " . implode('; ', $be['errors']));
    if (!$be['edits']) { Log::info("$id: brak zmian do wykonania"); return; }
    $secret = function ($e) { return stripos($e['label'], 'pass') !== false || in_array($e['label'], ['DATABASE_URL', 'PDO DSN'], true); };
    foreach ($be['edits'] as $f => $list) foreach ($list as $e) {
        Log::info("$id: " . ($dryRun ? '[dry-run] ' : '') . "$f:{$e['line']} {$e['label']}: " . ($secret($e) ? mask($e['old']) : $e['old']) . "  ->  " . ($secret($e) ? mask($e['new']) : $e['new']));
    }
    if ($dryRun) return;

    $httpCheck = $r['url'] !== '' && !Cli::has('no-http-check');
    $before = $r['http_before'] ?? ($httpCheck ? http_probe($r['url']) : null);
    if ($before && ($before['error'] || $before['code'] === 0)) { Log::warn("$id: strona nie odpowiada przed zmiana (" . http_probe_str($before) . ") - pomijam test HTTP"); $httpCheck = false; }

    $files = array_keys($be['edits']);
    $bdir = backup_files($id, $files);
    Log::info("$id: backup plikow -> $bdir");
    $written = [];
    try {
        foreach ($be['edits'] as $f => $list) {
            $c = (string)file_get_contents($f);
            $nc = apply_edits_to_content($c, $list);
            write_file_atomic($f, $nc);
            PhpFile::forget($f);
            $written[] = $f;
        }
        State::setRow($id, ['config_updated_at' => date('c'), 'backup_dir' => $bdir, 'files' => $files, 'rolled_back_at' => null]);
        foreach ($written as $f) {
            if (substr($f, -4) === '.php') {
                $l = php_lint($f);
                if ($l !== true) throw new DbmigError("php -l: $l");
            }
        }
        $rp = parse_by_cms($r['cms'], $r['file']);
        $fs = $rp ? fields_from_slots($rp['slots']) : null;
        if (!$fs) throw new DbmigError("po zapisie nie moge ponownie odczytac konfiguracji");
        foreach (DB_FIELDS as $k) {
            if ((string)$fs['fields'][$k] !== (string)$r['eff'][$k]) throw new DbmigError("po zapisie pole '$k' odczytane z pliku nie zgadza sie z oczekiwanym (" . ($k === 'pass' ? mask($fs['fields'][$k]) : $fs['fields'][$k]) . ")");
        }
        Log::ok("$id: pliki zapisane, skladnia OK, wartosci odczytane poprawnie");
        if ($httpCheck) {
            // opcache (opcache.revalidate_freq) moze przez chwile serwowac stara konfiguracje - odczekaj
            $delay = max(0, (int)Cli::get('http-delay', 3));
            if ($delay) sleep($delay);
            $after = http_probe($r['url']);
            $broken = ($after['db_error'] && !$before['db_error']) || ($after['code'] >= 500 && $before['code'] < 500) || ($after['code'] === 0 && $before['code'] !== 0);
            if ($broken) throw new DbmigError("strona po zmianie: " . http_probe_str($after) . " (przed: " . http_probe_str($before) . ")");
            Log::ok("$id: strona odpowiada po zmianie: " . http_probe_str($after));
        }
    } catch (Throwable $e) {
        Log::err("$id: " . $e->getMessage());
        if (Cli::has('no-auto-rollback')) throw new DbmigError("$id: konfiguracja ZOSTALA zmieniona, bez rollbacku (--no-auto-rollback). Przywroc: php dbmig.php rollback --site $id");
        restore_backup($bdir);
        State::setRow($id, ['config_updated_at' => null, 'rolled_back_at' => date('c'), 'last_error' => $e->getMessage()]);
        Log::warn("$id: przywrocono pliki z backupu $bdir (strona dziala na STAREJ bazie)");
        throw new DbmigError("$id: zmiana konfiguracji wycofana");
    }
}

function run_jobs(array &$rows, array $jobs, $doDb, $doConfig, $dryRun)
{
    $failed = 0; $done = 0;
    foreach ($jobs as $key => $j) {
        try {
            if ($doDb && $j['needs_db']) {
                if ($dryRun) {
                    Log::info("[dry-run] [$key] dump " . fmt_conn($j['old'], false) . " -> " . dump_filename($j['old']) . " -> import do " . fmt_conn($j['new'], false) . " -> weryfikacja");
                } else {
                    $df = job_dump($j);
                    job_import($j, $df);
                    job_verify($j);
                }
            }
            if ($doConfig) {
                foreach ($j['rows'] as $id) {
                    if (!row_needs_config($rows[$id])) continue;
                    row_update_config($rows[$id], $dryRun);
                }
            }
            $done++;
        } catch (Throwable $e) {
            $failed++;
            Log::err("[$key] PRZERWANO: " . $e->getMessage());
            if (!Cli::has('continue-on-error')) { Log::err("Zatrzymuje sie na pierwszym bledzie (--continue-on-error aby kontynuowac z kolejnymi bazami)."); break; }
        }
    }
    return [$done, $failed];
}

function prepare_active($mode)
{
    $rows = select_rows(load_rows());
    $active = array_filter($rows, 'row_is_active');
    if (!$active) fail("Wybrane wiersze nie maja action = migrate / db-only / config-only (sprawdz mapping.conf).");
    Log::step("Sprawdzanie wstepne (" . count($active) . " wierszy)");
    $res = check_rows($active, $mode);
    if ($res['errors'] && !Cli::has('skip-check')) fail("Bledow: {$res['errors']} - przerywam. Popraw i uruchom ponownie (check pokazuje szczegoly).");
    if (!$res['jobs']) fail("Nic do zrobienia (wszystkie wybrane wiersze sa juz zmigrowane lub pominiete).");
    return [$active, $res['jobs']];
}

function print_plan(array $rows, array $jobs, $doDb, $doConfig)
{
    Log::raw('');
    Log::raw("PLAN:");
    foreach ($jobs as $key => $j) {
        Log::raw("  stara baza " . fmt_conn($j['old'], false) . "  ->  nowa " . fmt_conn($j['new'], false) . (isset($j['old_size']) ? "  [" . human_bytes($j['old_size']) . ", " . $j['old_tables'] . " tabel]" : ''));
        if ($doDb && $j['needs_db']) Log::raw("      dump -> import -> weryfikacja");
        if ($doConfig) foreach ($j['rows'] as $id) if (row_needs_config($rows[$id])) Log::raw("      konfiguracja: {$rows[$id]['file']}" . ($rows[$id]['url'] ? "  (test: {$rows[$id]['url']})" : ''));
    }
    Log::raw('');
}

function cmd_migrate()
{
    $dry = Cli::has('dry-run');
    list($rows, $jobs) = prepare_active('full');
    print_plan($rows, $jobs, true, true);
    if (!$dry && !confirm("Wykonac migracje " . count($jobs) . " baz / " . count($rows) . " konfiguracji?")) { Log::warn("Anulowano."); return 1; }
    list($done, $failed) = run_jobs($rows, $jobs, true, true, $dry);
    Log::raw('');
    if ($dry) { Log::ok("[dry-run] nic nie zmieniono. Zadan: " . count($jobs)); return 0; }
    if ($failed) { Log::err("Zakonczono: OK $done, bledy $failed. Szczegoly: " . Log::$file); return 1; }
    Log::ok("Zakonczono: $done zadan OK. Sprawdz strony w przegladarce. Stare bazy NIE zostaly ruszone - skasuj je w panelu dopiero po testach.");
    return 0;
}

function cmd_dump()
{
    list($rows, $jobs) = prepare_active('dump');
    $jobs = array_filter($jobs, function ($j) { return $j['needs_db']; });
    if (!$jobs) fail("Brak zadan z action = migrate / db-only");
    print_plan($rows, $jobs, true, false);
    list($done, $failed) = [0, 0];
    foreach ($jobs as $key => $j) {
        try { if (Cli::has('dry-run')) Log::info("[dry-run] dump $key -> " . dump_filename($j['old'])); else job_dump($j); $done++; }
        catch (Throwable $e) { $failed++; Log::err("[$key] " . $e->getMessage()); if (!Cli::has('continue-on-error')) break; }
    }
    return $failed ? 1 : 0;
}

function cmd_import()
{
    list($rows, $jobs) = prepare_active('import');
    $jobs = array_filter($jobs, function ($j) { return $j['needs_db']; });
    if (!$jobs) fail("Brak zadan z action = migrate / db-only");
    print_plan($rows, $jobs, true, false);
    if (!Cli::has('dry-run') && !confirm("Zaimportowac zrzuty do " . count($jobs) . " nowych baz?")) return 1;
    list($done, $failed) = [0, 0];
    foreach ($jobs as $key => $j) {
        try {
            $df = State::db($key)['dump_file'] ?? dump_filename($j['old']);
            if (Cli::has('dry-run')) Log::info("[dry-run] import $df -> " . fmt_conn($j['new'], false));
            else { job_import($j, $df); job_verify($j); }
            $done++;
        } catch (Throwable $e) { $failed++; Log::err("[$key] " . $e->getMessage()); if (!Cli::has('continue-on-error')) break; }
    }
    return $failed ? 1 : 0;
}

function cmd_verify()
{
    list($rows, $jobs) = prepare_active('verify');
    $failed = 0;
    foreach ($jobs as $key => $j) {
        try { job_verify($j); } catch (Throwable $e) { $failed++; Log::err($e->getMessage()); }
    }
    return $failed ? 1 : 0;
}

function cmd_update_config()
{
    $dry = Cli::has('dry-run');
    list($rows, $jobs) = prepare_active('config');
    print_plan($rows, $jobs, false, true);
    if (!$dry && !confirm("Podmienic dane dostepowe w plikach konfiguracyjnych (" . count($rows) . ")?")) return 1;
    list($done, $failed) = run_jobs($rows, $jobs, false, true, $dry);
    if ($dry) { Log::ok("[dry-run] nic nie zmieniono."); return 0; }
    return $failed ? 1 : 0;
}

function cmd_rollback()
{
    $rows = select_rows(load_rows());
    $plan = [];
    foreach ($rows as $id => $r) {
        $dir = Cli::has('backup') ? Work::p("backups/$id/" . Cli::get('backup')) : latest_backup_dir($id);
        if (!$dir || !is_file("$dir/manifest.json")) { Log::warn("$id: brak backupu do przywrocenia"); continue; }
        $m = json_decode((string)file_get_contents("$dir/manifest.json"), true);
        Log::info("$id: przywroce z $dir (" . date('Y-m-d H:i:s', strtotime($m['created'])) . "): " . implode(', ', array_column($m['files'], 'orig')));
        $plan[$id] = $dir;
    }
    if (!$plan) return 1;
    if (Cli::has('dry-run')) { Log::ok("[dry-run] nic nie zmieniono"); return 0; }
    if (!confirm("Przywrocic pliki konfiguracyjne dla " . count($plan) . " wierszy? (strony wroca na STARE bazy)")) return 1;
    $failed = 0;
    foreach ($plan as $id => $dir) {
        try {
            $files = restore_backup($dir);
            foreach ($files as $f) if (substr($f, -4) === '.php') { $l = php_lint($f); if ($l !== true) Log::warn("$id: php -l po przywroceniu: $l"); }
            State::setRow($id, ['config_updated_at' => null, 'rolled_back_at' => date('c')]);
            Log::ok("$id: przywrocono " . count($files) . " plik(ow)");
            if ($rows[$id]['url'] !== '' && !Cli::has('no-http-check')) {
                $delay = max(0, (int)Cli::get('http-delay', 3));
                if ($delay) sleep($delay);
                Log::info("$id: strona po przywroceniu: " . http_probe_str(http_probe($rows[$id]['url'])));
            }
        } catch (Throwable $e) { $failed++; Log::err("$id: " . $e->getMessage()); }
    }
    return $failed ? 1 : 0;
}

function cmd_clean_new()
{
    $rows = select_rows(load_rows());
    $active = array_filter($rows, 'row_is_active');
    foreach ($active as &$r) { row_prepare($r); if ($r['error']) fail($r['id'] . ': ' . $r['error']); }
    unset($r);
    list($jobs, $jerr) = build_jobs($active);
    foreach ($jerr as $e) Log::err($e);
    if (!$jobs || $jerr) fail("Brak poprawnych zadan.");
    $failed = 0;
    foreach ($jobs as $key => $j) {
        if (db_key($j['old']) === db_key($j['new'])) { Log::err("[$key] nowa baza == stara baza - ODMAWIAM"); $failed++; continue; }
        foreach ($j['rows'] as $id) {
            $st = State::row($id);
            if (!empty($st['config_updated_at']) && !Cli::has('force')) { Log::err("[$key] konfiguracja $id juz wskazuje na nowa baze - czyszczenie wylaczyloby strone. Najpierw rollback, albo --force"); continue 2; }
        }
        try {
            $db = new Db($j['new']);
            $t = $db->tables();
            if (!count($t)) { Log::info("[$key] nowa baza " . fmt_conn($j['new'], false) . " jest juz pusta"); $db->close(); continue; }
            Log::warn("[$key] nowa baza " . fmt_conn($j['new'], false) . " ma " . count($t) . " tabel - zostana USUNIETE (stara baza nietknieta)");
            if (!Cli::has('dry-run') && !confirm("Potwierdz wpisujac nazwe nowej bazy", $j['new']['name'])) { Log::warn("pominieto"); $db->close(); continue; }
            if (Cli::has('dry-run')) { $db->close(); continue; }
            $n = $db->dropAllTables();
            $db->close();
            State::setDb($key, ['imported_at' => null, 'verified_at' => null, 'cleaned_at' => date('c')]);
            Log::ok("[$key] usunieto $n tabel/widokow z nowej bazy");
        } catch (Throwable $e) { $failed++; Log::err("[$key] " . $e->getMessage()); }
    }
    return $failed ? 1 : 0;
}

function cmd_status()
{
    $rows = select_rows(load_rows(), false);
    State::load();
    $t = [];
    foreach ($rows as $id => $r) {
        $key = db_key($r['map_old']);
        $sd = State::db($key); $sr = State::row($id);
        $t[] = [$id, $r['cms'], $r['action'], fmt_conn($r['map_old'], false), $r['new']['name'] !== '' ? fmt_conn(['host' => $r['new']['host'], 'port' => $r['new']['port'], 'name' => $r['new']['name']], false) : '',
            !empty($sd['dumped_at']) ? substr($sd['dumped_at'], 0, 16) : '', !empty($sd['imported_at']) ? substr($sd['imported_at'], 0, 16) : '',
            isset($sd['verify_ok']) ? ($sd['verify_ok'] ? 'OK' : 'BLAD') : '',
            !empty($sr['config_updated_at']) ? substr($sr['config_updated_at'], 0, 16) : (!empty($sr['rolled_back_at']) ? 'rollback ' . substr($sr['rolled_back_at'], 0, 16) : '')];
    }
    print_table(['id', 'cms', 'action', 'stara baza', 'nowa baza', 'dump', 'import', 'weryf.', 'konfiguracja'], $t);
    Log::raw('');
    Log::raw("workdir: " . Work::$dir . "   mapping: " . Work::mappingFile() . "   log: " . Log::$file);
    return 0;
}

function cmd_reset()
{
    $rows = select_rows(load_rows());
    if (!confirm("Skasowac stan (state.json) dla " . count($rows) . " wierszy? Pliki zrzutow i backupow zostaja.")) return 1;
    foreach ($rows as $id => $r) { State::unsetRow($id); State::unsetDb(db_key($r['map_old'])); Log::ok("$id: stan wyczyszczony"); }
    return 0;
}

// =====================================================================
//  plan (generowanie nowych nazw/haseł) + create-db (API DirectAdmin)
// =====================================================================

/** znaki hasla bezpieczne w URL (DATABASE_URL) i w plikach .env: bez % + = & # $ ; , / ? @ : ' " \ */
const PASS_URLSAFE_RE = '/^[A-Za-z0-9!*_.~-]+$/';

function gen_password($len = 20)
{
    // ostatni zestaw gwarantuje znak specjalny akceptowany przez panele ("!@#$ itp.")
    $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghjkmnpqrstuvwxyz', '23456789', '!*'];
    $all = implode('', $sets) . '-_.';
    $out = [];
    foreach ($sets as $s) $out[] = $s[random_int(0, strlen($s) - 1)];          // po jednym z kazdej klasy
    while (count($out) < $len) $out[] = $all[random_int(0, strlen($all) - 1)];
    // tasowanie (Fisher-Yates z random_int)
    for ($i = count($out) - 1; $i > 0; $i--) { $j = random_int(0, $i); $t = $out[$i]; $out[$i] = $out[$j]; $out[$j] = $t; }
    return implode('', $out);
}

/** nazwa konta hostingowego = prefiks baz w DirectAdmin (konto_...) */
function da_account()
{
    $u = Cli::get('da-user');
    if ($u) return $u;
    if (($e = getenv('USER')) && $e !== 'root') return $e;
    if (($h = getenv('HOME')) && basename($h) !== 'root' && basename($h) !== '') return basename($h);
    if (function_exists('posix_geteuid')) { $pw = @posix_getpwuid(posix_geteuid()); if (!empty($pw['name'])) return $pw['name']; }
    return '';
}

function da_password()
{
    static $pw = null;
    if ($pw !== null) return $pw;
    $pw = (string)(Cli::get('da-pass') ?: getenv('DA_PASS'));
    if ($pw !== '') return $pw;
    if (function_exists('posix_isatty') && !posix_isatty(STDIN)) throw new DbmigError("Brak hasla do panelu: podaj --da-pass=... albo zmienna DA_PASS (albo uruchom interaktywnie)");
    fwrite(STDOUT, "Haslo do panelu DirectAdmin dla '" . da_account() . "' (nie bedzie widoczne): ");
    @shell_exec('stty -echo 2>/dev/null');
    $pw = rtrim((string)fgets(STDIN), "\r\n");
    @shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");
    if ($pw === '') throw new DbmigError("Puste haslo");
    return $pw;
}

/** adres panelu: --da-url / DA_URL, a bez nich probowanie typowych adresow (wynik zapamietany w state.json) */
function da_base_url()
{
    static $base = null;
    if ($base !== null) return $base;
    $given = (string)(Cli::get('da-url') ?: getenv('DA_URL'));
    if ($given !== '') return $base = rtrim($given, '/');
    State::load();
    if (!empty(State::$d['da_url'])) return $base = State::$d['da_url'];
    $hosts = ['127.0.0.1'];
    $fq = trim((string)@shell_exec('hostname -f 2>/dev/null'));
    if ($fq !== '') $hosts[] = $fq;
    $sh = trim((string)@shell_exec('hostname 2>/dev/null'));
    if ($sh !== '' && $sh !== $fq) $hosts[] = $sh;
    $cands = [];
    foreach ([2223, 2222] as $port) foreach ($hosts as $h) $cands[] = "https://$h:$port";
    $cands[] = 'http://127.0.0.1:2222';
    Log::info("Szukam panelu DirectAdmin (podaj --da-url=https://host:port aby pominac): " . implode(', ', $cands));
    foreach ($cands as $c) {
        if (da_probe($c)) {
            Log::ok("Panel odpowiada pod $c (zapamietane w state.json)");
            State::$d['da_url'] = $c; State::save();
            return $base = $c;
        }
    }
    throw new DbmigError("Zaden z typowych adresow panelu nie odpowiada. Podaj --da-url=<adres z paska przegladarki, np. https://sXX.cyber-folks.pl:2223> (bez sciezki) albo DA_URL=...");
}

/** czy pod adresem cos odpowiada po HTTP (krotki timeout) */
function da_probe($base)
{
    if (function_exists('curl_init') && !Cli::has('curl-cli')) {
        $ch = curl_init($base . '/');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_USERAGENT => 'dbmig/' . DBMIG_VERSION]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $e = curl_errno($ch);
        curl_close($ch);
        return $e === 0 && $code > 0;
    }
    $curl = which(Cli::get('curl-bin', 'curl'));
    if (!$curl) return false;
    $r = run_proc([$curl, '-s', '-k', '-I', '--connect-timeout', '4', '--max-time', '8', '-o', '/dev/null', '-w', '%{http_code}', $base . '/']);
    return $r['code'] === 0 && (int)trim($r['out']) > 0;
}

/** zapytanie do legacy API DirectAdmin; zwraca ['code'=>http, 'data'=>parse_str, 'body'=>] */
function da_request($cmd, array $post = null)
{
    $base = da_base_url();
    $url = $base . '/' . ltrim($cmd, '/');
    $user = da_account(); $pass = da_password();
    if ($user === '') throw new DbmigError("Nie znam nazwy konta DirectAdmin - podaj --da-user=login");
    $body = ''; $code = 0; $err = null;
    if (function_exists('curl_init') && !Cli::has('curl-cli')) {
        $ch = curl_init($url);
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERPWD => $user . ':' . $pass, CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERAGENT => 'dbmig/' . DBMIG_VERSION];
        if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
        curl_setopt_array($ch, $o);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) $err = curl_error($ch);
        curl_close($ch);
    } else {
        $curl = which(Cli::get('curl-bin', 'curl'));
        if (!$curl) throw new DbmigError("Brak curl (rozszerzenie PHP i program) - nie moge wywolac API");
        $netrc = Tmp::file('netrc', "machine " . parse_url($base, PHP_URL_HOST) . " login $user password " . str_replace(' ', '\\ ', $pass) . "\n");
        $args = [$curl, '-sS', '-k', '--max-time', '90', '--netrc-file', $netrc, '-o', '-', '-w', "\n%{http_code}", '-A', 'dbmig/' . DBMIG_VERSION];
        if ($post !== null) { $args[] = '--data'; $args[] = http_build_query($post); }
        $args[] = $url;
        $r = run_proc($args);
        if ($r['code'] !== 0) $err = trim($r['err']);
        $p = strrpos($r['out'], "\n"); $code = (int)substr($r['out'], $p + 1); $body = substr($r['out'], 0, $p);
    }
    if ($err) throw new DbmigError("API DirectAdmin ($url): $err");
    if ($code === 401) throw new DbmigError("API DirectAdmin: 401 - zle haslo/login (konto '$user'); mozesz uzyc klucza logowania (Login Keys) jako hasla");
    if ($code === 0 || $code >= 500) throw new DbmigError("API DirectAdmin ($url): HTTP $code " . substr(strip_tags($body), 0, 200));
    $data = [];
    parse_str($body, $data);
    if (strpos($cmd, 'CMD_API_') === 0 && stripos($body, '<html') !== false && !isset($data['error'])) throw new DbmigError("API DirectAdmin zwrocilo HTML zamiast odpowiedzi API (zly --da-url? logowanie przez SSO?)");
    return ['code' => $code, 'data' => $data, 'body' => $body];
}

/** gotowe ustawienia wtyczek hostingow: [cmd, szablon pol, czy wymaga --da-instance, sciezka listy] */
function da_preset()
{
    $name = strtolower((string)(Cli::get('da-preset') ?: getenv('DA_PRESET')));
    if ($name === '') return null;
    $presets = [
        // cyber_Folks: wtyczka "Zarzadzanie MySQL" (DirectAdmin CMD_PLUGINS/mysql); instance: 1 = MariaDB 10.6 (127.0.0.1:3307, legacy), 2 = MariaDB 10.11 (127.0.0.1:3308)
        'cyberfolks' => ['cmd' => 'CMD_PLUGINS/mysql/actions/db_create.php',
            'fields' => 'action=create&instance={instance}&name={name}&user={user}&userlist=...&passwd={pass}&passwd2={pass}&comment={comment}&create=Utw%C3%B3rz',
            'needs_instance' => true, 'list' => 'CMD_PLUGINS/mysql', 'passwd' => 'CMD_PLUGINS/mysql/actions/db_user_password.php',
            'hint' => "--da-instance: numer z listy 'Instancja MySQL' w panelu (zwykle 1 = stara instancja, np. MariaDB 10.6; 2 = nowa, np. MariaDB 10.11 - sprawdz w swoim panelu); w new_host wpisz host:port z nawiasu przy instancji, np. 127.0.0.1:3308"],
    ];
    if (!isset($presets[$name])) throw new DbmigError("Nieznany --da-preset=$name (dostepne: " . implode(', ', array_keys($presets)) . ")");
    return $presets[$name];
}

function da_extra_fields()
{
    $x = [];
    parse_str((string)(Cli::get('da-extra') ?: getenv('DA_EXTRA')), $x);
    return $x;
}

function cmd_plan()
{
    $mf = Work::mappingFile();
    $m = mapping_read($mf);
    if (!$m['rows']) fail("Brak wierszy w $mf - najpierw: php dbmig.php scan");
    $rows = select_rows(load_rows());
    $suffix = (string)Cli::get('suffix', '_new');
    $newHost = (string)Cli::get('new-host', '');
    $action = strtolower((string)Cli::get('action', 'migrate'));
    if (!in_array($action, ACTIONS, true)) fail("--action musi byc jednym z: " . implode(', ', ACTIONS));
    $prefix = da_account() !== '' ? da_account() . '_' : '';
    $byDb = []; $table = []; $n = 0; $skipped = 0;
    foreach ($m['rows'] as $id0 => $r0) {   // juz uzupelnione wiersze: ta sama stara baza -> te same nowe dane
        if (($r0['new_name'] ?? '') === '' || ($r0['old_name'] ?? '') === '') continue;
        $k0 = db_key(['host' => $r0['old_host'] ?? '', 'port' => $r0['old_port'] ?? '', 'name' => $r0['old_name'], 'socket' => $r0['old_socket'] ?? '']);
        if (!isset($byDb[$k0])) $byDb[$k0] = ['name' => $r0['new_name'], 'user' => $r0['new_user'] ?? $r0['new_name'], 'pass' => $r0['new_pass'] ?? '', 'reused' => true];
    }
    foreach ($rows as $id => $r) {
        $raw =& $m['rows'][$id];
        $old = $r['map_old'];
        $notes = (string)($raw['notes'] ?? '');
        if ($old['name'] === '' || strpos($notes, 'wartosc dynamiczna') !== false) {
            if (!Cli::has('keep-action')) $raw['action'] = 'manual';
            Log::warn("$id: brak danych bazy albo wartosci dynamiczne - action = manual");
            unset($raw); $skipped++; continue;
        }
        if (($raw['new_name'] ?? '') !== '' && !Cli::has('overwrite')) {
            if ($newHost !== '' && ($raw['new_host'] ?? '') !== $newHost) { $raw['new_host'] = $newHost; Log::info("$id: new_* juz uzupelnione - ustawiam tylko new_host = $newHost"); $n++; }
            else { Log::info("$id: new_* juz uzupelnione - zostawiam (--overwrite aby nadpisac)"); $skipped++; }
            unset($raw); continue;
        }
        $key = db_key($old);
        if (!isset($byDb[$key])) {
            $name = $old['name'] . $suffix;
            $user = ($old['user'] !== '' ? $old['user'] : $old['name']) . $suffix;
            if ($prefix !== '' && strpos($name, $prefix) !== 0) Log::warn("$id: nazwa '$name' nie zaczyna sie od prefiksu konta '$prefix' - create-db przez API nie zadziala dla tej bazy");
            if (strlen($name) > 64) Log::warn("$id: nazwa bazy '$name' > 64 znakow");
            if (strlen($user) > 32) Log::warn("$id: uzytkownik '$user' > 32 znakow (limit MySQL 8; MariaDB pozwala 80) - skroc recznie");
            $byDb[$key] = ['name' => $name, 'user' => $user, 'pass' => gen_password(20)];
        }
        $raw['new_name'] = $byDb[$key]['name']; $raw['new_user'] = $byDb[$key]['user']; $raw['new_pass'] = $byDb[$key]['pass'];
        if (!empty($byDb[$key]['reused'])) Log::info("$id: ta sama stara baza co inny wiersz - uzywam jego new_* ({$byDb[$key]['name']})");
        if ($newHost !== '') $raw['new_host'] = $newHost;
        $stale = strpos($notes, 'polaczenie:') !== false;
        if (!Cli::has('keep-action')) $raw['action'] = $stale ? 'manual' : $action;
        if ($stale) Log::warn("$id: stare dane nie dzialaly przy skanie (patrz notes) - action = manual");
        $table[] = [$id, $old['name'], $raw['new_name'], $raw['new_user'], mask($raw['new_pass']), $raw['new_host'] !== '' ? $raw['new_host'] : '(bez zmian)', $raw['action']];
        $n++;
        unset($raw);
    }
    if (Cli::has('dry-run')) { print_table(['id', 'stara baza', 'new_name', 'new_user', 'new_pass', 'new_host', 'action'], $table); Log::ok("[dry-run] nic nie zapisano"); return 0; }
    mapping_write($mf, $m['defaults'], $m['rows']);
    print_table(['id', 'stara baza', 'new_name', 'new_user', 'new_pass', 'new_host', 'action'], $table);
    Log::ok("Zapisano $mf: uzupelniono $n wierszy (" . count($byDb) . " nowych baz), pominieto $skipped. Przejrzyj plik: martwe kopie stron ustaw na action = skip.");
    Log::raw("Nastepnie: php dbmig.php create-db --all   (zaklada bazy przez API DirectAdmin)  albo zaloz je recznie w panelu wg tej tabeli.");
    return 0;
}

function cmd_create_db()
{
    if (Cli::has('list') && ($preset = da_preset()) && !empty($preset['list'])) {
        $r = da_request($preset['list']);
        $prefix = da_account() . '_';
        $txt = preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $r['body'])));
        if (!preg_match_all('/\b(' . preg_quote($prefix, '/') . '[A-Za-z0-9_]+)\b(?:\s+(legacy|WAF))?(?:\s+((?:MariaDB|MySQL)\s+[0-9.]+))?/', $txt, $mm, PREG_SET_ORDER)) fail("Nie znalazlem baz z prefiksem $prefix w odpowiedzi " . $preset['list'] . " (HTTP {$r['code']})");
        $seen = []; $rows = [];
        foreach ($mm as $x) { if (isset($seen[$x[1]])) continue; $seen[$x[1]] = true; $rows[] = [$x[1], $x[2] ?? '', $x[3] ?? '']; }
        print_table(['baza', 'oznaczenie', 'instancja'], $rows);
        Log::ok(count($rows) . " baz na koncie wg wtyczki");
        return 0;
    }
    if (Cli::has('list')) {
        $r = da_request('CMD_API_DATABASES');
        $list = $r['data']['list'] ?? [];
        if (isset($r['data']['error']) && (string)$r['data']['error'] !== '0') fail("API: " . ($r['data']['text'] ?? '') . ' ' . ($r['data']['details'] ?? ''));
        Log::raw("Bazy na koncie (" . count($list) . "):");
        foreach ($list as $d) Log::raw("  $d");
        return 0;
    }
    $rows = select_rows(load_rows());
    $prefix = da_account() . '_';
    $extra = da_extra_fields();
    $todo = []; $errors = 0;
    foreach ($rows as $id => $r) {
        if (!row_is_active($r) || $r['new']['name'] === '') continue;
        $n = $r['new'];
        $sig = $n['name'] . '|' . $n['user'];
        if (isset($todo[$sig])) { $todo[$sig]['rows'][] = $id; if ($todo[$sig]['pass'] !== $n['pass']) { Log::err("$id: inne haslo dla tej samej nowej bazy {$n['name']} niz w " . $todo[$sig]['rows'][0]); $errors++; } continue; }
        if (strpos($n['name'], $prefix) !== 0 || strpos($n['user'] !== '' ? $n['user'] : $n['name'], $prefix) !== 0) { Log::err("$id: new_name/new_user musza zaczynac sie od '$prefix' (DirectAdmin dodaje prefiks konta) - popraw mapping.conf albo --da-user"); $errors++; continue; }
        if ($n['pass'] === '') { Log::err("$id: brak new_pass"); $errors++; continue; }
        $todo[$sig] = ['name' => $n['name'], 'user' => $n['user'] !== '' ? $n['user'] : $n['name'], 'pass' => $n['pass'], 'host' => $n['host'], 'port' => $n['port'], 'rows' => [$id], 'old' => $r['map_old']];
    }
    if ($errors) fail("Popraw bledy w mapping.conf");
    if (!$todo) fail("Brak wierszy z action != skip i uzupelnionym new_name (najpierw: php dbmig.php plan --all)");
    $plan = [];
    $commentTpl = (string)(Cli::get('da-comment') ?: (getenv('DA_COMMENT') ?: '{sites} (z {old_name})'));
    foreach ($todo as $sig => &$t) {
        $sites = [];
        foreach ($t['rows'] as $rid) { $d = explode('__', $rid)[0]; $sites[$d] = true; }
        $t['sites'] = implode(', ', array_keys($sites));
        $t['comment'] = substr(str_replace(['{sites}', '{old_name}', '{name_full}', '{date}'], [$t['sites'], $t['old']['name'], $t['name'], date('Y-m-d')], $commentTpl), 0, 120);
        try { $db = new Db(['host' => $t['host'], 'port' => $t['port'], 'name' => $t['name'], 'user' => $t['user'], 'pass' => $t['pass']]); $t['exists'] = $db->version; $db->close(); }
        catch (Throwable $e) { $t['exists'] = null; }
        $plan[] = [$t['name'], $t['user'], mask($t['pass']), $t['exists'] ? "JUZ ISTNIEJE ({$t['exists']})" : 'do zalozenia', $t['sites'], $t['comment']];
    }
    unset($t);
    print_table(['nowa baza', 'uzytkownik', 'haslo', 'stan', 'strony', 'opis w panelu'], $plan);
    $hasPreset = (bool)(Cli::get('da-preset') ?: getenv('DA_PRESET'));
    $hasTpl = (bool)(Cli::get('da-fields') ?: getenv('DA_FIELDS'));
    Log::info("API: " . da_base_url() . "  konto: " . da_account() . ($extra ? "  dodatkowe pola: " . http_build_query($extra) : ''));
    if (!$extra && !$hasPreset && !$hasTpl) Log::warn("Natywne API DirectAdmin bez pola wyboru instancji MySQL - baza powstanie na instancji DOMYSLNEJ panelu (na cyber_Folks uzyj --da-preset=cyberfolks --da-instance=N). Sprawdz po zalozeniu wersje serwera ponizej.");
    $toCreate = array_filter($todo, function ($t) { return $t['exists'] === null; });
    if (!$toCreate) { Log::ok("Wszystkie nowe bazy juz istnieja i dzialaja."); return 0; }
    if (Cli::has('dry-run')) { Log::ok("[dry-run] nic nie zalozono"); return 0; }
    if (!confirm("Zalozyc " . count($toCreate) . " baz przez API DirectAdmin?")) return 1;
    $failed = 0;
    $daCmd = (string)(Cli::get('da-cmd') ?: (getenv('DA_CMD') ?: 'CMD_API_DATABASES'));
    $tpl = (string)(Cli::get('da-fields') ?: getenv('DA_FIELDS'));
    $instance = (string)(Cli::get('da-instance') ?: getenv('DA_INSTANCE'));
    if ($preset = da_preset()) {
        if (!Cli::has('da-cmd')) $daCmd = $preset['cmd'];
        if (!Cli::has('da-fields')) $tpl = $preset['fields'];
        if (!empty($preset['needs_instance']) && $instance === '') fail("Preset wymaga --da-instance=<nr>. " . $preset['hint']);
        Log::info("Preset: " . Cli::get('da-preset') . " -> $daCmd" . ($instance !== '' ? ", instancja $instance" : ''));
    }
    $custom = ($daCmd !== 'CMD_API_DATABASES' || $tpl !== '');
    foreach ($toCreate as $t) {
        $short = substr($t['name'], strlen($prefix)); $shortU = substr($t['user'], strlen($prefix));
        if ($tpl !== '') {
            $post = [];
            // szablon jest w formacie query-string, wiec wartosci kodujemy (haslo moze zawierac % + & =)
            parse_str(str_replace(['{name}', '{user}', '{pass}', '{name_full}', '{user_full}', '{prefix}', '{instance}', '{comment}', '{sites}'],
                array_map('rawurlencode', [$short, $shortU, $t['pass'], $t['name'], $t['user'], rtrim($prefix, '_'), $instance, $t['comment'], $t['sites']]), $tpl), $post);
            $post = array_merge($post, $extra);
        } else {
            $post = array_merge(['action' => 'create', 'name' => $short, 'user' => $shortU, 'passwd' => $t['pass'], 'passwd2' => $t['pass']], $extra);
        }
        try {
            $r = da_request($daCmd, $post);
            $d = $r['data'];
            if (isset($d['error']) && (string)$d['error'] !== '0') throw new DbmigError("API odmowilo: " . ($d['text'] ?? substr($r['body'], 0, 200)) . ' | ' . ($d['details'] ?? ''));
            if (!isset($d['error']) && !$custom) throw new DbmigError("API zwrocilo nieznana odpowiedz: " . substr($r['body'], 0, 200));
            if ($r['code'] >= 400) throw new DbmigError("HTTP {$r['code']}: " . substr(strip_tags($r['body']), 0, 200));
            if ($custom && preg_match('/class=["\'][^"\']*(error|alert-danger)[^"\']*["\'][^>]*>\s*([^<]{3,200})/i', $r['body'], $em)) {
                throw new DbmigError("wtyczka zglosila blad: " . trim($em[2]));
            }
            $isHtml = stripos($r['body'], '<html') !== false;
            $okMsg = isset($d['text']) ? $d['text'] . ' ' . ($d['details'] ?? '') : ($isHtml ? (preg_match('/class=["\'][^"\']*success[^"\']*["\'][^>]*>\s*([^<]{3,160})/i', $r['body'], $sm) ? trim($sm[1]) : '(strona HTML)') : substr(preg_replace('/\s+/', ' ', strip_tags($r['body'])), 0, 160));
            Log::ok("[{$t['name']}] API: HTTP {$r['code']} $okMsg");
            $connected = false;
            for ($try = 1; $try <= 5 && !$connected; $try++) {
                try {
                    $db = new Db(['host' => $t['host'], 'port' => $t['port'], 'name' => $t['name'], 'user' => $t['user'], 'pass' => $t['pass']]);
                    $v = $db->version; $db->close(); $connected = true;
                    $ov = '';
                    try { $o = new Db($t['old']); $ov = $o->version; $o->close(); } catch (Throwable $e) {}
                    if ($ov !== '' && $ov === $v) Log::warn("[{$t['name']}] polaczenie OK, ale wersja serwera ($v) jest TAKA SAMA jak starej bazy - baza chyba powstala na starej instancji (sprawdz pole instancji / --da-extra) albo host w new_host jest inny");
                    else Log::ok("[{$t['name']}] polaczenie OK, serwer: $v" . ($ov !== '' ? " (stara: $ov)" : ''));
                } catch (Throwable $e) {
                    if ($try < 5) { sleep(2); continue; }
                    $msg = "[{$t['name']}] po wywolaniu API nie moge sie polaczyc danymi z mapping.conf (host " . ($t['host'] !== '' ? $t['host'] : '(stary)') . "): " . $e->getMessage();
                    if ($custom) throw new DbmigError($msg . " - wtyczka chyba NIE zalozyla bazy (sprawdz w panelu, --da-cmd/--da-fields)");
                    Log::warn($msg . " - ustaw new_host i uruchom check");
                }
            }
        } catch (Throwable $e) {
            $failed++;
            Log::err("[{$t['name']}] " . $e->getMessage());
            if (!Cli::has('continue-on-error')) break;
        }
    }
    return $failed ? 1 : 0;
}

function cmd_repass()
{
    $preset = da_preset();
    if (!$preset || empty($preset['passwd'])) fail("repass wymaga --da-preset=cyberfolks (zmiana hasla przez wtyczke panelu)");
    $instance = (string)(Cli::get('da-instance') ?: getenv('DA_INSTANCE'));
    if (!empty($preset['needs_instance']) && $instance === '') fail("Podaj --da-instance=<nr> (instancja NOWEJ bazy, np. 2)");
    $all = load_rows();
    $sel = select_rows($all);
    $onlyNeeded = !Cli::has('all-passwords');
    // 1. ktore bazy: z wybranych wierszy (juz zmigrowanych, tzn. plik wskazuje na nowa baze)
    $targets = [];
    foreach ($sel as $id => $r) {
        if (!row_is_active($r)) continue;
        row_prepare($r);
        if ($r['error']) { Log::warn("$id: " . $r['error']); continue; }
        if (!$r['done'] && !Cli::has('force')) { Log::info("$id: plik nie wskazuje jeszcze na nowa baze - pomijam (--force aby i tak zmienic haslo)"); continue; }
        $hasUrl = false;
        foreach ($r['parsed']['slots'] as $sl) if ($sl['type'] === 'url') $hasUrl = true;
        $unsafe = !preg_match(PASS_URLSAFE_RE, $r['live']['pass']);
        if ($onlyNeeded && !($hasUrl && $unsafe)) { Log::info("$id: haslo nie wymaga zmiany dla tego typu pliku (" . ($hasUrl ? 'URL, haslo bezpieczne' : 'nie DATABASE_URL') . ")"); continue; }
        $targets[db_key($r['live'])] = $r['live'];
    }
    if (!$targets) { Log::ok("Nic do zmiany."); return 0; }
    // 2. wszystkie pliki (nie tylko wybrane), ktore wskazuja na te bazy - musza dostac nowe haslo
    $groups = [];
    foreach ($all as $id => $r) {
        if ($r['action'] === 'skip') continue;
        row_prepare($r);
        if ($r['error'] || $r['live'] === null) continue;
        $k = db_key($r['live']);
        if (!isset($targets[$k])) continue;
        if ($r['live']['pass'] !== $targets[$k]['pass']) { Log::warn("$id: wskazuje na te sama baze, ale z innym haslem - pomijam ten plik"); continue; }
        $groups[$k]['db'] = $targets[$k];
        $groups[$k]['rows'][$id] = $r;
    }
    $plan = [];
    foreach ($groups as $k => $g) $plan[] = [$g['db']['name'], $g['db']['user'], mask($g['db']['pass']), implode(', ', array_keys($g['rows']))];
    print_table(['baza', 'uzytkownik', 'obecne haslo', 'pliki do podmiany'], $plan);
    Log::info("Nowe hasla: 20 znakow z [A-Za-z0-9!*-_.], zmiana przez " . $preset['passwd'] . " (instancja $instance)");
    if (Cli::has('dry-run')) { Log::ok("[dry-run] nic nie zmieniono"); return 0; }
    if (!confirm("Zmienic haslo " . count($groups) . " uzytkownikow bazy i podmienic je w plikach?")) return 1;
    $failed = 0;
    $m = mapping_read(Work::mappingFile());
    $setPass = function (array $db, $pass) use ($preset, $instance) {
        $post = ['action' => 'changepass', 'db' => $db['name'], 'user' => $db['user'], 'instance' => $instance, 'passwd' => $pass, 'passwd2' => $pass];
        $r = da_request($preset['passwd'] . '?db=' . rawurlencode($db['name']) . '&user=' . rawurlencode($db['user']), $post);
        if (isset($r['data']['error']) && (string)$r['data']['error'] !== '0') throw new DbmigError("API odmowilo: " . ($r['data']['text'] ?? ''));
        if ($r['code'] >= 400) throw new DbmigError("HTTP {$r['code']}: " . substr(strip_tags($r['body']), 0, 200));
        if (preg_match('/class=["\'][^"\']*(error|alert-danger)[^"\']*["\'][^>]*>\s*([^<]{3,200})/i', $r['body'], $em)) throw new DbmigError("wtyczka zglosila blad: " . trim($em[2]));
    };
    foreach ($groups as $k => $g) {
        $db = $g['db'];
        $new = gen_password(20);
        $pwChanged = false; $updatedRows = [];
        try {
            $setPass($db, $new);
            $pwChanged = true;
            $ok = false; $ver = '';
            for ($try = 1; $try <= 5 && !$ok; $try++) {
                try { $c = new Db(array_merge($db, ['pass' => $new])); $ver = $c->version; $c->close(); $ok = true; }
                catch (Throwable $e) { if ($try < 5) sleep(2); }
            }
            if (!$ok) {
                $oldWorks = false;
                try { $c = new Db($db); $c->close(); $oldWorks = true; } catch (Throwable $e) {}
                throw new DbmigError("po zmianie nie moge sie polaczyc nowym haslem" . ($oldWorks ? " (stare nadal dziala - wtyczka nie zmienila hasla?)" : " (stare tez nie dziala! sprawdz w panelu)"));
            }
            Log::ok("[{$db['name']}] haslo zmienione, polaczenie OK ($ver)");
            foreach ($g['rows'] as $id => $row) {
                $row['eff'] = $row['live'];
                $row['eff']['pass'] = $new;
                $row['eff']['server_version'] = $ver;
                $row['planned'] = null;
                row_update_config($row);
                $st = State::row($id);
                $updatedRows[$id] = $st['backup_dir'] ?? null;
            }
            foreach ($m['rows'] as $mid => &$mr) { if (($mr['new_name'] ?? '') === $db['name']) $mr['new_pass'] = $new; }
            unset($mr);
            mapping_write(Work::mappingFile(), $m['defaults'], $m['rows']);
            Log::ok("[{$db['name']}] gotowe: " . count($g['rows']) . " plik(ow) z nowym haslem, mapping.conf zaktualizowany");
        } catch (Throwable $e) {
            $failed++;
            Log::err("[{$db['name']}] " . $e->getMessage());
            if ($pwChanged) {
                Log::warn("[{$db['name']}] wycofuje: pliki z backupu + poprzednie haslo");
                foreach ($updatedRows as $uid => $bdir) {
                    if (!$bdir) continue;
                    try { restore_backup($bdir); State::setRow($uid, ['rolled_back_at' => date('c')]); Log::warn("$uid: przywrocono plik"); }
                    catch (Throwable $e2) { Log::err("$uid: nie udalo sie przywrocic pliku: " . $e2->getMessage()); }
                }
                try {
                    $setPass($db, $db['pass']);
                    $c = new Db($db); $c->close();
                    Log::warn("[{$db['name']}] haslo przywrocone na poprzednie, polaczenie OK");
                } catch (Throwable $e2) {
                    Log::err("[{$db['name']}] NIE udalo sie przywrocic poprzedniego hasla (" . $e2->getMessage() . ") - ustaw je w panelu recznie (Zarzadzanie MySQL -> baza -> Zmien haslo), pliki konfiguracyjne maja stare haslo");
                }
            }
            if (!Cli::has('continue-on-error')) break;
        }
    }
    return $failed ? 1 : 0;
}

// =====================================================================
//  main
// =====================================================================

function usage()
{
    $s = <<<TXT
dbmig.php v%s - migracja baz MySQL stron na nowa instancje (hosting wspoldzielony)

Uzycie: php dbmig.php <komenda> [opcje]

Komendy:
  scan            skanuje katalogi stron, wyciaga dane baz, tworzy inventory.csv/json + mapping.conf
  plan            generuje w mapping.conf nazwy/userow/hasla NOWYCH baz (stara nazwa + --suffix) i action
  create-db       zaklada nowe bazy przez API DirectAdmin (CMD_API_DATABASES) wg mapping.conf
  repass          zmienia haslo uzytkownika NOWEJ bazy przez wtyczke panelu na bezpieczne w URL i podmienia
                  je we wszystkich plikach wskazujacych na te baze (domyslnie tylko tam, gdzie trzeba:
                  DATABASE_URL z haslem zawierajacym znaki kodowane %%XX; --all-passwords = wszystkie)
  check           sprawdza mapping.conf, polaczenia do starych/nowych baz, pliki (nic nie zmienia)
  show            szczegoly wierszy: sloty w plikach, planowane zmiany
  migrate         pelna migracja: dump -> import -> weryfikacja -> podmiana konfiguracji -> test HTTP
  dump            tylko zrzuty starych baz
  import          tylko import zrzutow do nowych baz (+ weryfikacja)
  verify          porownanie tabel/wierszy stara vs nowa
  update-config   tylko podmiana danych w plikach konfiguracyjnych
  rollback        przywraca pliki konfiguracyjne z backupu (strona wraca na stara baze)
  clean-new       usuwa tabele z NOWEJ bazy (do ponownego importu); nigdy nie dotyka starej
  status          stan migracji
  reset           czysci stan (state.json) dla wybranych wierszy

Wybor stron:
  --site <id|domena|wzorzec>   mozna podac kilka: --site a.pl,b.pl  albo --site 'sklep*'
  --all                        wszystkie wiersze z action != skip

Bezpieczenstwo / sterowanie:
  --dry-run              pokaz co by sie stalo, nic nie zmieniaj
  --yes                  nie pytaj o potwierdzenie
  --continue-on-error    nie zatrzymuj sie na pierwszym bledzie (domyslnie: stop)
  --force                pozwol na import do niepustej bazy / config-only na pusta baze
  --redo                 powtorz dump/import mimo ze stan mowi "zrobione" (import wymaga clean-new)
  --skip-check           (niezalecane) pomin bledy sprawdzenia wstepnego
  --no-http-check        nie testuj strony przez HTTP po zmianie
  --http-delay=<s>       odczekaj s sekund przed testem HTTP po zmianie (domyslnie 3; opcache)
  --no-auto-rollback     przy bledzie po zmianie konfiguracji NIE przywracaj backupu automatycznie
  --strict-verify        rozna liczba wierszy = blad (domyslnie ostrzezenie)
  --fast-verify          tylko lista tabel, bez liczenia wierszy
  --keep-definer         nie usuwaj DEFINER= ze zrzutu (domyslnie usuwane)
  --no-rename-refs       nie podmieniaj odwolan `stara_baza`. w zrzucie na nowa nazwe
  --no-routines / --no-events / --no-triggers   pomin procedury / eventy / triggery w zrzucie
  --charset=utf8mb4      zestaw znakow dla mysqldump/mysql
  --backup=<znacznik>    rollback z konkretnego backupu (domyslnie najnowszy)
  --url=<adres>          nadpisz url testowy (tylko z jednym --site)

plan:
  --suffix=<tekst>       doklejane do starej nazwy bazy i usera (domyslnie _new)
  --new-host=<host>      wpisz ten new_host we wszystkich generowanych wierszach
  --action=<a>           jakie action ustawic (domyslnie migrate); --keep-action nie zmienia action
  --overwrite            nadpisz juz uzupelnione new_*

create-db (API DirectAdmin):
  --da-url=<url>         adres panelu, np. https://sXX.cyber-folks.pl:2223 (lub DA_URL); bez tego skrypt probuje
                         127.0.0.1 i nazwe hosta na portach 2223/2222 i zapamietuje dzialajacy adres
  --da-user=<login>      login do panelu = nazwa konta (prefiks baz); domyslnie ze zmiennych USER/HOME
  --da-pass=<haslo>      haslo do panelu albo klucz logowania; lepiej: zmienna DA_PASS lub pytanie na konsoli
  --da-extra='pole=wartosc&...'   dodatkowe pola formularza, np. wybor instancji MySQL (patrz README)
  --da-preset=cyberfolks gotowe ustawienia wtyczki "Zarzadzanie MySQL" cyber_Folks (wymaga --da-instance=<nr>)
  --da-instance=<nr>     numer instancji z formularza panelu (cyber_Folks: 2 = MariaDB 10.11 -> new_host 127.0.0.1:3308)
  --da-comment='...'     opis bazy w panelu (preset cyberfolks); domyslnie '{sites} (z {old_name})', np. "sklep.pl (z konto_wp5)"
  --da-cmd=<sciezka>     inna sciezka niz CMD_API_DATABASES (np. wtyczka hostingu: CMD_PLUGINS/xxx/...)
  --da-fields='...'      wlasny szablon pol POST: {name} {user} {pass} (bez prefiksu konta), {name_full} {user_full} {prefix} {instance} {comment} {sites}
                         np. --da-fields='dbname={name}&dbuser={user}&dbpass={pass}&instance=mariadb11'
  --list                 tylko wypisz bazy istniejace na koncie (CMD_API_DATABASES)

Skanowanie:
  --root=<katalog>       domyslnie ~/domains (DirectAdmin) albo biezacy katalog
  --depth=<n>            max glebokosc (domyslnie 7)
  --exclude=<nazwa>      dodatkowe katalogi do pominiecia (mozna wielokrotnie)
  --no-custom            nie szukaj polaczen we wlasnych skryptach PHP (mysqli/PDO)
  --no-db-test           nie testuj polaczen przy skanie

Srodowisko:
  --workdir=<katalog>    domyslnie ./dbmig-work obok skryptu (NIE w public_html)
  --mapping=<plik>       inny plik mapping.conf
  --mysql-bin, --mysqldump-bin, --php-bin, --curl-bin   sciezki do narzedzi
  --db-cli               nie uzywaj mysqli, tylko klienta mysql
  --show-secrets         pokazuj hasla w wyjsciu (domyslnie maskowane)
  --verbose              wiecej informacji;  --no-color  bez kolorow

Typowy przebieg:
  php dbmig.php scan
  php dbmig.php plan --all                 (albo recznie: uzupelnij dbmig-work/mapping.conf: new_* i action = migrate)
  php dbmig.php create-db --all --da-preset=cyberfolks --da-instance=2      (albo zaloz bazy w panelu)
  php dbmig.php check
  php dbmig.php migrate --site example.pl --dry-run
  php dbmig.php migrate --site example.pl
  php dbmig.php migrate --all
  php dbmig.php rollback --site example.pl        (gdyby cos poszlo nie tak)
TXT;
    Log::raw(sprintf($s, DBMIG_VERSION));
}

function main($argv)
{
    Cli::parse($argv);
    Log::$verbose = Cli::has('verbose');
    Log::$color = !Cli::has('no-color') && (!function_exists('posix_isatty') || posix_isatty(STDOUT));
    $cmd = Cli::$cmd;
    if (Cli::has('version')) { Log::raw('dbmig.php ' . DBMIG_VERSION); return 0; }
    if ($cmd === null || in_array($cmd, ['help', '-h', '--help'], true) || Cli::has('help')) { usage(); return 0; }
    Work::init();
    Log::append('---- ' . implode(' ', array_map(function ($a) { return preg_match('/pass|haslo/i', $a) ? '<ukryte>' : $a; }, $argv)));
    $map = ['scan' => 'cmd_scan', 'check' => 'cmd_check', 'show' => 'cmd_show', 'migrate' => 'cmd_migrate', 'dump' => 'cmd_dump',
        'import' => 'cmd_import', 'verify' => 'cmd_verify', 'update-config' => 'cmd_update_config', 'rollback' => 'cmd_rollback',
        'clean-new' => 'cmd_clean_new', 'status' => 'cmd_status', 'reset' => 'cmd_reset', 'plan' => 'cmd_plan', 'create-db' => 'cmd_create_db', 'repass' => 'cmd_repass'];
    if (!isset($map[$cmd])) { Log::err("Nieznana komenda: $cmd"); usage(); return 2; }
    try {
        return (int)$map[$cmd]();
    } catch (Throwable $e) {
        Log::err("Blad: " . $e->getMessage() . (Log::$verbose ? "\n" . $e->getTraceAsString() : ''));
        return 1;
    }
}

exit(main($argv));
