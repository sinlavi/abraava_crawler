<?php
// api/download.php
require_once __DIR__ . '/config.php';

function handleDownload($path, $auth) {
    $method = $_SERVER['REQUEST_METHOD'];
    $params = ($method === 'GET') ? $_GET : json_decode(file_get_contents('php://input'), true);

    switch ($path) {
        case '/download/add':
            if ($method !== 'POST') respond(['success' => false, 'error' => 'POST required'], 405);
            addDownload($params, $auth);
            break;
        case '/download/queue':
            getQueue($params, $auth);
            break;
        case '/download/status':
            getStatus($params, $auth);
            break;
        case '/download/update':
            if ($method !== 'POST') respond(['success' => false, 'error' => 'POST required'], 405);
            updateDownload($params, $auth);
            break;
        default:
            respond(['success' => false, 'error' => 'Endpoint not found'], 404);
    }
}

function addDownload($data, $auth) {
    if (empty($data['trackId'])) respond(['success' => false, 'error' => 'trackId required'], 400);

    $db = getDB();

    // Ensure track exists in tracks table to satisfy FK
    $stmtTrack = $db->prepare("SELECT downloaded, collectionId FROM tracks WHERE trackId = ?");
    $stmtTrack->execute([$data['trackId']]);
    $trackRow = $stmtTrack->fetch();

    if (!$trackRow) {
        // Fetch metadata from iTunes to populate tracks table
        $url = ITUNES_LOOKUP_API . '?id=' . $data['trackId'];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = curl_exec($ch);
        $meta = json_decode($resp, true);

        if (isset($meta['results'][0])) {
            $t = $meta['results'][0];
            $stmtIns = $db->prepare("INSERT IGNORE INTO tracks (trackId, collectionId, trackName, artistName) VALUES (?, ?, ?, ?)");
            $stmtIns->execute([$t['trackId'], $t['collectionId'] ?? null, $t['trackName'] ?? 'Unknown', $t['artistName'] ?? 'Unknown']);

            if (isset($t['collectionId'])) {
                $stmtInsColl = $db->prepare("INSERT IGNORE INTO collections (collectionId, collectionName, trackCount) VALUES (?, ?, ?)");
                $stmtInsColl->execute([$t['collectionId'], $t['collectionName'] ?? 'Unknown', $t['trackCount'] ?? 0]);
            }

            $collectionId = $t['collectionId'] ?? null;
        } else {
            respond(['success' => false, 'error' => 'Track not found in iTunes'], 404);
        }
    } else {
        if ($trackRow['downloaded']) {
            respond(['success' => true, 'status' => 'completed', 'message' => 'Already processed']);
        }
        $collectionId = $trackRow['collectionId'];
    }

    $stmt = $db->prepare("INSERT INTO download_queue (trackId, collectionId, user_id, quality, platform, status) VALUES (?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([
        $data['trackId'],
        $collectionId,
        $auth['user_id'],
        $data['quality'] ?? DEFAULT_AUDIO_QUALITY,
        $data['platform'] ?? 'web'
    ]);

    $downloadId = $db->lastInsertId();
    logHistory($auth['user_id'], 'add_download', $data['trackId']);

    respond(['success' => true, 'download_id' => $downloadId, 'status' => 'pending']);
}

function getQueue($params, $auth) {
    $db = getDB();
    $limit = isset($params['limit']) ? (int)$params['limit'] : 20;
    $offset = isset($params['offset']) ? (int)$params['offset'] : 0;

    // Admins see all, users see their own
    if ($auth['role'] === 'admin') {
        $stmt = $db->prepare("SELECT q.*, u.username FROM download_queue q LEFT JOIN users u ON q.user_id = u.id ORDER BY q.addedAt DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
    } else {
        $stmt = $db->prepare("SELECT q.* FROM download_queue q WHERE q.user_id = ? ORDER BY q.addedAt DESC LIMIT ? OFFSET ?");
        $stmt->execute([$auth['user_id'], $limit, $offset]);
    }

    $items = $stmt->fetchAll();
    respond(['success' => true, 'items' => $items]);
}

function getStatus($params, $auth) {
    $id = $params['id'] ?? null;
    $trackId = $params['trackId'] ?? null;
    $db = getDB();

    if ($id) {
        $stmt = $db->prepare("SELECT * FROM download_queue WHERE id = ?");
        $stmt->execute([$id]);
    } else {
        $stmt = $db->prepare("SELECT * FROM download_queue WHERE trackId = ? AND user_id = ? ORDER BY addedAt DESC LIMIT 1");
        $stmt->execute([$trackId, $auth['user_id']]);
    }

    $row = $stmt->fetch();
    if ($row) {
        respond(['success' => true, 'download' => $row]);
    }
    respond(['success' => false, 'error' => 'Download not found']);
}

function updateDownload($data, $auth) {
    if (empty($data['id'])) respond(['success' => false, 'error' => 'ID required'], 400);

    $db = getDB();
    $updates = [];
    $values = [];

    if (isset($data['status'])) {
        $updates[] = "status = ?";
        $values[] = $data['status'];
        if ($data['status'] === DOWNLOAD_STATUS_COMPLETED) {
            $updates[] = "completedAt = NOW()";
            $updates[] = "progress = 100";

            // Mark track as downloaded
            $stmtTrack = $db->prepare("UPDATE tracks SET downloaded = TRUE WHERE trackId = (SELECT trackId FROM download_queue WHERE id = ?)");
            $stmtTrack->execute([$data['id']]);

            // Update collection status
            $stmtCollId = $db->prepare("SELECT collectionId FROM download_queue WHERE id = ?");
            $stmtCollId->execute([$data['id']]);
            $collId = $stmtCollId->fetchColumn();
            if ($collId) {
                require_once __DIR__ . '/music.php';
                updateCollectionDownloadStatus($collId);
            }
        }
    }

    if (isset($data['progress'])) {
        $updates[] = "progress = ?";
        $values[] = (int)$data['progress'];
    }

    if (isset($data['status_step'])) {
        $updates[] = "status_step = ?";
        $values[] = $data['status_step'];
    }

    if (empty($updates)) respond(['success' => false, 'error' => 'Nothing to update'], 400);

    $values[] = $data['id'];
    $sql = "UPDATE download_queue SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($values);

    respond(['success' => true]);
}
