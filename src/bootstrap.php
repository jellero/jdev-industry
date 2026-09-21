<?php
declare(strict_types=1);

session_start();

$root = dirname(__DIR__);
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    $configFile = $root . '/config.example.php';
}

$config = require $configFile;
date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Rome');

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/MachineApi.php';
require_once __DIR__ . '/functions.php';

try {
    $pdo = Db::connect($config['db']);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Configurazione database richiesta</h1>';
    echo '<p>Copia <code>config.example.php</code> in <code>config.php</code>, imposta MySQL e importa <code>database/schema.sql</code>.</p>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}
