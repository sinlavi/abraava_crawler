<?php
/**
 * iTunes API Proxy v2.0 – MySQL Edition
 * + Download Manager v1.0
 * Unified attachments structure, type‑specific fields.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ── MySQL Configuration ────────────────────────────────────
define('DEST_HOST', 'localhost');
define('DEST_NAME', 'abraava');
define('DEST_USER', 'root');
define('DEST_PASS', '');
define('DEST_PORT', 3306);
define('CHUNK_SIZE', 100);

// ── General Configuration ─────────────────────────────────
define('CACHE_DURATION', 21600);
define('ITUNES_SEARCH_API', 'https://itunes.apple.com/search');
define('ITUNES_LOOKUP_API', 'https://itunes.apple.com/lookup');
define('BATCH_SIZE', 500);
define('ENABLE_GZIP', true);
define('RATE_LIMIT_MAX_RETRIES', 5);
define('RATE_LIMIT_BASE_DELAY', 0.5);
define('RATE_LIMIT_MAX_DELAY', 30);
define('ITUNES_RATE_LIMIT_PER_MINUTE', 50);
define('USE_PROXY_ROTATION', true);
define('PROXY_LIST_FILE', __DIR__ . '/proxies.txt');
define('ENABLE_REQUEST_THROTTLING', true);
define('THROTTLE_MIN_INTERVAL', 500000);
define('ENABLE_USER_AGENT_ROTATION', true);
define('ENABLE_IP_SPOOFING', true);
define('CACHE_ADAPTIVE_TTL', true);
define('SMART_CACHE_PRELOAD', true);
define('SUPPORTED_AUDIO_QUALITIES', ['320', '192', '128']);
define('DEFAULT_AUDIO_QUALITY', '192');

// ── Authentication Token ───────────────────────────────────
define('API_TOKEN', getenv('API_TOKEN') ?: 'change_me_to_a_secure_token');

// ── Download Status Constants ─────────────────────────────
define('DOWNLOAD_STATUS_PENDING', 'pending');
define('DOWNLOAD_STATUS_DOWNLOADING', 'downloading');
define('DOWNLOAD_STATUS_PAUSED', 'paused');
define('DOWNLOAD_STATUS_COMPLETED', 'completed');
define('DOWNLOAD_STATUS_FAILED', 'failed');
define('DOWNLOAD_STATUS_STOPPED', 'stopped');

$db = null;
$statements = [];
$lastRequestTime = 0;
$currentProxyIndex = 0;
$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:120.0) Gecko/20100101 Firefox/120.0',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36 Edg/119.0.0.0',
];

// ── Database & Statements ─────────────────────────────────
function getDB(): PDO
{
    global $db;
    if ($db === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DEST_HOST, DEST_PORT, DEST_NAME);
        $db = new PDO($dsn, DEST_USER, DEST_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        initDatabase($db);
    }
    return $db;
}

function getStatement(string $sql): PDOStatement
{
    global $statements;
    $hash = md5($sql);
    if (!isset($statements[$hash])) {
        $statements[$hash] = getDB()->prepare($sql);
    }
    return $statements[$hash];
}

// ── Schema & Initialization ────────────────────────────────
function initDatabase(PDO $db): void
{
    static $initialized = false;
    if ($initialized) return;

    // Migrate old download_queue
    try {
        if ($db->query("SHOW TABLES LIKE 'download_queue'")->fetch()) {
            if (!$db->query("SHOW TABLES LIKE 'downloadQueue'")->fetch()) {
                $db->exec("RENAME TABLE download_queue TO downloadQueue");
            }
        }
    } catch (Exception $e) { /* ignore */ }

    // Migrate entityMirrors: add id, unique key, source
    try {
        if ($db->query("SHOW TABLES LIKE 'entityMirrors'")->fetch()) {
            if (!$db->query("SHOW COLUMNS FROM entityMirrors LIKE 'id'")->fetch()) {
                $db->exec("ALTER TABLE entityMirrors ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY FIRST");
                try { $db->exec("ALTER TABLE entityMirrors DROP PRIMARY KEY"); } catch (Exception $e) {}
                $db->exec("ALTER TABLE entityMirrors ADD UNIQUE KEY unique_mirror (entityType, entityId, urlType, quality, mirrorUrl(255))");
                try { $db->exec("ALTER TABLE entityMirrors ADD COLUMN source VARCHAR(50) DEFAULT 'custom'"); } catch (Exception $e) {}
            } else {
                try { $db->exec("ALTER TABLE entityMirrors ADD COLUMN source VARCHAR(50) DEFAULT 'custom'"); } catch (Exception $e) {}
            }
        } else {
            $db->exec("CREATE TABLE entityMirrors (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entityType VARCHAR(50) NOT NULL,
                entityId VARCHAR(255) NOT NULL,
                urlType VARCHAR(50) NOT NULL,
                mirrorUrl TEXT NOT NULL,
                quality VARCHAR(10),
                source VARCHAR(50) DEFAULT 'custom',
                updatedAt DATETIME,
                UNIQUE KEY unique_mirror (entityType, entityId, urlType, quality, mirrorUrl(255))
            ) ENGINE=InnoDB");
        }
    } catch (Exception $e) { /* ignore */ }

    // Core tables
    $db->exec("CREATE TABLE IF NOT EXISTS artists (artistId VARCHAR(255) PRIMARY KEY) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS collections (collectionId VARCHAR(255) PRIMARY KEY) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS tracks (trackId VARCHAR(255) PRIMARY KEY, isStreamable TINYINT(1) DEFAULT 0) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS trackLyrics (
        trackId VARCHAR(255) PRIMARY KEY,
        lyrics TEXT NOT NULL,
        type ENUM('synced','unsynced') DEFAULT 'unsynced',
        source VARCHAR(50) DEFAULT 'custom',
        updatedAt DATETIME,
        FOREIGN KEY (trackId) REFERENCES tracks(trackId) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // Migrate old lyrics
    try {
        if ($db->query("SHOW COLUMNS FROM tracks LIKE 'lyrics'")->fetch()) {
            $db->exec("INSERT IGNORE INTO trackLyrics (trackId, lyrics, type, source, updatedAt)
                       SELECT trackId, lyrics, 'unsynced', 'custom', NOW() FROM tracks WHERE lyrics IS NOT NULL AND lyrics != ''");
            $db->exec("ALTER TABLE tracks DROP COLUMN lyrics");
        }
    } catch (Exception $e) { /* ignore */ }

    $db->exec("CREATE TABLE IF NOT EXISTS requestCache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        endpoint VARCHAR(255) NOT NULL,
        params VARCHAR(2048) NOT NULL,
        resultIds TEXT NOT NULL,
        expiresAt DATETIME NOT NULL,
        lastAccessed DATETIME,
        accessCount INT DEFAULT 0,
        UNIQUE KEY (endpoint, params)
    ) ENGINE=InnoDB");

    $db->exec("CREATE TABLE IF NOT EXISTS rateLimitLog (
        id INT AUTO_INCREMENT PRIMARY KEY,
        apiName VARCHAR(100) NOT NULL,
        lastRequestTime DATETIME NOT NULL,
        requestCount INT DEFAULT 1,
        successfulRequests INT DEFAULT 0,
        failedRequests INT DEFAULT 0,
        blockedUntil DATETIME,
        UNIQUE KEY (apiName)
    ) ENGINE=InnoDB");

    $db->exec("CREATE TABLE IF NOT EXISTS requestHistory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        requestTime DATETIME NOT NULL,
        endpoint TEXT NOT NULL,
        statusCode INT,
        responseTime INT,
        userAgent TEXT,
        success INT DEFAULT 0
    ) ENGINE=InnoDB");

    $db->exec("CREATE TABLE IF NOT EXISTS proxyStatus (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proxyUrl VARCHAR(255) NOT NULL UNIQUE,
        lastUsed DATETIME,
        successCount INT DEFAULT 0,
        failCount INT DEFAULT 0,
        isBlocked INT DEFAULT 0,
        blockedUntil DATETIME,
        responseTimeAvg DECIMAL(10,2) DEFAULT 0
    ) ENGINE=InnoDB");

    $db->exec("CREATE TABLE IF NOT EXISTS downloadQueue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trackId VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        filePath TEXT,
        quality VARCHAR(10),
        addedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
        startedAt DATETIME,
        completedAt DATETIME,
        errorMessage TEXT,
        retryCount INT DEFAULT 0,
        priority INT DEFAULT 0,
        percent INT DEFAULT 0,
        FOREIGN KEY (trackId) REFERENCES tracks(trackId) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_download_status ON downloadQueue(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_download_track ON downloadQueue(trackId)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_mirrors_lookup ON entityMirrors(entityType, entityId)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_lookup ON requestCache(endpoint, params(255))");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_request_history ON requestHistory(requestTime)");

    // Add missing columns
    try {
        if (!$db->query("SHOW COLUMNS FROM tracks LIKE 'isStreamable'")->fetch()) {
            $db->exec("ALTER TABLE tracks ADD COLUMN isStreamable TINYINT(1) DEFAULT 0");
        }
        if (!$db->query("SHOW COLUMNS FROM downloadQueue LIKE 'percent'")->fetch()) {
            $db->exec("ALTER TABLE downloadQueue ADD COLUMN percent INT DEFAULT 0");
        }
    } catch (Exception $e) { /* ignore */ }

    $initialized = true;
}

// ── Dynamic Column Addition ────────────────────────────────
function ensureColumns(PDO $db, string $table, array $data): void
{
    static $existingColumns = [];
    $allowedTables = ['artists', 'collections', 'tracks'];
    if (!in_array($table, $allowedTables)) return;

    if (!isset($existingColumns[$table])) {
        $cols = $db->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN, 0);
        $existingColumns[$table] = array_flip($cols);
    }

    foreach ($data as $col => $value) {
        if (!isset($existingColumns[$table][$col]) && preg_match('/^[a-zA-Z0-9_]+$/', $col)) {
            $db->exec("ALTER TABLE $table ADD COLUMN `$col` TEXT DEFAULT NULL");
            $existingColumns[$table][$col] = true;
            error_log("Added column `$col` to table $table");
        }
    }
}

// ── Entity Saving ───────────────────────────────────────────
function saveEntitiesFromApi(PDO $db, string $table, array $entities): void
{
    if (empty($entities)) return;
    if (isset($entities['wrapperType']) || isset($entities['artistId']) || isset($entities['collectionId']) || isset($entities['trackId'])) {
        $entities = [$entities];
    }

    $expectedWrapper = match ($table) {
        'artists' => 'artist',
        'collections' => 'collection',
        'tracks' => 'track',
        default => null,
    };
    if ($expectedWrapper === null) return;

    $db->beginTransaction();
    foreach ($entities as $entity) {
        if (!is_array($entity)) continue;
        if (isset($entity['wrapperType']) && $entity['wrapperType'] !== $expectedWrapper) continue;

        $pkCol = match ($table) {
            'artists' => 'artistId',
            'collections' => 'collectionId',
            'tracks' => 'trackId',
            default => null,
        };
        if (!$pkCol || !isset($entity[$pkCol])) continue;

        ensureColumns($db, $table, $entity);
        $id = $entity[$pkCol];

        $stmt = getStatement("SELECT 1 FROM $table WHERE $pkCol = :id");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        $exists = $stmt->fetch();

        if (!$exists) {
            $columns = array_keys($entity);
            $placeholders = implode(',', array_map(fn($c) => ":$c", $columns));
            $ins = $db->prepare("INSERT INTO $table (`" . implode('`,`', $columns) . "`) VALUES ($placeholders)");
            foreach ($entity as $col => $val) {
                $ins->bindValue(":$col", $val, is_int($val) ? PDO::PARAM_INT : (is_float($val) ? PDO::PARAM_STR : PDO::PARAM_STR));
            }
            $ins->execute();
        } else {
            $updates = [];
            foreach ($entity as $col => $val) {
                if ($col !== $pkCol && $col !== 'lyrics') {
                    $updates[] = "`$col` = :$col";
                }
            }
            if (!empty($updates)) {
                $upd = $db->prepare("UPDATE $table SET " . implode(',', $updates) . " WHERE $pkCol = :id");
                foreach ($entity as $col => $val) {
                    if ($col !== $pkCol && $col !== 'lyrics') {
                        $upd->bindValue(":$col", $val, is_int($val) ? PDO::PARAM_INT : (is_float($val) ? PDO::PARAM_STR : PDO::PARAM_STR));
                    }
                }
                $upd->bindValue(':id', $id);
                $upd->execute();
            }
        }

        if ($table === 'tracks') updateStreamableStatus($db, $id);
    }
    $db->commit();
}

// ── Mirror/Attachment Helpers ──────────────────────────────
function getAudioUrlTypeWithQuality(string $urlType, ?string $quality = null): string
{
    if ($urlType !== 'audioUrl' || !$quality) return $urlType;
    if (!in_array($quality, SUPPORTED_AUDIO_QUALITIES)) $quality = DEFAULT_AUDIO_QUALITY;
    return $urlType . '_' . $quality;
}

function extractQualityFromUrlType(string $urlType): ?string
{
    if (strpos($urlType, 'audioUrl_') === 0) {
        $qual = substr($urlType, 9);
        return in_array($qual, SUPPORTED_AUDIO_QUALITIES) ? $qual : null;
    }
    return null;
}

function getBestAvailableQuality(array $mirrors): ?array
{
    foreach (SUPPORTED_AUDIO_QUALITIES as $qual) {
        $key = 'audioUrl_' . $qual;
        if (isset($mirrors[$key]) && !empty($mirrors[$key])) {
            return ['url' => $mirrors[$key][0]['url'], 'quality' => $qual];
        }
    }
    if (isset($mirrors['audioUrl']) && !empty($mirrors['audioUrl'])) {
        return ['url' => $mirrors['audioUrl'][0]['url'], 'quality' => $mirrors['audioUrl'][0]['quality'] ?? DEFAULT_AUDIO_QUALITY];
    }
    return null;
}

function attachAttachments(array &$entity, string $type, string $id, ?string $requestedQuality = null): void
{
    $db = getDB();

    // Collect iTunes artwork URLs
    $artworkUrls = [];
    foreach ($entity as $key => $value) {
        if (strpos($key, 'artworkUrl') === 0 && $key !== 'artworkUrl' && !is_null($value)) {
            $size = substr($key, strlen('artworkUrl'));
            if (is_numeric($size)) {
                $size = $size . 'x' . $size;
                $artworkUrls[] = ['size' => $size, 'url' => $value, 'source' => 'itunes'];
            }
        }
    }
    if (isset($entity['artworkUrl']) && !empty($entity['artworkUrl'])) {
        $found = false;
        foreach ($artworkUrls as $a) {
            if ($a['url'] === $entity['artworkUrl']) { $found = true; break; }
        }
        if (!$found) {
            $artworkUrls[] = ['size' => 'original', 'url' => $entity['artworkUrl'], 'source' => 'itunes'];
        }
    }

    // Fetch mirrors from DB
    $stmt = getStatement("SELECT id, urlType, mirrorUrl, quality, source FROM entityMirrors WHERE entityType=:t AND entityId=:id");
    $stmt->bindValue(':t', $type);
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    $mirrors = [];
    while ($row = $stmt->fetch()) {
        $ut = $row['urlType'];
        if (!isset($mirrors[$ut])) $mirrors[$ut] = [];
        $mirrors[$ut][] = [
            'id' => $row['id'],
            'url' => $row['mirrorUrl'],
            'quality' => $row['quality'],
            'source' => $row['source'] ?? 'custom'
        ];
    }

    // Build attachments per type
    $attachments = [];

    // Artwork
    $artworkFromMirrors = array_map(fn($m) => ['size' => 'mirror', 'url' => $m['url'], 'source' => $m['source']], $mirrors['artworkUrl'] ?? []);
    $attachments['artworkUrls'] = array_merge($artworkUrls, $artworkFromMirrors);

    if ($type === 'artist') {
        $attachments['bannerUrls'] = array_map(fn($m) => ['url' => $m['url'], 'source' => $m['source']], $mirrors['bannerUrl'] ?? []);
        $attachments['previewUrls'] = null;
        $attachments['audioUrls'] = null;
        $attachments['lyrics'] = null;
    } elseif ($type === 'collection') {
        $attachments['previewUrls'] = null;
        $attachments['audioUrls'] = null;
        $attachments['lyrics'] = null;
    } else { // track
        $previewUrls = [];
        if (isset($entity['previewUrl']) && !empty($entity['previewUrl'])) {
            $previewUrls[] = ['url' => $entity['previewUrl'], 'source' => 'itunes'];
        }
        foreach ($mirrors['previewUrl'] ?? [] as $m) {
            $previewUrls[] = ['url' => $m['url'], 'source' => $m['source']];
        }
        $attachments['previewUrls'] = $previewUrls;

        $audioUrls = [];
        foreach ($mirrors as $urlType => $items) {
            if (strpos($urlType, 'audioUrl') === 0) {
                foreach ($items as $item) {
                    $quality = $item['quality'] ?? null;
                    if (!$quality && $urlType !== 'audioUrl') {
                        $quality = extractQualityFromUrlType($urlType);
                    }
                    $audioUrls[] = [
                        'quality' => $quality ?: 'unknown',
                        'url' => $item['url'],
                        'source' => $item['source']
                    ];
                }
            }
        }
        $attachments['audioUrls'] = $audioUrls;

        // Lyrics
        $lyricsData = getLyrics($db, $id);
        $attachments['lyrics'] = $lyricsData['success'] ? [
            'type' => $lyricsData['type'],
            'text' => $lyricsData['lyrics'],
            'source' => $lyricsData['source'] ?? 'custom'
        ] : null;
    }

    $entity['attachments'] = $attachments;

    // Remove top-level URL fields
    unset($entity['artworkUrl'], $entity['previewUrl'], $entity['audioUrl']);
    foreach (array_keys($entity) as $key) {
        if (strpos($key, 'artworkUrl') === 0 || strpos($key, 'previewUrl') === 0) {
            unset($entity[$key]);
        }
    }

    // Ensure isStreamable is integer
    if (isset($entity['isStreamable'])) {
        $entity['isStreamable'] = (int)$entity['isStreamable'];
    }

    if ($type === 'track') updateStreamableStatus($db, $id);
}

function updateStreamableStatus(PDO $db, string $trackId): void
{
    $stmt = getStatement("SELECT 1 FROM entityMirrors WHERE entityType='track' AND entityId=:id AND urlType LIKE 'audioUrl%' LIMIT 1");
    $stmt->bindValue(':id', $trackId);
    $stmt->execute();
    $hasAudio = (bool)$stmt->fetch();
    $stmt2 = getStatement("UPDATE tracks SET isStreamable = :streamable WHERE trackId = :id");
    $stmt2->bindValue(':streamable', $hasAudio ? 1 : 0, PDO::PARAM_INT);
    $stmt2->bindValue(':id', $trackId);
    $stmt2->execute();
}

// ── Mirror CRUD ────────────────────────────────────────────
function addMirrorUrl(PDO $db, string $type, string $id, string $urlType, string $mirrorUrl, ?string $quality = null, string $source = 'custom'): array
{
    if (!in_array($urlType, ['artworkUrl', 'previewUrl', 'audioUrl', 'bannerUrl'])) {
        return ['success' => false, 'error' => 'Invalid urlType'];
    }
    if (!filter_var($mirrorUrl, FILTER_VALIDATE_URL) && strpos($mirrorUrl, 'tg://') !== 0) {
        return ['success' => false, 'error' => 'Invalid URL'];
    }

    $table = match ($type) {
        'artist' => 'artists',
        'collection' => 'collections',
        'track' => 'tracks',
        default => null,
    };
    if ($table) {
        $pk = $type . 'Id';
        $db->exec("INSERT IGNORE INTO $table ($pk) VALUES ('" . addslashes($id) . "')");
    }

    $actualUrlType = getAudioUrlTypeWithQuality($urlType, $quality);
    $qualityVal = ($urlType === 'audioUrl') ? $quality : null;

    $stmt = getStatement("INSERT IGNORE INTO entityMirrors (entityType, entityId, urlType, mirrorUrl, quality, source, updatedAt) 
                          VALUES (:t, :id, :ut, :url, :q, :src, NOW())");
    $stmt->bindValue(':t', $type);
    $stmt->bindValue(':id', $id);
    $stmt->bindValue(':ut', $actualUrlType);
    $stmt->bindValue(':url', $mirrorUrl);
    $stmt->bindValue(':q', $qualityVal);
    $stmt->bindValue(':src', $source);
    $stmt->execute();

    if ($stmt->rowCount()) {
        $newId = $db->lastInsertId();
        if ($type === 'track') updateStreamableStatus($db, $id);
        return ['success' => true, 'id' => $newId, 'message' => 'Mirror added'];
    }
    return ['success' => false, 'error' => 'Duplicate mirror already exists'];
}

function addMirrorUrlsBatch(PDO $db, array $attachments): array
{
    $results = [];
    foreach ($attachments as $item) {
        if (!isset($item['entityType'], $item['entityId'], $item['urlType'], $item['mirrorUrl'])) {
            $results[] = ['success' => false, 'error' => 'Missing required fields', 'item' => $item];
            continue;
        }
        $res = addMirrorUrl($db, $item['entityType'], $item['entityId'], $item['urlType'], $item['mirrorUrl'], $item['quality'] ?? null, $item['source'] ?? 'custom');
        $results[] = array_merge($res, ['entity' => $item['entityId']]);
    }
    return ['success' => true, 'results' => $results];
}

function getMirrorUrls(PDO $db, string $type, string $id, ?string $urlType = null, ?string $quality = null): array
{
    $sql = "SELECT id, urlType, mirrorUrl, quality, source FROM entityMirrors WHERE entityType = :t AND entityId = :id";
    $params = [':t' => $type, ':id' => $id];
    $stmt = getStatement($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();

    $attachments = ['artworkUrls' => []];
    if ($type === 'artist') $attachments['bannerUrls'] = [];
    if ($type === 'track') {
        $attachments['previewUrls'] = [];
        $attachments['audioUrls'] = [];
        $attachments['lyrics'] = null;
    } else {
        $attachments['previewUrls'] = null;
        $attachments['audioUrls'] = null;
        $attachments['lyrics'] = null;
    }

    while ($row = $stmt->fetch()) {
        $rowType = $row['urlType'];
        if ($urlType && $quality && $rowType !== getAudioUrlTypeWithQuality($urlType, $quality)) continue;

        $item = ['id' => $row['id'], 'url' => $row['mirrorUrl'], 'source' => $row['source'] ?? 'custom'];
        if ($row['quality']) $item['quality'] = $row['quality'];

        if (strpos($rowType, 'audioUrl') === 0 && $type === 'track') {
            $qual = $row['quality'] ?? null;
            if (!$qual && $rowType !== 'audioUrl') $qual = extractQualityFromUrlType($rowType);
            $item['quality'] = $qual ?: 'unknown';
            $attachments['audioUrls'][] = $item;
        } elseif ($rowType === 'artworkUrl') {
            $attachments['artworkUrls'][] = ['size' => 'mirror', 'url' => $row['mirrorUrl'], 'source' => $item['source']];
        } elseif ($rowType === 'previewUrl' && $type === 'track') {
            $attachments['previewUrls'][] = ['url' => $row['mirrorUrl'], 'source' => $item['source']];
        } elseif ($rowType === 'bannerUrl' && $type === 'artist') {
            $attachments['bannerUrls'][] = ['url' => $row['mirrorUrl'], 'source' => $item['source']];
        }
    }

    return ['success' => true, 'entityType' => $type, 'entityId' => $id, 'attachments' => $attachments];
}

function deleteMirrorUrl(PDO $db, string $type, string $id, ?string $urlType = null, ?string $quality = null, ?int $mirrorId = null): array
{
    if ($mirrorId !== null) {
        $stmt = getStatement("DELETE FROM entityMirrors WHERE id = :mid AND entityType = :t AND entityId = :id");
        $stmt->bindValue(':mid', $mirrorId, PDO::PARAM_INT);
        $stmt->bindValue(':t', $type);
        $stmt->bindValue(':id', $id);
    } else {
        if ($urlType) {
            $actual = getAudioUrlTypeWithQuality($urlType, $quality);
            $stmt = getStatement("DELETE FROM entityMirrors WHERE entityType=:t AND entityId=:id AND urlType=:ut");
            $stmt->bindValue(':ut', $actual);
        } else {
            $stmt = getStatement("DELETE FROM entityMirrors WHERE entityType=:t AND entityId=:id");
        }
        $stmt->bindValue(':t', $type);
        $stmt->bindValue(':id', $id);
    }
    $stmt->execute();
    $deleted = $stmt->rowCount();
    if ($type === 'track') updateStreamableStatus($db, $id);
    return ['success' => true, 'deleted_count' => $deleted];
}

// ── Lyrics ──────────────────────────────────────────────────
function getLyrics(PDO $db, string $trackId): array
{
    $stmt = getStatement("SELECT lyrics, type, source FROM trackLyrics WHERE trackId = :id");
    $stmt->bindValue(':id', $trackId);
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row && !empty($row['lyrics'])) {
        return [
            'success' => true,
            'trackId' => $trackId,
            'lyrics' => json_decode($row['lyrics'], true),
            'type' => $row['type'],
            'source' => $row['source'] ?? 'custom'
        ];
    }
    return ['success' => false, 'error' => 'Lyrics not found'];
}

function saveLyrics(PDO $db, string $trackId, $lyrics, string $type = 'unsynced', string $source = 'custom'): array
{
    $lyricsJson = is_string($lyrics) ? $lyrics : json_encode($lyrics);
    if (json_decode($lyricsJson) === null) return ['success' => false, 'error' => 'Invalid JSON'];
    $db->exec("INSERT IGNORE INTO tracks (trackId) VALUES ('" . addslashes($trackId) . "')");
    $stmt = getStatement("REPLACE INTO trackLyrics (trackId, lyrics, type, source, updatedAt) VALUES (:id, :lyrics, :type, :src, NOW())");
    $stmt->bindValue(':id', $trackId);
    $stmt->bindValue(':lyrics', $lyricsJson);
    $stmt->bindValue(':type', $type);
    $stmt->bindValue(':src', $source);
    $stmt->execute();
    return ['success' => true, 'message' => 'Lyrics saved'];
}

function fetchLyricsFromLrclib(string $trackName, string $artistName, ?string $albumName = null): ?array
{
    $params = ['track_name' => $trackName, 'artist_name' => $artistName];
    if ($albumName) $params['album_name'] = $albumName;
    $url = 'https://lrclib.net/api/get?' . http_build_query($params);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if ($data) {
            $type = !empty($data['syncedLyrics']) ? 'synced' : 'unsynced';
            return ['data' => $data, 'type' => $type, 'source' => 'lrclib'];
        }
    }
    return null;
}

// ── Fetch single entity ────────────────────────────────────
function fetchEntityById(PDO $db, string $type, string $id, ?string $quality = null): ?array
{
    $table = match ($type) {
        'artist' => 'artists',
        'collection' => 'collections',
        'track' => 'tracks',
        default => null,
    };
    if (!$table) return null;
    $pk = $type . 'Id';
    $stmt = getStatement("SELECT * FROM $table WHERE $pk = :id");
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row) {
        attachAttachments($row, $type, $id, $quality);
        return $row;
    }
    return null;
}

// ── Caching ─────────────────────────────────────────────────
function getAdaptiveTTL(): int
{
    $db = getDB();
    $stmt = getStatement("SELECT successfulRequests, failedRequests FROM rateLimitLog WHERE apiName='itunes' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    $base = CACHE_DURATION;
    if ($row) {
        $total = $row['successfulRequests'] + $row['failedRequests'];
        if ($total > 0) {
            $rate = $row['successfulRequests'] / $total;
            if ($rate < 0.5) $base *= 4;
            elseif ($rate < 0.7) $base *= 2;
            elseif ($rate < 0.9) $base = (int)($base * 1.5);
        }
    }
    $hour = (int)date('H');
    if ($hour >= 2 && $hour <= 5) $base = (int)($base * 0.7);
    elseif ($hour >= 18 && $hour <= 23) $base = (int)($base * 1.3);
    return $base;
}

function extractResultIds(array $results): string
{
    $ids = [];
    foreach ($results as $item) {
        if (isset($item['wrapperType']) && isset($item[$item['wrapperType'] . 'Id'])) {
            $ids[] = ['type' => $item['wrapperType'], 'id' => $item[$item['wrapperType'] . 'Id']];
        }
    }
    return json_encode($ids);
}

function saveCacheIds(PDO $db, string $endpoint, array $params, array $results): void
{
    $idsJson = extractResultIds($results);
    if ($idsJson === '[]') return;
    $paramsJson = json_encode($params);
    $ttl = CACHE_ADAPTIVE_TTL ? getAdaptiveTTL() : CACHE_DURATION;
    $expires = date('Y-m-d H:i:s', time() + $ttl);
    $stmt = getStatement("REPLACE INTO requestCache (endpoint, params, resultIds, expiresAt, lastAccessed, accessCount) VALUES (:ep, :p, :ids, :ex, NOW(), 1)");
    $stmt->bindValue(':ep', $endpoint);
    $stmt->bindValue(':p', $paramsJson);
    $stmt->bindValue(':ids', $idsJson);
    $stmt->bindValue(':ex', $expires);
    $stmt->execute();
}

function getCachedResults(PDO $db, string $endpoint, array $params): ?array
{
    $paramsJson = json_encode($params);
    $stmt = getStatement("SELECT resultIds, expiresAt FROM requestCache WHERE endpoint=:ep AND params=:p AND expiresAt > NOW() LIMIT 1");
    $stmt->bindValue(':ep', $endpoint);
    $stmt->bindValue(':p', $paramsJson);
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) return null;
    $stmt = getStatement("UPDATE requestCache SET accessCount = accessCount + 1, lastAccessed = NOW() WHERE endpoint=:ep AND params=:p");
    $stmt->bindValue(':ep', $endpoint);
    $stmt->bindValue(':p', $paramsJson);
    $stmt->execute();
    $ids = json_decode($row['resultIds'], true);
    if (!$ids) return null;
    $results = [];
    foreach ($ids as $entry) {
        $entity = fetchEntityById($db, $entry['type'], $entry['id'], $params['quality'] ?? null);
        if ($entity) $results[] = $entity;
    }
    return ['resultCount' => count($results), 'results' => $results];
}

function cleanExpiredCache(PDO $db): void
{
    $now = time();
    $stmt = getStatement("SELECT lastRequestTime FROM rateLimitLog WHERE apiName = 'system_cleanup' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    $lastCleanup = $row ? strtotime($row['lastRequestTime']) : 0;
    if (($now - $lastCleanup) > 1800) {
        $db->exec("DELETE FROM requestCache WHERE expiresAt < NOW()");
        $db->exec("DELETE FROM requestHistory WHERE requestTime < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $db->exec("UPDATE proxyStatus SET isBlocked = 0, blockedUntil = NULL WHERE blockedUntil < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt = getStatement("REPLACE INTO rateLimitLog (apiName, lastRequestTime) VALUES ('system_cleanup', NOW())");
        $stmt->execute();
    }
}

// ── Rate Limiting & Proxies ──────────────────────────────
function checkRateLimit(string $api = 'itunes'): bool
{
    global $lastRequestTime;
    if (ENABLE_REQUEST_THROTTLING) {
        $now = microtime(true);
        $elapsed = ($now - $lastRequestTime) * 1000000;
        if ($lastRequestTime > 0 && $elapsed < THROTTLE_MIN_INTERVAL) usleep(THROTTLE_MIN_INTERVAL - $elapsed);
        $lastRequestTime = microtime(true);
    }
    return true;
}

function handleRateLimitHit(string $api = 'itunes'): void {}
function resetRateLimit(string $api = 'itunes', bool $success = true): void {}

function loadProxies(): array
{
    if (!file_exists(PROXY_LIST_FILE)) return [];
    $lines = file(PROXY_LIST_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_filter($lines, fn($l) => strpos($l, '://') !== false);
}

function getNextProxy(): ?string
{
    global $currentProxyIndex;
    $proxies = loadProxies();
    if (empty($proxies)) return null;
    for ($i = 0; $i < count($proxies); $i++) {
        $idx = ($currentProxyIndex + $i) % count($proxies);
        $proxy = $proxies[$idx];
        $db = getDB();
        $stmt = getStatement("SELECT isBlocked, blockedUntil FROM proxyStatus WHERE proxyUrl = :url");
        $stmt->bindValue(':url', $proxy);
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row || !$row['isBlocked'] || strtotime($row['blockedUntil']) < time()) {
            $currentProxyIndex = ($idx + 1) % count($proxies);
            $stmt = getStatement("REPLACE INTO proxyStatus (proxyUrl, lastUsed) VALUES (:url, NOW())");
            $stmt->bindValue(':url', $proxy);
            $stmt->execute();
            return $proxy;
        }
    }
    return null;
}

function rotateProxy(): ?string { return getNextProxy(); }

function markProxyStatus(string $proxy, bool $success): void
{
    $db = getDB();
    if ($success) {
        $stmt = getStatement("UPDATE proxyStatus SET successCount = successCount + 1, isBlocked = 0 WHERE proxyUrl = :url");
    } else {
        $stmt = getStatement("UPDATE proxyStatus SET failCount = failCount + 1, isBlocked = 1, blockedUntil = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE proxyUrl = :url");
    }
    $stmt->bindValue(':url', $proxy);
    $stmt->execute();
}

// ── iTunes API Calls ──────────────────────────────────────
function makeApiRequest(string $url, int $retry = 0): ?array
{
    if (!checkRateLimit()) {
        if ($retry < RATE_LIMIT_MAX_RETRIES) {
            usleep((RATE_LIMIT_BASE_DELAY * pow(2, $retry) + mt_rand(0, 1000000) / 1e6) * 1e6);
            return makeApiRequest($url, $retry + 1);
        }
        return null;
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => true,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_FRESH_CONNECT => true,
    ]);
    global $userAgents;
    if (ENABLE_USER_AGENT_ROTATION) curl_setopt($ch, CURLOPT_USERAGENT, $userAgents[array_rand($userAgents)]);
    $currentProxy = null;
    if (USE_PROXY_ROTATION && ($currentProxy = getNextProxy())) curl_setopt($ch, CURLOPT_PROXY, $currentProxy);
    if (ENABLE_IP_SPOOFING) {
        $ip = mt_rand(1, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 255);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Forwarded-For: ' . $ip, 'X-Real-IP: ' . $ip, 'Client-IP: ' . $ip]);
    }
    usleep(mt_rand(100000, 500000));
    curl_setopt($ch, CURLOPT_URL, $url);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($response, $headerSize);
    $db = getDB();
    $stmt = getStatement("INSERT INTO requestHistory (requestTime, endpoint, statusCode, responseTime, success) VALUES (NOW(), :ep, :code, :time, :success)");
    $stmt->bindValue(':ep', $url);
    $stmt->bindValue(':code', $httpCode, PDO::PARAM_INT);
    $stmt->bindValue(':time', curl_getinfo($ch, CURLINFO_TOTAL_TIME_T), PDO::PARAM_INT);
    $stmt->bindValue(':success', $httpCode === 200 ? 1 : 0, PDO::PARAM_INT);
    $stmt->execute();
    curl_close($ch);
    if ($httpCode === 200) {
        resetRateLimit('itunes', true);
        if ($currentProxy) markProxyStatus($currentProxy, true);
        return json_decode($body, true);
    } elseif ($httpCode === 429) {
        handleRateLimitHit('itunes');
        if ($currentProxy) markProxyStatus($currentProxy, false);
        if ($retry < RATE_LIMIT_MAX_RETRIES) return makeApiRequest($url, $retry + 1);
        return null;
    } elseif (in_array($httpCode, [403, 503]) && $retry < RATE_LIMIT_MAX_RETRIES) {
        rotateProxy();
        sleep(mt_rand(5, 15));
        return makeApiRequest($url, $retry + 1);
    }
    return null;
}

function searchLocalDatabase(array $params): array
{
    $db = getDB();
    $results = [];
    if (isset($params['term'])) {
        $term = '%' . strtolower($params['term']) . '%';
        $entity = $params['entity'] ?? 'all';
        $limit = min((int)($params['limit'] ?? 50), 500);
        $quality = $params['quality'] ?? null;

        $queries = [];
        if ($entity === 'all' || $entity === 'musicArtist') {
            $queries[] = ['table' => 'artists', 'type' => 'artist', 'idCol' => 'artistId', 'nameCol' => 'artistName', 'wrapper' => 'artist'];
        }
        if ($entity === 'all' || $entity === 'collection') {
            $queries[] = ['table' => 'collections', 'type' => 'collection', 'idCol' => 'collectionId', 'nameCol' => 'collectionName', 'wrapper' => 'collection'];
        }
        if ($entity === 'all' || $entity === 'song') {
            $queries[] = ['table' => 'tracks', 'type' => 'track', 'idCol' => 'trackId', 'nameCol' => 'trackName', 'wrapper' => 'track'];
        }

        foreach ($queries as $q) {
            $stmt = getStatement("SELECT *, '{$q['wrapper']}' as wrapperType FROM {$q['table']} WHERE LOWER({$q['nameCol']}) LIKE :term LIMIT :limit");
            $stmt->bindValue(':term', $term);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch()) {
                attachAttachments($row, $q['type'], $row[$q['idCol']], $quality);
                $results[] = $row;
            }
        }
    } elseif (isset($params['id'])) {
        $ids = explode(',', $params['id']);
        foreach ($ids as $rawId) {
            $id = trim($rawId);
            foreach (['artist', 'collection', 'track'] as $type) {
                $entity = fetchEntityById($db, $type, $id, $params['quality'] ?? null);
                if ($entity) {
                    $results[] = $entity;
                    break;
                }
            }
        }
    }
    return ['resultCount' => count($results), 'results' => $results, 'fromCache' => true];
}

function makeApiRequestWithFallback(string $url, array $params, int $retry = 0): array
{
    $response = makeApiRequest($url, $retry);
    if ($response && isset($response['results'])) {
        $response['source'] = 'api';
        return $response;
    }
    return searchLocalDatabase($params);
}

// ── Core Search/Lookup Functions ──────────────────────────
function processApiResults(PDO $db, array &$results, ?string $quality = null): void
{
    // Save each entity type
    $artists = array_filter($results, fn($item) => ($item['wrapperType'] ?? '') === 'artist');
    $collections = array_filter($results, fn($item) => ($item['wrapperType'] ?? '') === 'collection');
    $tracks = array_filter($results, fn($item) => ($item['wrapperType'] ?? '') === 'track');

    if (!empty($artists)) saveEntitiesFromApi($db, 'artists', $artists);
    if (!empty($collections)) saveEntitiesFromApi($db, 'collections', $collections);
    if (!empty($tracks)) saveEntitiesFromApi($db, 'tracks', $tracks);

    // Now attach attachments to each result
    foreach ($results as &$item) {
        $type = $item['wrapperType'] ?? null;
        if ($type === 'artist') attachAttachments($item, 'artist', $item['artistId'], $quality);
        elseif ($type === 'collection') attachAttachments($item, 'collection', $item['collectionId'], $quality);
        elseif ($type === 'track') attachAttachments($item, 'track', $item['trackId'], $quality);
    }
    unset($item);
}

/**
 * Ensure every result has 'attachments' and convert isStreamable to int.
 */
function ensureResponseAttachments(array &$response, array $params): void
{
    if (!isset($response['results']) || !is_array($response['results'])) return;
    foreach ($response['results'] as &$item) {
        if (!isset($item['attachments'])) {
            $type = $item['wrapperType'] ?? null;
            if ($type === 'artist') attachAttachments($item, 'artist', $item['artistId'], $params['quality'] ?? null);
            elseif ($type === 'collection') attachAttachments($item, 'collection', $item['collectionId'], $params['quality'] ?? null);
            elseif ($type === 'track') attachAttachments($item, 'track', $item['trackId'], $params['quality'] ?? null);
        }
        if (isset($item['isStreamable'])) {
            $item['isStreamable'] = (int)$item['isStreamable'];
        }
    }
    unset($item);
}

function searchiTunes(PDO $db, array $params): array
{
    if (!isset($params['entity'])) $params['entity'] = 'musicArtist,album,song';
    $params['media'] = 'music';

    $cached = getCachedResults($db, 'search', $params);
    if ($cached) {
        ensureResponseAttachments($cached, $params);
        return $cached;
    }

    $url = ITUNES_SEARCH_API . '?' . http_build_query($params);
    $response = makeApiRequestWithFallback($url, $params);
    if (isset($response['source']) && $response['source'] === 'api' && !empty($response['results'])) {
        processApiResults($db, $response['results'], $params['quality'] ?? null);
        saveCacheIds($db, 'search', $params, $response['results']);
    }
    ensureResponseAttachments($response, $params);
    return $response ?? ['resultCount' => 0, 'results' => []];
}

function lookupiTunes(PDO $db, array $params): array
{
    $cached = getCachedResults($db, 'lookup', $params);
    if ($cached) {
        ensureResponseAttachments($cached, $params);
        return $cached;
    }

    if (isset($params['id'])) {
        $ids = array_map('trim', explode(',', $params['id']));
        $params['id'] = implode(',', $ids);
    }
    $url = ITUNES_LOOKUP_API . '?' . http_build_query($params);
    $response = makeApiRequestWithFallback($url, $params);
    if (isset($response['source']) && $response['source'] === 'api' && !empty($response['results'])) {
        processApiResults($db, $response['results'], $params['quality'] ?? null);
        saveCacheIds($db, 'lookup', $params, $response['results']);
    }
    ensureResponseAttachments($response, $params);
    return $response ?? ['resultCount' => 0, 'results' => []];
}

// ── Additional Endpoints ──────────────────────────────────
function handleBatchLookup(PDO $db, array $params): array
{
    if (empty($params['ids'])) throw new Exception('Missing ids parameter (comma-separated)', 400);
    $ids = array_map('trim', explode(',', $params['ids']));
    $results = [];
    foreach ($ids as $id) {
        $found = false;
        foreach (['artist', 'collection', 'track'] as $type) {
            $entity = fetchEntityById($db, $type, $id, $params['quality'] ?? null);
            if ($entity) {
                $results[] = $entity;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $lookup = lookupiTunes($db, ['id' => $id, 'quality' => $params['quality'] ?? null]);
            if (!empty($lookup['results'])) $results[] = $lookup['results'][0];
        }
    }
    return ['resultCount' => count($results), 'results' => $results];
}

function handlePopular(PDO $db, array $params): array
{
    $limit = min((int)($params['limit'] ?? 20), 100);
    $stmt = getStatement("SELECT * FROM tracks ORDER BY trackId DESC LIMIT :limit");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $tracks = [];
    while ($row = $stmt->fetch()) {
        attachAttachments($row, 'track', $row['trackId'], $params['quality'] ?? null);
        $tracks[] = $row;
    }
    return ['resultCount' => count($tracks), 'results' => $tracks];
}

function handleCacheClear(PDO $db): array
{
    $db->exec("DELETE FROM requestCache");
    return ['success' => true, 'message' => 'All cache cleared'];
}

function handleStats(PDO $db): array
{
    $cacheCount = $db->query("SELECT COUNT(*) as total FROM requestCache WHERE expiresAt > NOW()")->fetch()['total'];
    $trackCount = $db->query("SELECT COUNT(*) as total FROM tracks")->fetch()['total'];
    $artistCount = $db->query("SELECT COUNT(*) as total FROM artists")->fetch()['total'];
    $albumCount = $db->query("SELECT COUNT(*) as total FROM collections")->fetch()['total'];
    return [
        'cache_entries' => $cacheCount,
        'track_count' => $trackCount,
        'artist_count' => $artistCount,
        'album_count' => $albumCount,
        'db_size_bytes' => 0,
        'uptime_seconds' => time() - (filemtime(__FILE__) ?? time()),
    ];
}

function handleProxyStatus(PDO $db): array
{
    $stmt = $db->query("SELECT proxyUrl, successCount, failCount, isBlocked, lastUsed FROM proxyStatus ORDER BY successCount DESC");
    return ['proxies' => $stmt->fetchAll()];
}

function handleResetRateLimit(PDO $db): array
{
    $db->exec("DELETE FROM rateLimitLog");
    $db->exec("DELETE FROM requestHistory WHERE success = 0 AND requestTime > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    return ['success' => true, 'message' => 'Rate limit counters reset'];
}

// ── Download Manager Functions ────────────────────────────
function resolveTrackIdsFromInput(PDO $db, array $params): array
{
    $trackIds = [];
    if (!empty($params['trackId'])) {
        $ids = is_array($params['trackId']) ? $params['trackId'] : explode(',', $params['trackId']);
        $trackIds = array_merge($trackIds, array_map('trim', $ids));
    }
    if (!empty($params['albumId'])) {
        $albumId = $params['albumId'];
        $stmt = getStatement("SELECT trackId FROM tracks WHERE collectionId = :aid");
        $stmt->bindValue(':aid', $albumId);
        $stmt->execute();
        $found = false;
        while ($row = $stmt->fetch()) {
            $trackIds[] = $row['trackId'];
            $found = true;
        }
        if (!$found) {
            $lookup = lookupiTunes($db, ['id' => $albumId, 'entity' => 'song']);
            if (!empty($lookup['results'])) {
                $stmt2 = getStatement("SELECT trackId FROM tracks WHERE collectionId = :aid");
                $stmt2->bindValue(':aid', $albumId);
                $stmt2->execute();
                while ($row = $stmt2->fetch()) $trackIds[] = $row['trackId'];
            }
        }
    }
    if (!empty($params['artistId'])) {
        $artistId = $params['artistId'];
        $stmt = getStatement("SELECT trackId FROM tracks WHERE artistId = :aid");
        $stmt->bindValue(':aid', $artistId);
        $stmt->execute();
        while ($row = $stmt->fetch()) $trackIds[] = $row['trackId'];
    }
    return array_unique($trackIds);
}

function handleDownloadAdd(PDO $db, array $params): array
{
    $trackIds = resolveTrackIdsFromInput($db, $params);
    if (empty($trackIds)) throw new Exception('No tracks resolved. Provide trackId, albumId, or artistId.', 400);

    $quality = $params['quality'] ?? DEFAULT_AUDIO_QUALITY;
    $priority = (int)($params['priority'] ?? 0);
    $skipExisting = filter_var($params['skipExisting'] ?? true, FILTER_VALIDATE_BOOL);
    $force = filter_var($params['force'] ?? false, FILTER_VALIDATE_BOOL);
    $initialStatus = $params['status'] ?? DOWNLOAD_STATUS_PENDING;
    if (!in_array($initialStatus, [DOWNLOAD_STATUS_PENDING, DOWNLOAD_STATUS_DOWNLOADING, DOWNLOAD_STATUS_PAUSED])) {
        $initialStatus = DOWNLOAD_STATUS_PENDING;
    }

    $added = $skipped = $failed = [];
    $db->beginTransaction();
    foreach ($trackIds as $tid) {
        if ($skipExisting) {
            $stmt = getStatement("SELECT id, status FROM downloadQueue WHERE trackId = :tid AND status NOT IN ('completed', 'failed', 'stopped')");
            $stmt->bindValue(':tid', $tid);
            $stmt->execute();
            if ($stmt->fetch()) {
                $skipped[] = ['trackId' => $tid, 'reason' => 'Already in queue'];
                continue;
            }
        }

        $track = fetchEntityById($db, 'track', $tid, $quality);
        if (!$track) {
            $lookup = lookupiTunes($db, ['id' => $tid]);
            if (empty($lookup['results'])) {
                $failed[] = ['trackId' => $tid, 'reason' => 'Track not found in iTunes'];
                continue;
            }
            $track = $lookup['results'][0];
            attachAttachments($track, 'track', $tid, $quality);
        }

        $hasAudio = isset($track['attachments']['audioUrls']) && count($track['attachments']['audioUrls']) > 0;
        $finalStatus = ($hasAudio && !$force) ? DOWNLOAD_STATUS_COMPLETED : $initialStatus;
        $completedAt = ($finalStatus === DOWNLOAD_STATUS_COMPLETED) ? 'NOW()' : 'NULL';

        $stmt = getStatement("INSERT INTO downloadQueue (trackId, status, quality, priority, addedAt, completedAt) 
                              VALUES (:tid, :status, :qual, :prio, NOW(), $completedAt)");
        $stmt->bindValue(':tid', $tid);
        $stmt->bindValue(':status', $finalStatus);
        $stmt->bindValue(':qual', $quality);
        $stmt->bindValue(':prio', $priority, PDO::PARAM_INT);
        $stmt->execute();

        $downloadId = $db->lastInsertId();
        $trackData = fetchEntityById($db, 'track', $tid, $quality) ?: $track;
        $added[] = ['downloadId' => $downloadId, 'trackId' => $tid, 'track' => $trackData];
    }
    $db->commit();

    return [
        'success' => true,
        'added_count' => count($added),
        'skipped_count' => count($skipped),
        'failed_count' => count($failed),
        'added' => $added,
        'skipped' => $skipped,
        'failed' => $failed
    ];
}

function handleDownloadQueue(PDO $db, array $params): array
{
    $status = $params['status'] ?? null;
    $limit = min((int)($params['limit'] ?? 100), 2000);
    $offset = (int)($params['offset'] ?? 0);
    $quality = $params['quality'] ?? null;

    $sql = "SELECT d.* FROM downloadQueue d";
    $countSql = "SELECT COUNT(*) as total FROM downloadQueue d";
    if ($status && in_array($status, [DOWNLOAD_STATUS_PENDING, DOWNLOAD_STATUS_DOWNLOADING, DOWNLOAD_STATUS_PAUSED, DOWNLOAD_STATUS_COMPLETED, DOWNLOAD_STATUS_FAILED, DOWNLOAD_STATUS_STOPPED])) {
        $sql .= " WHERE d.status = :status";
        $countSql .= " WHERE d.status = :status";
    }
    $sql .= " ORDER BY d.priority DESC, d.addedAt ASC LIMIT :limit OFFSET :offset";

    $stmt = getStatement($sql);
    $countStmt = getStatement($countSql);
    if ($status) {
        $stmt->bindValue(':status', $status);
        $countStmt->bindValue(':status', $status);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = [];
    while ($row = $stmt->fetch()) {
        $tid = $row['trackId'];
        $trackData = fetchEntityById($db, 'track', $tid, $quality) ?: ['trackId' => $tid];
        $downloadMeta = [
            'download_id' => $row['id'],
            'download_status' => $row['status'],
            'file_path' => $row['filePath'],
            'quality' => $row['quality'],
            'added_at' => $row['addedAt'],
            'started_at' => $row['startedAt'],
            'completed_at' => $row['completedAt'],
            'error_message' => $row['errorMessage'],
            'retry_count' => $row['retryCount'],
            'priority' => $row['priority'],
            'percent' => (int)$row['percent']
        ];
        $items[] = array_merge($trackData, $downloadMeta);
    }
    $countStmt->execute();
    $total = $countStmt->fetch()['total'] ?? 0;
    return ['success' => true, 'total' => (int)$total, 'limit' => $limit, 'offset' => $offset, 'items' => $items];
}

function handleDownloadStatus(PDO $db, array $params): array
{
    $id = $params['id'] ?? null;
    $trackId = $params['trackId'] ?? null;
    if (!$id && !$trackId) throw new Exception('Missing id or trackId parameter', 400);
    $quality = $params['quality'] ?? null;

    if ($id) {
        $stmt = getStatement("SELECT * FROM downloadQueue WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    } else {
        $stmt = getStatement("SELECT * FROM downloadQueue WHERE trackId = :tid ORDER BY id DESC LIMIT 1");
        $stmt->bindValue(':tid', $trackId);
    }
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) return ['success' => false, 'error' => 'Download entry not found'];

    $tid = $row['trackId'];
    $trackData = fetchEntityById($db, 'track', $tid, $quality) ?: ['trackId' => $tid];
    $downloadMeta = [
        'download_id' => $row['id'],
        'download_status' => $row['status'],
        'file_path' => $row['filePath'],
        'quality' => $row['quality'],
        'added_at' => $row['addedAt'],
        'started_at' => $row['startedAt'],
        'completed_at' => $row['completedAt'],
        'error_message' => $row['errorMessage'],
        'retry_count' => $row['retryCount'],
        'priority' => $row['priority'],
        'percent' => (int)$row['percent']
    ];
    return ['success' => true, 'download' => array_merge($trackData, $downloadMeta)];
}

function handleDownloadUpdate(PDO $db, array $params): array
{
    $idParam = $params['id'] ?? $params['ids'] ?? null;
    $trackIdsRaw = $params['trackIds'] ?? [];
    $filterStatus = $params['filterStatus'] ?? null;
    $status = $params['status'] ?? null;
    $filePath = $params['filePath'] ?? null;
    $errorMessage = $params['errorMessage'] ?? null;
    $percent = isset($params['percent']) ? (int)$params['percent'] : null;

    $targetIds = [];
    if ($idParam !== null) {
        $idArray = is_array($idParam) ? $idParam : explode(',', (string)$idParam);
        $targetIds = array_map('intval', $idArray);
    } elseif (!empty($trackIdsRaw)) {
        $trackIds = is_array($trackIdsRaw) ? $trackIdsRaw : explode(',', (string)$trackIdsRaw);
        $placeholders = implode(',', array_fill(0, count($trackIds), '?'));
        $stmt = $db->prepare("SELECT id FROM downloadQueue WHERE trackId IN ($placeholders)");
        foreach ($trackIds as $i => $tid) $stmt->bindValue($i + 1, $tid);
        $stmt->execute();
        while ($row = $stmt->fetch()) $targetIds[] = $row['id'];
    } elseif ($filterStatus !== null) {
        $stmt = $db->prepare("SELECT id FROM downloadQueue WHERE status = :status");
        $stmt->bindValue(':status', $filterStatus);
        $stmt->execute();
        while ($row = $stmt->fetch()) $targetIds[] = $row['id'];
    }

    if (empty($targetIds)) return ['success' => true, 'updated_count' => 0, 'message' => 'No matching entries'];

    $updates = [];
    $bindings = [];
    if ($status !== null && $status !== '') {
        if (!in_array($status, [DOWNLOAD_STATUS_PENDING, DOWNLOAD_STATUS_DOWNLOADING, DOWNLOAD_STATUS_PAUSED, DOWNLOAD_STATUS_COMPLETED, DOWNLOAD_STATUS_FAILED, DOWNLOAD_STATUS_STOPPED])) {
            throw new Exception('Invalid status', 400);
        }
        $updates[] = "status = ?";
        $bindings[] = $status;
        if ($status === DOWNLOAD_STATUS_DOWNLOADING) $updates[] = "startedAt = COALESCE(startedAt, NOW())";
        if ($status === DOWNLOAD_STATUS_COMPLETED) {
            $updates[] = "completedAt = NOW()";
            $updates[] = "errorMessage = NULL";
        }
    }
    if ($filePath !== null) { $updates[] = "filePath = ?"; $bindings[] = $filePath; }
    if ($errorMessage !== null) {
        $updates[] = "errorMessage = ?";
        $bindings[] = $errorMessage;
        if ($status === null) { $updates[] = "status = ?"; $bindings[] = DOWNLOAD_STATUS_FAILED; }
    }
    if ($percent !== null) { $updates[] = "percent = ?"; $bindings[] = $percent; }

    if (empty($updates)) throw new Exception('Nothing to update', 400);

    $db->beginTransaction();
    try {
        $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
        $stmt = $db->prepare("UPDATE downloadQueue SET " . implode(', ', $updates) . " WHERE id IN ($placeholders)");
        $pos = 1;
        foreach ($bindings as $val) {
            $stmt->bindValue($pos++, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        foreach ($targetIds as $id) $stmt->bindValue($pos++, $id, PDO::PARAM_INT);
        $stmt->execute();
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    return ['success' => true, 'updated_count' => count($targetIds), 'message' => 'Updated successfully'];
}

function handleDownloadDelete(PDO $db, array $params): array
{
    $idParam = $params['id'] ?? null;
    $idsParam = $params['ids'] ?? null;
    $trackIdsRaw = $params['trackIds'] ?? [];
    $status = $params['status'] ?? null;
    $all = filter_var($params['all'] ?? false, FILTER_VALIDATE_BOOL);
    $singleId = $params['id'] ?? null;
    $singleTrackId = $params['trackId'] ?? null;

    if (!$idParam && !$idsParam && !$trackIdsRaw && !$status && !$all && !$singleId && !$singleTrackId) {
        throw new Exception('No deletion criteria', 400);
    }

    $sql = "DELETE FROM downloadQueue";
    $bindings = [];
    $conditions = [];

    if ($all) {
        // no WHERE
    } elseif ($idParam !== null) {
        $idsArray = is_array($idParam) ? $idParam : explode(',', $idParam);
        $idsArray = array_map('intval', $idsArray);
        $placeholders = implode(',', array_fill(0, count($idsArray), '?'));
        $conditions[] = "id IN ($placeholders)";
        $bindings = array_merge($bindings, $idsArray);
    } elseif (!empty($idsParam)) {
        $idsArray = is_array($idsParam) ? $idsParam : explode(',', $idsParam);
        $idsArray = array_map('intval', $idsArray);
        $placeholders = implode(',', array_fill(0, count($idsArray), '?'));
        $conditions[] = "id IN ($placeholders)";
        $bindings = array_merge($bindings, $idsArray);
    } elseif (!empty($trackIdsRaw)) {
        $trackIds = is_array($trackIdsRaw) ? $trackIdsRaw : explode(',', $trackIdsRaw);
        $placeholders = implode(',', array_fill(0, count($trackIds), '?'));
        $conditions[] = "trackId IN ($placeholders)";
        $bindings = array_merge($bindings, $trackIds);
    } elseif ($status) {
        $conditions[] = "status = ?";
        $bindings[] = $status;
    } elseif ($singleId) {
        $conditions[] = "id = ?";
        $bindings[] = (int)$singleId;
    } elseif ($singleTrackId) {
        $conditions[] = "trackId = ?";
        $bindings[] = $singleTrackId;
    }

    if (!empty($conditions)) $sql .= " WHERE " . implode(' AND ', $conditions);
    $stmt = getStatement($sql);
    foreach ($bindings as $idx => $val) {
        $stmt->bindValue($idx + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return ['success' => true, 'deleted_count' => $stmt->rowCount()];
}

// ── HTTP Request Handling ──────────────────────────────────
function enableCompression(): void
{
    if (ENABLE_GZIP && !headers_sent() && extension_loaded('zlib') && strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false) {
        ini_set('zlib.output_compression', 'On');
        ini_set('zlib.output_compression_level', '6');
    }
}

function respond($data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Quality, Authorization');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Authentication Middleware ─────────────────────────────
function authenticateRequest(): void
{
    $token = null;

    // 1. Authorization: Bearer <token>
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            $token = $matches[1];
        }
    }

    // 2. Query parameter ?token=...
    if ($token === null && isset($_GET['token'])) {
        $token = $_GET['token'];
    }

    // 3. POST field (for JSON body we already decoded; but we can also check raw)
    if ($token === null) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['token'])) {
            $token = $input['token'];
        } elseif (isset($_POST['token'])) {
            $token = $_POST['token'];
        }
    }

    if ($token === null || !hash_equals(API_TOKEN, $token)) {
        respond(['success' => false, 'error' => 'Unauthorized: invalid or missing token'], 401);
    }
}

function handleRequest(): void
{
    enableCompression();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') respond([], 200);

    // Token authentication for every endpoint
    authenticateRequest();

    $db = getDB();
    cleanExpiredCache($db);

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    if ($scriptDir !== '/' && strpos($path, $scriptDir) === 0) $path = substr($path, strlen($scriptDir));
    $path = rtrim($path, '/') ?: '/';
    $method = $_SERVER['REQUEST_METHOD'];
    $params = ($method === 'GET') ? $_GET : (json_decode(file_get_contents('php://input'), true) ?: $_POST);
    if (isset($params['term'])) $params['term'] = trim(strtolower($params['term']));
    $quality = $_SERVER['HTTP_QUALITY'] ?? $params['quality'] ?? null;
    if ($quality && !in_array($quality, SUPPORTED_AUDIO_QUALITIES)) $quality = DEFAULT_AUDIO_QUALITY;
    if ($quality) $params['quality'] = $quality;

    try {
        switch ($path) {
            case '/search':
                if (empty($params['term'])) throw new Exception('Missing term', 400);
                $response = searchiTunes($db, $params);
                break;
            case '/lookup':
                if (empty($params['id'])) throw new Exception('Missing id', 400);
                $response = lookupiTunes($db, $params);
                break;
            case '/mirror/set':
                if ($method !== 'POST') throw new Exception('Method not allowed', 405);
                if (isset($params['attachments']) && is_array($params['attachments'])) {
                    $response = addMirrorUrlsBatch($db, $params['attachments']);
                } else {
                    $response = addMirrorUrl($db, $params['entityType'] ?? '', $params['entityId'] ?? '', $params['urlType'] ?? '', $params['mirrorUrl'] ?? '', $params['quality'] ?? null, $params['source'] ?? 'custom');
                }
                break;
            case '/mirror/get':
                $response = getMirrorUrls($db, $params['entityType'] ?? '', $params['entityId'] ?? '', $params['urlType'] ?? null, $params['quality'] ?? null);
                break;
            case '/mirror/delete':
            case '/mirror/remove':
                if (!in_array($method, ['POST', 'DELETE'])) throw new Exception('Method not allowed', 405);
                $mirrorId = isset($params['mirrorId']) ? (int)$params['mirrorId'] : null;
                $response = deleteMirrorUrl($db, $params['entityType'] ?? '', $params['entityId'] ?? '', $params['urlType'] ?? null, $params['quality'] ?? null, $mirrorId);
                break;
            case '/track/save':
            case '/song/save':
                if ($method !== 'POST') throw new Exception('Method not allowed', 405);
                saveEntitiesFromApi($db, 'tracks', $params);
                $response = ['success' => true, 'message' => 'Track metadata saved'];
                break;
            case '/collection/save':
            case '/album/save':
                if ($method !== 'POST') throw new Exception('Method not allowed', 405);
                saveEntitiesFromApi($db, 'collections', $params);
                $response = ['success' => true, 'message' => 'Collection metadata saved'];
                break;
            case '/artist/save':
                if ($method !== 'POST') throw new Exception('Method not allowed', 405);
                saveEntitiesFromApi($db, 'artists', $params);
                $response = ['success' => true, 'message' => 'Artist metadata saved'];
                break;
            case '/lyrics/get':
                if (empty($params['id'])) throw new Exception('Missing track id', 400);
                $lyricsResult = getLyrics($db, $params['id']);
                if (!$lyricsResult['success']) {
                    $track = fetchEntityById($db, 'track', $params['id']);
                    if ($track && !empty($track['trackName']) && !empty($track['artistName'])) {
                        $fetched = fetchLyricsFromLrclib($track['trackName'], $track['artistName'], $track['collectionName'] ?? null);
                        if ($fetched) {
                            saveLyrics($db, $params['id'], $fetched['data'], $fetched['type'], $fetched['source']);
                            $lyricsResult = getLyrics($db, $params['id']);
                        }
                    }
                }
                $response = $lyricsResult;
                break;
            case '/lyrics/save':
                if ($method !== 'POST') throw new Exception('Method not allowed', 405);
                if (empty($params['id']) || empty($params['lyrics'])) throw new Exception('Missing parameters', 400);
                $response = saveLyrics($db, $params['id'], $params['lyrics'], $params['type'] ?? 'unsynced', $params['source'] ?? 'custom');
                break;
            case '/batch':
                $response = handleBatchLookup($db, $params);
                break;
            case '/popular':
                $response = handlePopular($db, $params);
                break;
            case '/cache/clear':
                $response = handleCacheClear($db);
                break;
            case '/stats':
            case '/db/stats':
                $response = handleStats($db);
                break;
            case '/health':
                $response = ['status' => 'ok', 'timestamp' => date('c'), 'db_size_bytes' => 0];
                break;
            case '/proxy/status':
                $response = handleProxyStatus($db);
                break;
            case '/rate-limit/reset':
                $response = handleResetRateLimit($db);
                break;
            case '/download/add':
                if ($method !== 'POST') throw new Exception('Method not allowed', 405);
                $response = handleDownloadAdd($db, $params);
                break;
            case '/download/queue':
                if ($method !== 'GET') throw new Exception('Method not allowed', 405);
                $response = handleDownloadQueue($db, $params);
                break;
            case '/download/status':
                if ($method !== 'GET') throw new Exception('Method not allowed', 405);
                $response = handleDownloadStatus($db, $params);
                break;
            // New endpoint: follow download status + percent by track ID
            case '/download/progress':
                if ($method !== 'GET') throw new Exception('Method not allowed', 405);
                if (empty($params['trackId'])) throw new Exception('Missing trackId', 400);
                $response = handleDownloadStatus($db, $params);
                break;
            case '/download/update':
                if (!in_array($method, ['POST', 'PUT'])) throw new Exception('Method not allowed', 405);
                $response = handleDownloadUpdate($db, $params);
                break;
            case '/download/delete':
                if (!in_array($method, ['POST', 'DELETE'])) throw new Exception('Method not allowed', 405);
                $response = handleDownloadDelete($db, $params);
                break;
            default:
                throw new Exception('Endpoint not found', 404);
        }
    } catch (Exception $e) {
        respond(['success' => false, 'error' => $e->getMessage()], $e->getCode() ?: 500);
    }
    respond($response);
}

if (php_sapi_name() !== 'cli') {
    try {
        handleRequest();
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Internal server error', 'message' => $e->getMessage()]);
    }
}
