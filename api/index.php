<?php
// api/index.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/middleware.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') respond([], 200);

try {

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
if ($scriptDir !== '/' && strpos($path, $scriptDir) === 0) {
    $path = substr($path, strlen($scriptDir));
}
$path = rtrim($path, '/') ?: '/';

// Open endpoints (no auth)
if ($path === '/auth/signup' || $path === '/auth/login') {
    require_once __DIR__ . '/auth.php';
    handleAuth($path);
}

// Protected endpoints
$auth = authenticate();

switch (true) {
    case (strpos($path, '/music') === 0):
        require_once __DIR__ . '/music.php';
        handleMusic($path, $auth);
        break;
    case (strpos($path, '/download') === 0):
        require_once __DIR__ . '/download.php';
        handleDownload($path, $auth);
        break;
    case (strpos($path, '/admin') === 0):
        requireAdmin($auth);
        require_once __DIR__ . '/admin.php';
        handleAdmin($path, $auth);
        break;
    case ($path === '/auth/me'):
        respond(['success' => true, 'user' => $auth]);
        break;
    case ($path === '/auth/app/create'):
        require_once __DIR__ . '/auth.php';
        handleAppCreate($auth);
        break;
    default:
        respond(['success' => false, 'error' => 'Endpoint not found: ' . $path], 404);
}

} catch (Throwable $e) {
    respond([
        'success' => false,
        'error' => 'Internal Server Error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], 500);
}
