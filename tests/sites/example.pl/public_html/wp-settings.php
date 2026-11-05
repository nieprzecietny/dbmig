<?php
// test stub: emulates WordPress bootstrap (connects with wp-config values)
$h = DB_HOST; $port = 3306;
if (strpos($h, ':') !== false) { list($h, $port) = explode(':', $h, 2); $port = (int)$port; }
$m = mysqli_init();
$ok = @$m->real_connect($h, DB_USER, DB_PASSWORD, DB_NAME, $port);
if (!$ok || (defined('BROKEN') && substr(DB_NAME, -1) === '8')) {
    http_response_code(500);
    echo "<h1>Error establishing a database connection</h1>";
} else {
    echo "OK db=" . DB_NAME . " host=" . DB_HOST . " server=" . $m->server_info;
}
