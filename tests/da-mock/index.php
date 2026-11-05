<?php
// Atrapa panelu DirectAdmin do testow. Obsluguje:
//  - natywne API: /CMD_API_DATABASES (lista, action=create)
//  - wtyczke cyber_Folks: /CMD_PLUGINS/mysql (lista HTML) i /CMD_PLUGINS/mysql/actions/db_create.php (POST formularza)
// Naprawde zaklada bazy/uzytkownikow na mysql8 jako root.
$u = $_SERVER['PHP_AUTH_USER'] ?? ''; $p = $_SERVER['PHP_AUTH_PW'] ?? '';
$log = fopen('/tmp/da-mock.log', 'a');
fwrite($log, date('c') . ' ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' user=' . $u . ' post=' . json_encode($_POST) . "\n");
if ($u !== 'acc' || $p !== 'panelpass') { header('HTTP/1.0 401 Unauthorized'); echo 'error=1&text=' . rawurlencode('Login failed') . '&details='; exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
mysqli_report(MYSQLI_REPORT_OFF);
$m = @new mysqli('mysql8', 'root', 'root');
if ($m->connect_error) { echo 'error=1&text=' . rawurlencode('mysql8 down') . '&details=' . rawurlencode($m->connect_error); exit; }

function create_db(mysqli $m, $name, $user, $pw)
{
    $db = 'acc_' . $name; $usr = 'acc_' . $user;
    $esc = $m->real_escape_string($pw);
    if (!$m->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4")) return $m->error;
    $m->query("CREATE USER '$usr'@'%' IDENTIFIED BY '$esc'");
    $m->query("GRANT ALL ON `$db`.* TO '$usr'@'%'");
    return true;
}
function list_dbs(mysqli $m)
{
    $out = [];
    $r = $m->query("SHOW DATABASES LIKE 'acc\\_%'");
    while ($row = $r->fetch_row()) $out[] = $row[0];
    return $out;
}

if ($path === '/CMD_API_DATABASES') {
    // na cyber_Folks natywne API jest wylaczone - symulujemy to samo
    http_response_code(500);
    echo 'details=' . rawurlencode('http://www.directadmin.com/features.php?id=1528') . '&error=1&text=' . rawurlencode('MySQL nie jest włączony na tym serwerze');
    exit;
}
if ($path === '/CMD_PLUGINS/mysql') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<html><body><h1>Zarządzanie MySQL</h1><table>\n";
    foreach (list_dbs($m) as $d) echo "<tr><td>$d <span class=\"badge\">" . (substr($d, -1) === '8' ? '' : 'legacy') . "</span></td><td><span>MariaDB 10.11</span></td><td>Dodaj opis</td><td>1</td><td>1 KB</td></tr>\n";
    echo "</table></body></html>";
    exit;
}
if ($path === '/CMD_PLUGINS/mysql/actions/db_create.php') {
    header('Content-Type: text/html; charset=utf-8');
    $a = $_POST['action'] ?? '';
    if ($a !== 'create') { echo "<html><body><form method=post>formularz</form></body></html>"; exit; }
    $name = $_POST['name'] ?? ''; $user = $_POST['user'] ?? ''; $pw = $_POST['passwd'] ?? ''; $pw2 = $_POST['passwd2'] ?? '';
    $inst = $_POST['instance'] ?? '';
    if ($inst !== '2') { echo "<html><body><div class='error'>Błąd: nieznana instancja '$inst' (test oczekuje instance=2)</div></body></html>"; exit; }
    if (!isset($_POST['userlist'], $_POST['create'])) { echo "<html><body><div class='error'>Błąd: brak pól userlist/create</div></body></html>"; exit; }
    if (!preg_match('/^[a-z0-9_]{1,40}$/', $name) || !preg_match('/^[a-z0-9_]{1,40}$/', $user)) { echo "<html><body><div class='error'>Błąd: niepoprawna nazwa</div></body></html>"; exit; }
    if ($pw === '' || $pw !== $pw2) { echo "<html><body><div class='error'>Błąd: hasła różne</div></body></html>"; exit; }
    $r = create_db($m, $name, $user, $pw);
    if ($r !== true) { echo "<html><body><div class='error'>Błąd: $r</div></body></html>"; exit; }
    echo "<html><body><div class='success'>Baza acc_$name została utworzona</div></body></html>";
    exit;
}
if ($path === '/CMD_PLUGINS/mysql/actions/db_user_password.php') {
    header('Content-Type: text/html; charset=utf-8');
    $a = $_POST['action'] ?? '';
    if ($a !== 'changepass') { echo "<html><body><form method=post>formularz</form></body></html>"; exit; }
    $user = $_POST['user'] ?? ''; $pw = $_POST['passwd'] ?? ''; $pw2 = $_POST['passwd2'] ?? ''; $inst = $_POST['instance'] ?? '';
    if ($inst !== '2' || !preg_match('/^acc_[a-z0-9_]+$/', $user) || $pw === '' || $pw !== $pw2 || strlen($pw) < 10) { echo "<html><body><div class='error'>Błąd: niepoprawne dane</div></body></html>"; exit; }
    $esc = $m->real_escape_string($pw);
    if (!$m->query("ALTER USER '$user'@'%' IDENTIFIED BY '$esc'")) { echo "<html><body><div class='error'>Błąd: " . $m->error . "</div></body></html>"; exit; }
    echo "<html><body><div class='success'>Hasło użytkownika $user zostało zmienione</div></body></html>";
    exit;
}
http_response_code(404);
echo 'error=1&text=' . rawurlencode('Unknown command') . '&details=';
