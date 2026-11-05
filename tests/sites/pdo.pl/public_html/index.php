<?php
try {
    $pdo = new \PDO('mysql:host=mysql57;dbname=acc_app;charset=utf8mb4', 'acc_app', 'appPass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die('DB error');
}
echo "ok";
