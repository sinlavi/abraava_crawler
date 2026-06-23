<?php
// api/auth.php
require_once __DIR__ . '/config.php';

function handleAuth($path) {
    $method = $_SERVER['REQUEST_METHOD'];
    $data = json_decode(file_get_contents('php://input'), true);

    if ($path === '/auth/signup') {
        if ($method !== 'POST') respond(['success' => false, 'error' => 'Method not allowed'], 405);
        signup($data);
    } elseif ($path === '/auth/login') {
        if ($method !== 'POST') respond(['success' => false, 'error' => 'Method not allowed'], 405);
        login($data);
    }
}

function signup($data) {
    if (empty($data['username']) || empty($data['password'])) {
        respond(['success' => false, 'error' => 'Username and password required'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$data['username']]);
    if ($stmt->fetch()) {
        respond(['success' => false, 'error' => 'Username already exists'], 409);
    }

    $hash = password_hash($data['password'], PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    try {
        $stmt->execute([$data['username'], $hash]);
        $userId = $db->lastInsertId();

        // Auto-create a default application token for the user
        $token = bin2hex(random_bytes(20));
        $stmtApp = $db->prepare("INSERT INTO applications (name, api_token, user_id, type) VALUES ('Default Client', ?, ?, 'client')");
        $stmtApp->execute([$token, $userId]);

        respond(['success' => true, 'message' => 'User created', 'api_token' => $token]);
    } catch (Exception $e) {
        respond(['success' => false, 'error' => 'Signup failed: ' . $e->getMessage()], 500);
    }
}

function login($data) {
    if (empty($data['username']) || empty($data['password'])) {
        respond(['success' => false, 'error' => 'Username and password required'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$data['username']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($data['password'], $user['password_hash'])) {
        respond(['success' => false, 'error' => 'Invalid credentials'], 401);
    }

    // Get the most recent application token
    $stmtApp = $db->prepare("SELECT api_token FROM applications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmtApp->execute([$user['id']]);
    $app = $stmtApp->fetch();
    $token = $app ? $app['api_token'] : null;

    if (!$token) {
        $token = bin2hex(random_bytes(20));
        $stmtApp = $db->prepare("INSERT INTO applications (name, api_token, user_id, type) VALUES ('Client', ?, ?, 'client')");
        $stmtApp->execute([$token, $user['id']]);
    }

    respond([
        'success' => true,
        'user_id' => $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'api_token' => $token
    ]);
}

function handleAppCreate($auth) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['name'])) respond(['success' => false, 'error' => 'Application name required'], 400);

    $token = bin2hex(random_bytes(20));
    $type = $data['type'] ?? 'application';

    $db = getDB();
    $stmt = $db->prepare("INSERT INTO applications (name, api_token, user_id, type) VALUES (?, ?, ?, ?)");
    $stmt->execute([$data['name'], $token, $auth['user_id'], $type]);

    logHistory($auth['user_id'], 'create_app', $db->lastInsertId());
    respond(['success' => true, 'api_token' => $token]);
}
