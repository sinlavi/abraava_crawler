<?php
// api/admin.php
require_once __DIR__ . '/config.php';

function handleAdmin($path, $auth) {
    switch ($path) {
        case '/admin/users':
            listUsers($auth);
            break;
        case '/admin/user/usage':
            userUsage($auth);
            break;
        case '/admin/stats':
            stats($auth);
            break;
        default:
            respond(['success' => false, 'error' => 'Endpoint not found'], 404);
    }
}

function listUsers($auth) {
    $db = getDB();
    $stmt = $db->query("SELECT id, username, role, created_at FROM users");
    $users = $stmt->fetchAll();
    respond(['success' => true, 'users' => $users]);
}

function userUsage($auth) {
    $userId = $_GET['user_id'] ?? null;
    if (!$userId) respond(['success' => false, 'error' => 'user_id required'], 400);

    $db = getDB();
    $stmt = $db->prepare("SELECT action, COUNT(*) as count FROM user_history WHERE user_id = ? GROUP BY action");
    $stmt->execute([$userId]);
    $usage = $stmt->fetchAll();

    $stmtApps = $db->prepare("SELECT name, api_token, type, created_at FROM applications WHERE user_id = ?");
    $stmtApps->execute([$userId]);
    $apps = $stmtApps->fetchAll();

    respond(['success' => true, 'usage' => $usage, 'applications' => $apps]);
}

function stats($auth) {
    $db = getDB();
    $stats = [];
    $stats['total_users'] = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['total_tracks'] = $db->query("SELECT COUNT(*) FROM tracks")->fetchColumn();
    $stats['downloaded_tracks'] = $db->query("SELECT COUNT(*) FROM tracks WHERE downloaded = TRUE")->fetchColumn();
    $stats['queue_size'] = $db->query("SELECT COUNT(*) FROM download_queue WHERE status = 'pending'")->fetchColumn();

    respond(['success' => true, 'stats' => $stats]);
}
