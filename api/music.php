<?php
// api/music.php
require_once __DIR__ . '/config.php';

function handleMusic($path, $auth) {
    $method = $_SERVER['REQUEST_METHOD'];
    $params = ($method === 'GET') ? $_GET : json_decode(file_get_contents('php://input'), true);

    switch ($path) {
        case '/music/search':
            search($params, $auth);
            break;
        case '/music/lookup':
            lookup($params, $auth);
            break;
        case '/music/lyrics':
            getLyrics($params, $auth);
            break;
        case '/music/check-processed':
            checkProcessed($params, $auth);
            break;
        default:
            respond(['success' => false, 'error' => 'Endpoint not found'], 404);
    }
}

function search($params, $auth) {
    if (empty($params['term'])) respond(['success' => false, 'error' => 'Term required'], 400);

    // Proxy to iTunes search
    $url = ITUNES_SEARCH_API . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $data = json_decode($response, true);

    if (isset($data['results'])) {
        $db = getDB();
        foreach ($data['results'] as &$item) {
            $id = $item['trackId'] ?? $item['collectionId'] ?? null;
            if ($id) {
                $type = $item['wrapperType'] === 'track' ? 'tracks' : 'collections';
                $pk = $item['wrapperType'] === 'track' ? 'trackId' : 'collectionId';
                $stmt = $db->prepare("SELECT downloaded FROM $type WHERE $pk = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                $item['downloaded'] = $row ? (bool)$row['downloaded'] : false;
            }
        }
    }

    logHistory($auth['user_id'], 'search', $params['term']);
    respond($data);
}

function lookup($params, $auth) {
    if (empty($params['id'])) respond(['success' => false, 'error' => 'ID required'], 400);

    $url = ITUNES_LOOKUP_API . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $data = json_decode($response, true);

    if (isset($data['results'])) {
        $db = getDB();
        foreach ($data['results'] as &$item) {
            $id = $item['trackId'] ?? $item['collectionId'] ?? null;
            if ($id) {
                $type = $item['wrapperType'] === 'track' ? 'tracks' : 'collections';
                $pk = $item['wrapperType'] === 'track' ? 'trackId' : 'collectionId';

                // If it's a collection, check if all tracks are downloaded
                if ($item['wrapperType'] === 'collection') {
                    updateCollectionDownloadStatus($id);
                }

                $stmt = $db->prepare("SELECT downloaded FROM $type WHERE $pk = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                $item['downloaded'] = $row ? (bool)$row['downloaded'] : false;

                // Fetch mirror URLs from original system logic (adapted)
                // For simplicity in this overhauled version, we'll just check tracks table for audioUrl
                // but usually it would join with entityMirrors.
            }
        }
    }

    logHistory($auth['user_id'], 'lookup', $params['id']);
    respond($data);
}

function updateCollectionDownloadStatus($collectionId) {
    if (!$collectionId) return;
    $db = getDB();

    // 1. Get collection info to know total track count
    $stmtColl = $db->prepare("SELECT trackCount FROM collections WHERE collectionId = ?");
    $stmtColl->execute([$collectionId]);
    $coll = $stmtColl->fetch();

    if (!$coll) return;

    // 2. Count downloaded tracks in our DB for this collection
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM tracks WHERE collectionId = ? AND downloaded = TRUE");
    $stmtCount->execute([$collectionId]);
    $downloadedCount = $stmtCount->fetchColumn();

    // 3. If match, set collection downloaded = TRUE
    if ($downloadedCount >= $coll['trackCount'] && $coll['trackCount'] > 0) {
        $stmtUpdate = $db->prepare("UPDATE collections SET downloaded = TRUE WHERE collectionId = ?");
        $stmtUpdate->execute([$collectionId]);
    }
}

function checkProcessed($params, $auth) {
    $id = $params['id'];
    $type = $params['type'] ?? 'track';
    $db = getDB();

    if ($type === 'track') {
        $stmt = $db->prepare("SELECT downloaded FROM tracks WHERE trackId = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        respond(['processed' => $row ? (bool)$row['downloaded'] : false]);
    } else {
        $stmt = $db->prepare("SELECT downloaded FROM collections WHERE collectionId = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        respond(['processed' => $row ? (bool)$row['downloaded'] : false]);
    }
}
function getLyrics($params, $auth) {
    $id = $params['id'];
    $db = getDB();
    $stmt = $db->prepare("SELECT lyrics FROM tracks WHERE trackId = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row && $row['lyrics']) {
        respond(['success' => true, 'lyrics' => json_decode($row['lyrics'], true)]);
    }
    respond(['success' => false, 'error' => 'Lyrics not found']);
}
