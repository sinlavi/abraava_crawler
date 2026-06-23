<?php
// api/config.php

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'musicman');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_PORT', getenv('DB_PORT') ?: 3306);

define('ITUNES_SEARCH_API', 'https://itunes.apple.com/search');
define('ITUNES_LOOKUP_API', 'https://itunes.apple.com/lookup');

define('SUPPORTED_AUDIO_QUALITIES', ['320', '192', '128']);
define('DEFAULT_AUDIO_QUALITY', '192');

define('DOWNLOAD_STATUS_PENDING', 'pending');
define('DOWNLOAD_STATUS_DOWNLOADING', 'downloading');
define('DOWNLOAD_STATUS_PAUSED', 'paused');
define('DOWNLOAD_STATUS_COMPLETED', 'completed');
define('DOWNLOAD_STATUS_FAILED', 'failed');
define('DOWNLOAD_STATUS_STOPPED', 'stopped');

function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $db = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $db;
}

function respond($data, int $status = 200): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Quality');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}
