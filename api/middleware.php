<?php
// api/middleware.php
require_once __DIR__ . '/config.php';

function authenticate(): array {
    $headers = getallheaders();
    $token = null;

    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }

    if (!$token) {
        respond(['success' => false, 'error' => 'Authentication required'], 401);
    }

    $db = getDB();

    // Check applications table first (for bots/clients)
    $stmt = $db->prepare("SELECT a.*, u.role FROM applications a LEFT JOIN users u ON a.user_id = u.id WHERE a.api_token = ?");
    $stmt->execute([$token]);
    $app = $stmt->fetch();

    if ($app) {
        return [
            'type' => 'application',
            'app_id' => $app['id'],
            'user_id' => $app['user_id'],
            'role' => $app['role'] ?? 'user',
            'app_type' => $app['type']
        ];
    }

    // Check users table (for direct UI login session - normally we'd use JWT but keeping it simple for this overhauled structure)
    // Here we'll treat the 'token' as a user session token if it was implemented that way,
    // but the request asks for "tokens to use are there" in applications table.
    // So applications table is the primary source of API access.

    respond(['success' => false, 'error' => 'Invalid token'], 403);
    return [];
}

function requireAdmin(array $auth) {
    if ($auth['role'] !== 'admin') {
        respond(['success' => false, 'error' => 'Admin access required'], 403);
    }
}

function logHistory($userId, $action, $targetId = null) {
    if (!$userId) return;
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO user_history (user_id, action, target_id) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $action, $targetId]);
}
