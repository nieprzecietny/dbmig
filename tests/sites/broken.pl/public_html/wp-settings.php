<?php
// test stub: this "site" breaks whenever it is pointed at a DB whose name ends with 8
$h = DB_HOST; $port = 3306;
if (strpos($h, ':') !== false) { list($h, $port) = explode(':', $h, 2); $port = (int)$port; }
$m = mysqli_init();
$ok = @$m->real_connect($h, DB_USER, DB_PASSWORD, DB_NAME, $port);
if (!$ok || substr(DB_NAME, -1) === '8') {
    echo "<h1>Błąd nawiązywania połączenia z bazą danych</h1>";
} else {
    echo "OK db=" . DB_NAME;
}
