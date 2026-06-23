<?php
/**
 * iTunes API Proxy v2.0 – MySQL Edition
 * + Download Manager v1.0
 * All features preserved, now powered by MySQL (PDO).
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ── MySQL Configuration ────────────────────────────────────
define('DEST_HOST', 'localhost');
define('DEST_NAME', 'rahir111_abraava');
define('DEST_USER', 'rahir111_admin');
define('DEST_PASS', 's.hH3KolwG=J!qRY');
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
define('OFFLINE_FALLBACK_ENABLED', true);
define('SMART_CACHE_PRELOAD', true);
define('SUPPORTED_AUDIO_QUALITIES', ['320', '192', '128']);
define('DEFAULT_AUDIO_QUALITY', '192');

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

// ── ID Helpers ─────────────────────────────────────────────
function normalizeId($id): string {
    if (is_numeric($id) || (is_string($id) && ctype_digit($id))) return 'it_' . $id;
    if (is_string($id) && strpos($id, 'it_') === 0) return $id;
    return (string)$id;
}
function denormalizeId($id): string {
    if (is_string($id) && strpos($id, 'it_') === 0) return substr($id, 3);
    return (string)$id;
}
function normalizeIdsInArray(array &$data): void {
    foreach (['artistId', 'collectionId', 'trackId'] as $key) {
        if (isset($data[$key])) $data[$key] = normalizeId($data[$key]);
    }
}
function denormalizeIdsInArray(array &$data): void {
    foreach (['artistId', 'collectionId', 'trackId'] as $key) {
        if (isset($data[$key])) $data[$key] = denormalizeId($data[$key]);
    }
}

// ── Database & Statements (PDO) ───────────────────────────
function getDB(): PDO {
    global $db;
    if ($db === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DEST_HOST,
            DEST_PORT,
            DEST_NAME
        );
        $db = new PDO($dsn, DEST_USER, DEST_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        initDatabase($db);
    }
    return $db;
}

function getStatement(string $sql): PDOStatement {
    global $statements;
    $hash = md5($sql);
    if (!isset($statements[$hash])) {
        $statements[$hash] = getDB()->prepare($sql);
    }
    return $statements[$hash];
}

// ── Schema & Initialization (MySQL) ──────────────────────
function initDatabase(PDO $db): void {
    static $initialized = false;
    if ($initialized) return;

    $db->exec("CREATE TABLE IF NOT EXISTS artists (
        artistId VARCHAR(255) PRIMARY KEY
    ) ENGINE=InnoDB");

    $db->exec("CREATE TABLE IF NOT EXISTS collections (
        collectionId VARCHAR(255) PRIMARY KEY
    ) ENGINE=InnoDB");

    $db->exec("CREATE TABLE IF NOT EXISTS tracks (
        trackId VARCHAR(255) PRIMARY KEY,
        lyrics TEXT
    ) ENGINE=InnoDB");

    $db->exec("CREATE TABLE IF NOT EXISTS entityMirrors (
        entityType VARCHAR(50) NOT NULL,
        entityId VARCHAR(255) NOT NULL,
        urlType VARCHAR(50) NOT NULL,
        mirrorUrl TEXT NOT NULL,
        quality VARCHAR(10),
        platform VARCHAR(50) NOT NULL DEFAULT 'telegram',
        updatedAt DATETIME,
        PRIMARY KEY (entityType, entityId, urlType, quality, platform)
    ) ENGINE=InnoDB");

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

    $db->exec("CREATE TABLE IF NOT EXISTS offlineCache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entityType VARCHAR(50) NOT NULL,
        entityId VARCHAR(255) NOT NULL,
        data TEXT NOT NULL,
        createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
        expiresAt DATETIME,
        UNIQUE KEY (entityType, entityId)
    ) ENGINE=InnoDB");

    $db->exec("CREATE TABLE IF NOT EXISTS download_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trackId VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        filePath TEXT,
        quality VARCHAR(10),
        platform VARCHAR(50) DEFAULT 'telegram',
        addedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
        startedAt DATETIME,
        completedAt DATETIME,
        errorMessage TEXT,
        retryCount INT DEFAULT 0,
        priority INT DEFAULT 0,
        FOREIGN KEY (trackId) REFERENCES tracks(trackId) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_download_status ON download_queue(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_download_track ON download_queue(trackId)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_mirrors_lookup ON entityMirrors(entityType, entityId)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cache_lookup ON requestCache(endpoint, params(255))");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_offline_entity ON offlineCache(entityType, entityId)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_request_history ON requestHistory(requestTime)");

    $initialized = true;
}

// ── Dynamic Column Addition (MySQL) ───────────────────────
function ensureColumns(PDO $db, string $table, array $data): void {
    static $existingColumns = [];
    $allowedTables = ['artists', 'collections', 'tracks'];
    if (!in_array($table, $allowedTables)) return;

    if (!isset($existingColumns[$table])) {
        $stmt = $db->query("SHOW COLUMNS FROM $table");
        $cols = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[$row['Field']] = true;
        }
        $existingColumns[$table] = $cols;
    }

    foreach ($data as $col => $value) {
        if (!isset($existingColumns[$table][$col])) {
            if (preg_match('/^[a-zA-Z0-9_]+$/', $col)) {
                $db->exec("ALTER TABLE $table ADD COLUMN `$col` TEXT DEFAULT NULL");
                $existingColumns[$table][$col] = true;
                error_log("Added column `$col` to table $table");
            }
        }
    }
}

// ── Data Preservation when Saving from iTunes ─────────────
function saveEntitiesFromApi(PDO $db, string $table, array $entities): void {
    if (empty($entities)) return;
    if (isset($entities['wrapperType']) || isset($entities['artistId']) || isset($entities['collectionId']) || isset($entities['trackId'])) {
        $entities = [$entities];
    }

    $expectedWrapper = match ($table) {
        'artists'     => 'artist',
        'collections' => 'collection',
        'tracks'      => 'track',
        default       => null,
    };
    if ($expectedWrapper === null) return;

    $db->beginTransaction();
    foreach ($entities as $entity) {
        if (!is_array($entity)) continue;
        if (isset($entity['wrapperType']) && $entity['wrapperType'] !== $expectedWrapper) continue;

        normalizeIdsInArray($entity);
        $pkCol = match ($table) {
            'artists'     => 'artistId',
            'collections' => 'collectionId',
            'tracks'      => 'trackId',
            default       => null,
        };
        if (!$pkCol || !isset($entity[$pkCol])) continue;

        ensureColumns($db, $table, $entity);

        $stmt = getStatement("SELECT 1 FROM $table WHERE $pkCol = :id");
        $stmt->bindValue(':id', $entity[$pkCol]);
        $stmt->execute();
        $exists = $stmt->fetch() !== false;

        if (!$exists) {
            $columns = array_keys($entity);
            $placeholders = implode(',', array_map(fn($c) => ":$c", $columns));
            $sql = "INSERT INTO $table (`" . implode('`,`', $columns) . "`) VALUES ($placeholders)";
            $ins = $db->prepare($sql);
            foreach ($entity as $col => $val) {
                $type = is_int($val) ? PDO::PARAM_INT : (is_float($val) ? PDO::PARAM_STR : PDO::PARAM_STR);
                $ins->bindValue(":$col", $val, $type);
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
                $sql = "UPDATE $table SET " . implode(',', $updates) . " WHERE $pkCol = :id";
                $upd = $db->prepare($sql);
                foreach ($entity as $col => $val) {
                    if ($col !== $pkCol && $col !== 'lyrics') {
                        $type = is_int($val) ? PDO::PARAM_INT : (is_float($val) ? PDO::PARAM_STR : PDO::PARAM_STR);
                        $upd->bindValue(":$col", $val, $type);
                    }
                }
                $upd->bindValue(':id', $entity[$pkCol]);
                $upd->execute();
            }
        }
    }
    $db->commit();
}

// ── Mirror Helpers (quality aware + platform grouping) ───
function getAudioUrlTypeWithQuality(string $urlType, ?string $quality = null): string {
    if ($urlType !== 'audioUrl' || !$quality) return $urlType;
    if (!in_array($quality, SUPPORTED_AUDIO_QUALITIES)) $quality = DEFAULT_AUDIO_QUALITY;
    return $urlType . '_' . $quality;
}
function extractQualityFromUrlType(string $urlType): ?string {
    if (strpos($urlType, 'audioUrl_') === 0) {
        $qual = substr($urlType, 9);
        return in_array($qual, SUPPORTED_AUDIO_QUALITIES) ? $qual : null;
    }
    return null;
}
function getBestAvailableQuality(array $mirrors): ?array {
    foreach (SUPPORTED_AUDIO_QUALITIES as $qual) {
        $key = 'audioUrl_' . $qual;
        if (isset($mirrors[$key]['url'])) return ['url' => $mirrors[$key]['url'], 'quality' => $qual];
    }
    if (isset($mirrors['audioUrl']['url'])) return ['url' => $mirrors['audioUrl']['url'], 'quality' => $mirrors['audioUrl']['quality'] ?? DEFAULT_AUDIO_QUALITY];
    return null;
}

/**
 * Attach mirrors to an entity. If platform is 'all', attaches all platforms under 'allMirrors'.
 * Otherwise, attaches only the specified platform under 'mirrorUrls'.
 */
function attachMirrors(array &$entity, string $type, string $id, ?string $requestedQuality = null, string $platform = 'telegram'): void {
    $db = getDB();
    $id = normalizeId($id);

    if ($platform === 'all') {
        // Fetch all platforms and group by platform
        $stmt = getStatement("SELECT urlType, mirrorUrl, quality, platform FROM entityMirrors WHERE entityType=:t AND entityId=:id");
        $stmt->bindValue(':t', $type);
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        $allMirrors = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $p = $row['platform'];
            if (!isset($allMirrors[$p])) $allMirrors[$p] = [];
            $urlType = $row['urlType'];
            $mirrorData = ['url' => $row['mirrorUrl']];
            if ($row['quality']) $mirrorData['quality'] = $row['quality'];
            $allMirrors[$p][$urlType] = $mirrorData;
        }
        // Also add best audio for each platform?
        $entity['allMirrors'] = $allMirrors;
        // Keep mirrorUrls for backward compatibility (using first platform or default 'telegram')
        $defaultPlatform = 'telegram';
        if (isset($allMirrors[$defaultPlatform])) {
            $mirrors = $allMirrors[$defaultPlatform];
        } else if (!empty($allMirrors)) {
            $mirrors = reset($allMirrors);
        } else {
            $mirrors = [];
        }
        // Build mirrorUrls from default platform (for backward compat)
        $entity['mirrorUrls'] = [];
        if (isset($mirrors['artworkUrl'])) $entity['mirrorUrls']['artworkUrl'] = ['url' => $mirrors['artworkUrl']['url']];
        if (isset($mirrors['previewUrl'])) $entity['mirrorUrls']['previewUrl'] = ['url' => $mirrors['previewUrl']['url']];
        $best = getBestAvailableQuality($mirrors);
        if ($best) $entity['mirrorUrls']['audioUrl'] = $best;
        return;
    }

    // Single platform (original behavior)
    $stmt = getStatement("SELECT urlType, mirrorUrl, quality FROM entityMirrors WHERE entityType=:t AND entityId=:id AND platform=:p");
    $stmt->bindValue(':t', $type);
    $stmt->bindValue(':id', $id);
    $stmt->bindValue(':p', $platform);
    $stmt->execute();
    $mirrors = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $urlType = $row['urlType'];
        $mirrorData = ['url' => $row['mirrorUrl']];
        $qual = extractQualityFromUrlType($urlType);
        if ($qual) $mirrorData['quality'] = $qual;
        elseif ($row['quality']) $mirrorData['quality'] = $row['quality'];
        $mirrors[$urlType] = $mirrorData;
    }

    $artworkMirror = null;
    if ($type === 'track' && !empty($entity['collectionId'])) {
        $collectionId = normalizeId($entity['collectionId']);
        $stmtColl = getStatement("SELECT mirrorUrl FROM entityMirrors WHERE entityType='collection' AND entityId=:cid AND urlType='artworkUrl' AND platform=:p LIMIT 1");
        $stmtColl->bindValue(':cid', $collectionId);
        $stmtColl->bindValue(':p', $platform);
        $stmtColl->execute();
        $collRow = $stmtColl->fetch(PDO::FETCH_ASSOC);
        if ($collRow && !empty($collRow['mirrorUrl'])) {
            $artworkMirror = $collRow['mirrorUrl'];
        }
    }
    if (!$artworkMirror && isset($mirrors['artworkUrl'])) {
        $artworkMirror = $mirrors['artworkUrl']['url'];
    }
    $mirrorUrls['artworkUrl'] = $artworkMirror ? ['url' => $artworkMirror] : null;

    $previewMirror = isset($mirrors['previewUrl']) ? $mirrors['previewUrl']['url'] : null;
    $mirrorUrls['previewUrl'] = $previewMirror ? ['url' => $previewMirror] : null;

    if ($requestedQuality && in_array($requestedQuality, SUPPORTED_AUDIO_QUALITIES)) {
        $specific = $mirrors['audioUrl_' . $requestedQuality] ?? null;
        $mirrorUrls['audioUrl'] = $specific ?? getBestAvailableQuality($mirrors);
    } else {
        $mirrorUrls['audioUrl'] = getBestAvailableQuality($mirrors);
    }

    $entity['mirrorUrls'] = $mirrorUrls ?: new stdClass();
}

function setMirrorUrl(PDO $db, string $type, string $id, string $urlType, string $mirrorUrl, ?string $quality = null, string $platform = 'telegram'): array {
    if (!in_array($urlType, ['artworkUrl','previewUrl','audioUrl'])) return ['success'=>false, 'error'=>'Invalid urlType'];
    if (!filter_var($mirrorUrl, FILTER_VALIDATE_URL) && strpos($mirrorUrl, 'tg://') !== 0) return ['success'=>false, 'error'=>'Invalid URL'];
    $id = normalizeId($id);

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
    $stmt = getStatement("REPLACE INTO entityMirrors (entityType, entityId, urlType, mirrorUrl, quality, platform, updatedAt) VALUES (:t,:id,:ut,:url,:q,:p, NOW())");
    $stmt->bindValue(':t', $type);
    $stmt->bindValue(':id', $id);
    $stmt->bindValue(':ut', $actualUrlType);
    $stmt->bindValue(':url', $mirrorUrl);
    $stmt->bindValue(':q', $qualityVal);
    $stmt->bindValue(':p', $platform);
    $stmt->execute();
    return ['success'=>true, 'message'=>"Mirror $urlType set" . ($quality ? " for quality $quality" : "") . " on $platform"];
}

/**
 * Get mirror URLs. If $platform is 'all', returns all platforms grouped.
 * Otherwise returns only the specified platform.
 */
function getMirrorUrls(PDO $db, string $type, string $id, ?string $urlType = null, ?string $quality = null, string $platform = 'telegram'): array {
    $id = normalizeId($id);
    $sql = "SELECT urlType, mirrorUrl, quality, platform FROM entityMirrors 
            WHERE entityType = :t AND entityId = :id";
    $params = [':t' => $type, ':id' => $id];
    if ($platform !== 'all') {
        $sql .= " AND platform = :p";
        $params[':p'] = $platform;
    }
    $stmt = getStatement($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $mirrors = [];
    $allPlatforms = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $p = $row['platform'];
        if (!isset($allPlatforms[$p])) $allPlatforms[$p] = [];
        $rowType = $row['urlType'];
        if ($urlType && $quality && $rowType !== getAudioUrlTypeWithQuality($urlType, $quality)) {
            continue;
        }
        $allPlatforms[$p][$rowType] = ['url' => $row['mirrorUrl']];
        if ($row['quality']) {
            $allPlatforms[$p][$rowType]['quality'] = $row['quality'];
        }
        // Also keep flat for backward compatibility (if platform is specific)
        if ($platform !== 'all' || $p === $platform) {
            $mirrors[$rowType] = ['url' => $row['mirrorUrl']];
            if ($row['quality']) {
                $mirrors[$rowType]['quality'] = $row['quality'];
            }
        }
    }

    // Build response
    $mirrorUrls = [];
    $artworkMirror = null;
    if ($type === 'track' && !empty($id)) {
        $collectionId = null;
        $trackStmt = getStatement("SELECT collectionId FROM tracks WHERE trackId = :id");
        $trackStmt->bindValue(':id', $id);
        $trackStmt->execute();
        $trackRow = $trackStmt->fetch(PDO::FETCH_ASSOC);
        if ($trackRow && !empty($trackRow['collectionId'])) {
            $collectionId = $trackRow['collectionId'];
            $collStmt = getStatement("SELECT mirrorUrl FROM entityMirrors 
                                      WHERE entityType = 'collection' AND entityId = :cid 
                                      AND urlType = 'artworkUrl'");
            if ($platform !== 'all') {
                $collStmt = getStatement($collStmt->queryString . " AND platform = :p LIMIT 1");
                $collStmt->bindValue(':p', $platform);
            } else {
                $collStmt = getStatement($collStmt->queryString . " LIMIT 1");
            }
            $collStmt->bindValue(':cid', $collectionId);
            $collStmt->execute();
            $collRow = $collStmt->fetch(PDO::FETCH_ASSOC);
            if ($collRow && !empty($collRow['mirrorUrl'])) {
                $artworkMirror = $collRow['mirrorUrl'];
            }
        }
    }
    if (!$artworkMirror && isset($mirrors['artworkUrl'])) {
        $artworkMirror = $mirrors['artworkUrl']['url'];
    }
    $mirrorUrls['artworkUrl'] = $artworkMirror ? ['url' => $artworkMirror] : null;
    $previewMirror = isset($mirrors['previewUrl']) ? $mirrors['previewUrl']['url'] : null;
    $mirrorUrls['previewUrl'] = $previewMirror ? ['url' => $previewMirror] : null;
    $bestAudio = getBestAvailableQuality($mirrors);
    $mirrorUrls['audioUrl'] = $bestAudio;

    return [
        'success' => true,
        'entityType' => $type,
        'entityId' => denormalizeId($id),
        'platform' => $platform,
        'mirrorUrls' => $mirrorUrls,
        'allPlatforms' => $allPlatforms, // optional, for UI
    ];
}

function deleteMirrorUrl(PDO $db, string $type, string $id, ?string $urlType = null, ?string $quality = null, string $platform = 'telegram'): array {
    $id = normalizeId($id);
    if ($urlType) {
        $actual = getAudioUrlTypeWithQuality($urlType, $quality);
        $stmt = getStatement("DELETE FROM entityMirrors WHERE entityType=:t AND entityId=:id AND urlType=:ut AND platform=:p");
        $stmt->bindValue(':ut', $actual);
    } else {
        $stmt = getStatement("DELETE FROM entityMirrors WHERE entityType=:t AND entityId=:id AND platform=:p");
    }
    $stmt->bindValue(':t', $type);
    $stmt->bindValue(':id', $id);
    $stmt->bindValue(':p', $platform);
    $stmt->execute();
    return ['success'=>true, 'message'=>($urlType ? "Mirror '$urlType' deleted" : 'All mirrors deleted')];
}

// ── Lyrics ────────────────────────────────────────────────
function getLyrics(PDO $db, string $trackId): array {
    $trackId = normalizeId($trackId);
    $stmt = getStatement("SELECT lyrics FROM tracks WHERE trackId = :id");
    $stmt->bindValue(':id', $trackId);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['lyrics'])) return ['success'=>true, 'trackId'=>denormalizeId($trackId), 'lyrics'=>json_decode($row['lyrics'], true)];
    return ['success'=>false, 'error'=>'Lyrics not found'];
}
function saveLyrics(PDO $db, string $trackId, $lyrics): array {
    $trackId = normalizeId($trackId);
    $lyricsJson = is_string($lyrics) ? $lyrics : json_encode($lyrics);
    if (json_decode($lyricsJson) === null) return ['success'=>false, 'error'=>'Invalid JSON'];
    $stmt = getStatement("INSERT IGNORE INTO tracks (trackId) VALUES (:id)");
    $stmt->bindValue(':id', $trackId);
    $stmt->execute();
    $stmt = getStatement("UPDATE tracks SET lyrics = :lyrics WHERE trackId = :id");
    $stmt->bindValue(':lyrics', $lyricsJson);
    $stmt->bindValue(':id', $trackId);
    $stmt->execute();
    return ['success'=>true, 'message'=>'Lyrics saved'];
}

/**
 * Fetch lyrics from LRCLIB based on track metadata.
 * Returns the full API response as a JSON string, or null on failure.
 */
function fetchLyricsFromLrclib(string $trackName, string $artistName, ?string $albumName = null): ?string {
    $params = [
        'track_name' => $trackName,
        'artist_name' => $artistName,
    ];
    if ($albumName) {
        $params['album_name'] = $albumName;
    }
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
            // Store the complete response to preserve all lyric fields
            return json_encode($data);
        }
    }
    return null;
}

// ── Fetch single entity ───────────────────────────────────
function fetchEntityById(PDO $db, string $type, string $id, ?string $quality = null, string $platform = 'telegram'): ?array {
    $id = normalizeId($id);
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
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        attachMirrors($row, $type, $id, $quality, $platform);
        if ($type === 'track') {
            $lyricsData = getLyrics($db, $id);
            if ($lyricsData['success']) {
                $row['lyrics'] = $lyricsData['lyrics'];
            } else {
                $row['lyrics'] = null;
            }
        }
        return $row;
    }
    return null;
}

// ── Caching ───────────────────────────────────────────────
function getAdaptiveTTL(): int {
    $db = getDB();
    $stmt = getStatement("SELECT successfulRequests, failedRequests FROM rateLimitLog WHERE apiName='itunes' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
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
function extractResultIds(array $results): string {
    $ids = [];
    foreach ($results as $item) {
        if (isset($item['wrapperType']) && isset($item[$item['wrapperType'] . 'Id'])) {
            $ids[] = ['type'=>$item['wrapperType'], 'id'=>normalizeId($item[$item['wrapperType'] . 'Id'])];
        }
    }
    return json_encode($ids);
}
function saveCacheIds(PDO $db, string $endpoint, array $params, array $results): void {
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
function getCachedResults(PDO $db, string $endpoint, array $params): ?array {
    $paramsJson = json_encode($params);
    $stmt = getStatement("SELECT resultIds, expiresAt FROM requestCache WHERE endpoint=:ep AND params=:p AND expiresAt > NOW() LIMIT 1");
    $stmt->bindValue(':ep', $endpoint);
    $stmt->bindValue(':p', $paramsJson);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $stmt = getStatement("UPDATE requestCache SET accessCount = accessCount + 1, lastAccessed = NOW() WHERE endpoint=:ep AND params=:p");
    $stmt->bindValue(':ep', $endpoint);
    $stmt->bindValue(':p', $paramsJson);
    $stmt->execute();
    $ids = json_decode($row['resultIds'], true);
    if (!$ids) return null;
    $results = [];
    foreach ($ids as $entry) {
        $quality = $params['quality'] ?? null;
        $platform = $params['platform'] ?? 'telegram';
        $entity = fetchEntityById($db, $entry['type'], $entry['id'], $quality, $platform);
        if ($entity) $results[] = $entity;
    }
    return ['resultCount'=>count($results), 'results'=>$results];
}
function cleanExpiredCache(PDO $db): void {
    $now = time();
    $stmt = getStatement("SELECT lastRequestTime FROM rateLimitLog WHERE apiName = 'system_cleanup' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $lastCleanup = $row ? strtotime($row['lastRequestTime']) : 0;

    if (($now - $lastCleanup) > 1800) {
        $db->exec("DELETE FROM requestCache WHERE expiresAt < NOW()");
        $db->exec("DELETE FROM offlineCache WHERE expiresAt < NOW()");
        $db->exec("DELETE FROM requestHistory WHERE requestTime < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $db->exec("UPDATE proxyStatus SET isBlocked = 0, blockedUntil = NULL WHERE blockedUntil < DATE_SUB(NOW(), INTERVAL 24 HOUR)");

        $stmt = getStatement("REPLACE INTO rateLimitLog (apiName, lastRequestTime) VALUES ('system_cleanup', NOW())");
        $stmt->execute();
    }
}
function saveToOfflineCache(string $type, string $id, array $data): void {
    $db = getDB();
    $id = normalizeId($id);
    $expires = date('Y-m-d H:i:s', time() + CACHE_DURATION * 2);
    $stmt = getStatement("REPLACE INTO offlineCache (entityType, entityId, data, expiresAt) VALUES (:t, :id, :data, :ex)");
    $stmt->bindValue(':t', $type);
    $stmt->bindValue(':id', $id);
    $stmt->bindValue(':data', json_encode($data));
    $stmt->bindValue(':ex', $expires);
    $stmt->execute();
}
function getFromOfflineCache(string $type, string $id): ?array {
    $db = getDB();
    $id = normalizeId($id);
    $stmt = getStatement("SELECT data FROM offlineCache WHERE entityType=:t AND entityId=:id AND expiresAt > NOW()");
    $stmt->bindValue(':t', $type);
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? json_decode($row['data'], true) : null;
}

// ── Rate Limiting & Proxies ──────────────────────────────
function checkRateLimit(string $api = 'itunes'): bool {
    global $lastRequestTime;
    if (ENABLE_REQUEST_THROTTLING) {
        $now = microtime(true);
        $elapsed = ($now - $lastRequestTime) * 1000000;
        if ($lastRequestTime > 0 && $elapsed < THROTTLE_MIN_INTERVAL) usleep(THROTTLE_MIN_INTERVAL - $elapsed);
        $lastRequestTime = microtime(true);
    }
    return true;
}
function handleRateLimitHit(string $api = 'itunes'): void { /* log and block */ }
function resetRateLimit(string $api = 'itunes', bool $success = true): void { /* reset counters */ }
function loadProxies(): array {
    if (!file_exists(PROXY_LIST_FILE)) return [];
    $lines = file(PROXY_LIST_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_filter($lines, fn($l) => strpos($l, '://') !== false);
}
function getNextProxy(): ?string {
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
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
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
function markProxyStatus(string $proxy, bool $success): void {
    $db = getDB();
    if ($success) {
        $stmt = getStatement("UPDATE proxyStatus SET successCount = successCount + 1, isBlocked = 0 WHERE proxyUrl = :url");
    } else {
        $stmt = getStatement("UPDATE proxyStatus SET failCount = failCount + 1, isBlocked = 1, blockedUntil = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE proxyUrl = :url");
    }
    $stmt->bindValue(':url', $proxy);
    $stmt->execute();
}

// ── iTunes API Calls with Fallback ────────────────────────
function makeApiRequest(string $url, int $retry = 0): ?array {
    if (!checkRateLimit()) {
        if ($retry < RATE_LIMIT_MAX_RETRIES) { usleep((RATE_LIMIT_BASE_DELAY * pow(2, $retry) + mt_rand(0,1000000)/1e6)*1e6); return makeApiRequest($url, $retry+1); }
        return null;
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_ENCODING => '',
        CURLOPT_HEADER => true, CURLOPT_FORBID_REUSE => true, CURLOPT_FRESH_CONNECT => true,
    ]);
    global $userAgents;
    if (ENABLE_USER_AGENT_ROTATION) curl_setopt($ch, CURLOPT_USERAGENT, $userAgents[array_rand($userAgents)]);
    $currentProxy = null;
    if (USE_PROXY_ROTATION && ($currentProxy = getNextProxy())) curl_setopt($ch, CURLOPT_PROXY, $currentProxy);
    if (ENABLE_IP_SPOOFING) {
        $ip = mt_rand(1,255).'.'.mt_rand(0,255).'.'.mt_rand(0,255).'.'.mt_rand(1,255);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Forwarded-For: '.$ip, 'X-Real-IP: '.$ip, 'Client-IP: '.$ip]);
    }
    usleep(mt_rand(100000,500000));
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
        if ($retry < RATE_LIMIT_MAX_RETRIES) return makeApiRequest($url, $retry+1);
        return null;
    } elseif (in_array($httpCode, [403,503]) && $retry < RATE_LIMIT_MAX_RETRIES) {
        rotateProxy();
        sleep(mt_rand(5,15));
        return makeApiRequest($url, $retry+1);
    }
    return null;
}
function makeApiRequestWithFallback(string $url, array $params, int $retry = 0): array {
    $response = makeApiRequest($url, $retry);
    if ($response && isset($response['results'])) {
        $response['source'] = 'api';
        foreach ($response['results'] as $item) {
            $type = $item['wrapperType'] ?? (isset($item['artistId']) && !isset($item['collectionId']) ? 'artist' : (isset($item['collectionId']) && !isset($item['trackId']) ? 'collection' : (isset($item['trackId']) ? 'track' : null)));
            if ($type && isset($item[$type . 'Id'])) saveToOfflineCache($type, $item[$type . 'Id'], $item);
        }
        return $response;
    }
    if (OFFLINE_FALLBACK_ENABLED && isset($params['id'])) {
        $ids = explode(',', $params['id']);
        $results = [];
        foreach ($ids as $rawId) {
            $id = normalizeId(trim($rawId));
            foreach (['artist', 'collection', 'track'] as $type) {
                $cached = getFromOfflineCache($type, $id);
                if ($cached) {
                    attachMirrors($cached, $type, $id, $params['quality'] ?? null, $params['platform'] ?? 'telegram');
                    $results[] = $cached;
                    break;
                }
            }
        }
        if (!empty($results)) return ['resultCount'=>count($results), 'results'=>$results, 'fromCache'=>true];
    }
    return searchLocalDatabase($params);
}
function searchLocalDatabase(array $params): array {
    $db = getDB();
    $results = [];
    $platform = $params['platform'] ?? 'telegram';
    if (isset($params['term'])) {
        $term = '%' . strtolower($params['term']) . '%';
        $entity = $params['entity'] ?? 'all';
        $limit = min((int)($params['limit'] ?? 50), 500);
        $quality = $params['quality'] ?? null;
        if ($entity === 'all' || $entity === 'musicArtist') {
            $stmt = getStatement("SELECT *, 'artist' as wrapperType FROM artists WHERE LOWER(artistName) LIKE :term LIMIT :limit");
            $stmt->bindValue(':term', $term);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { attachMirrors($row, 'artist', $row['artistId'], $quality, $platform); $results[] = $row; }
        }
        if ($entity === 'all' || $entity === 'collection') {
            $stmt = getStatement("SELECT *, 'collection' as wrapperType FROM collections WHERE LOWER(collectionName) LIKE :term LIMIT :limit");
            $stmt->bindValue(':term', $term);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { attachMirrors($row, 'collection', $row['collectionId'], $quality, $platform); $results[] = $row; }
        }
        if ($entity === 'all' || $entity === 'song') {
            $stmt = getStatement("SELECT *, 'track' as wrapperType FROM tracks WHERE LOWER(trackName) LIKE :term LIMIT :limit");
            $stmt->bindValue(':term', $term);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { attachMirrors($row, 'track', $row['trackId'], $quality, $platform); $results[] = $row; }
        }
    } elseif (isset($params['id'])) {
        $ids = explode(',', $params['id']);
        foreach ($ids as $rawId) {
            $id = normalizeId(trim($rawId));
            foreach (['artist', 'collection', 'track'] as $type) {
                $entity = fetchEntityById($db, $type, $id, $params['quality'] ?? null, $platform);
                if ($entity) { $results[] = $entity; break; }
            }
        }
    }
    return ['resultCount'=>count($results), 'results'=>$results, 'fromCache'=>true];
}
function searchiTunes(PDO $db, array $params): array {
    $cached = getCachedResults($db, 'search', $params);
    if ($cached) {
        foreach ($cached['results'] as &$item) {
            unset($item['mirrorUrls']);
        }
        return $cached;
    }

    $url = ITUNES_SEARCH_API . '?' . http_build_query($params);
    $response = makeApiRequestWithFallback($url, $params);
    if ($response && isset($response['results']) && $response['resultCount'] > 0 && isset($response['source']) && $response['source'] === 'api') {
        saveEntitiesFromApi($db, 'artists', $response['results']);
        saveEntitiesFromApi($db, 'collections', $response['results']);
        saveEntitiesFromApi($db, 'tracks', $response['results']);
        saveCacheIds($db, 'search', $params, $response['results']);
    }

    if ($response && isset($response['results'])) {
        foreach ($response['results'] as &$item) {
            unset($item['mirrorUrls']);
        }
    }
    return $response ?? ['resultCount'=>0, 'results'=>[]];
}
function lookupiTunes(PDO $db, array $params): array {
    $cached = getCachedResults($db, 'lookup', $params);
    if ($cached) {
        $quality = $params['quality'] ?? null;
        $platform = $params['platform'] ?? 'telegram';
        foreach ($cached['results'] as &$item) {
            $type = $item['wrapperType'] ?? null;
            if ($type === 'artist') attachMirrors($item, 'artist', $item['artistId'], $quality, $platform);
            elseif ($type === 'collection') attachMirrors($item, 'collection', $item['collectionId'], $quality, $platform);
            elseif ($type === 'track') {
                attachMirrors($item, 'track', $item['trackId'], $quality, $platform);
                $lyricsData = getLyrics($db, $item['trackId']);
                if ($lyricsData['success']) {
                    $item['lyrics'] = $lyricsData['lyrics'];
                } else {
                    $item['lyrics'] = null;
                }
            }
        }
        return $cached;
    }

    $apiParams = $params;
    if (isset($apiParams['id'])) {
        $ids = array_map('trim', explode(',', $apiParams['id']));
        $denormalized = array_map('denormalizeId', $ids);
        $apiParams['id'] = implode(',', $denormalized);
    }
    $url = ITUNES_LOOKUP_API . '?' . http_build_query($apiParams);
    $response = makeApiRequestWithFallback($url, $params);
    if ($response && isset($response['results']) && $response['resultCount'] > 0 && isset($response['source']) && $response['source'] === 'api') {
        saveEntitiesFromApi($db, 'artists', $response['results']);
        saveEntitiesFromApi($db, 'collections', $response['results']);
        saveEntitiesFromApi($db, 'tracks', $response['results']);
        saveCacheIds($db, 'lookup', $params, $response['results']);
    }

    if ($response && isset($response['results'])) {
        $quality = $params['quality'] ?? null;
        $platform = $params['platform'] ?? 'telegram';
        foreach ($response['results'] as &$item) {
            $type = $item['wrapperType'] ?? null;
            if ($type === 'artist') attachMirrors($item, 'artist', $item['artistId'], $quality, $platform);
            elseif ($type === 'collection') attachMirrors($item, 'collection', $item['collectionId'], $quality, $platform);
            elseif ($type === 'track') {
                attachMirrors($item, 'track', $item['trackId'], $quality, $platform);
                $trackId = normalizeId($item['trackId']);
                $lyricsData = getLyrics($db, $trackId);
                if ($lyricsData['success']) {
                    $item['lyrics'] = $lyricsData['lyrics'];
                } else {
                    $item['lyrics'] = null;
                }
            }
        }
    }
    return $response ?? ['resultCount'=>0, 'results'=>[]];
}

// ── New / Enhanced Endpoints ──────────────────────────────
function handleBatchLookup(PDO $db, array $params): array {
    if (empty($params['ids'])) throw new Exception('Missing ids parameter (comma-separated)', 400);
    $ids = array_map('trim', explode(',', $params['ids']));
    $results = [];
    foreach ($ids as $id) {
        $normalized = normalizeId($id);
        $found = false;
        foreach (['artist', 'collection', 'track'] as $type) {
            $entity = fetchEntityById($db, $type, $normalized, $params['quality'] ?? null, $params['platform'] ?? 'telegram');
            if ($entity) {
                $results[] = $entity;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $lookup = lookupiTunes($db, ['id' => denormalizeId($normalized), 'quality' => $params['quality'] ?? null, 'platform' => $params['platform'] ?? 'telegram']);
            if (!empty($lookup['results'])) $results[] = $lookup['results'][0];
        }
    }
    return ['resultCount' => count($results), 'results' => $results];
}
function handlePopular(PDO $db, array $params): array {
    $limit = min((int)($params['limit'] ?? 20), 100);
    $stmt = getStatement("SELECT * FROM tracks ORDER BY trackId DESC LIMIT :limit");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $tracks = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        attachMirrors($row, 'track', $row['trackId'], $params['quality'] ?? null, $params['platform'] ?? 'telegram');
        $tracks[] = $row;
    }
    return ['resultCount' => count($tracks), 'results' => $tracks];
}
function handleCacheClear(PDO $db): array {
    $db->exec("DELETE FROM requestCache");
    $db->exec("DELETE FROM offlineCache");
    return ['success' => true, 'message' => 'All cache cleared'];
}
function handleStats(PDO $db): array {
    $stmt = $db->query("SELECT COUNT(*) as total FROM requestCache WHERE expiresAt > NOW()");
    $cacheCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $stmt = $db->query("SELECT COUNT(*) as total FROM tracks");
    $trackCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $stmt = $db->query("SELECT COUNT(*) as total FROM artists");
    $artistCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $stmt = $db->query("SELECT COUNT(*) as total FROM collections");
    $albumCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    return [
        'cache_entries' => $cacheCount,
        'track_count' => $trackCount,
        'artist_count' => $artistCount,
        'album_count' => $albumCount,
        'db_size_bytes' => 0,
        'uptime_seconds' => time() - (filemtime(__FILE__) ?? time()),
    ];
}
function handleProxyStatus(PDO $db): array {
    $stmt = $db->query("SELECT proxyUrl, successCount, failCount, isBlocked, lastUsed FROM proxyStatus ORDER BY successCount DESC");
    $proxies = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $proxies[] = $row;
    return ['proxies' => $proxies];
}
function handleResetRateLimit(PDO $db): array {
    $db->exec("DELETE FROM rateLimitLog");
    $db->exec("DELETE FROM requestHistory WHERE success = 0 AND requestTime > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    return ['success' => true, 'message' => 'Rate limit counters reset'];
}

// ── Download Manager Functions ────────────────────────────
function resolveTrackIdsFromInput(PDO $db, array $params): array {
    $trackIds = [];

    if (!empty($params['trackId'])) {
        $ids = is_array($params['trackId']) ? $params['trackId'] : explode(',', $params['trackId']);
        foreach ($ids as $tid) {
            $trackIds[] = normalizeId(trim($tid));
        }
    }

    if (!empty($params['albumId'])) {
        $albumId = normalizeId($params['albumId']);
        $stmt = getStatement("SELECT trackId FROM tracks WHERE collectionId = :aid");
        $stmt->bindValue(':aid', $albumId);
        $stmt->execute();
        $found = false;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $trackIds[] = $row['trackId'];
            $found = true;
        }
        if (!$found) {
            $lookup = lookupiTunes($db, ['id' => denormalizeId($albumId), 'entity' => 'song']);
            if (!empty($lookup['results'])) {
                $stmt2 = getStatement("SELECT trackId FROM tracks WHERE collectionId = :aid");
                $stmt2->bindValue(':aid', $albumId);
                $stmt2->execute();
                while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                    $trackIds[] = $row['trackId'];
                }
            }
        }
    }

    if (!empty($params['artistId'])) {
        $artistId = normalizeId($params['artistId']);
        $stmt = getStatement("SELECT trackId FROM tracks WHERE artistId = :aid");
        $stmt->bindValue(':aid', $artistId);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $trackIds[] = $row['trackId'];
        }
    }

    return array_unique($trackIds);
}

function handleDownloadAdd(PDO $db, array $params): array {
    $trackIds = resolveTrackIdsFromInput($db, $params);
    if (empty($trackIds)) {
        throw new Exception('No tracks resolved. Provide trackId, albumId, or artistId.', 400);
    }

    $quality = $params['quality'] ?? DEFAULT_AUDIO_QUALITY;
    $platform = $params['platform'] ?? 'telegram';
    $priority = (int)($params['priority'] ?? 0);
    $skipExisting = filter_var($params['skipExisting'] ?? true, FILTER_VALIDATE_BOOL);
    $force = filter_var($params['force'] ?? false, FILTER_VALIDATE_BOOL);
    $initialStatus = $params['status'] ?? DOWNLOAD_STATUS_PENDING;
    if (!in_array($initialStatus, [DOWNLOAD_STATUS_PENDING, DOWNLOAD_STATUS_DOWNLOADING, DOWNLOAD_STATUS_PAUSED])) {
        $initialStatus = DOWNLOAD_STATUS_PENDING;
    }

    $added = [];
    $skipped = [];
    $failed = [];

    $db->beginTransaction();
    foreach ($trackIds as $tid) {
        if ($skipExisting) {
            $stmt = getStatement("SELECT id, status FROM download_queue WHERE trackId = :tid AND status NOT IN ('completed', 'failed', 'stopped')");
            $stmt->bindValue(':tid', $tid);
            $stmt->execute();
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $skipped[] = [
                    'trackId' => denormalizeId($tid),
                    'reason' => 'Already in queue with status ' . $existing['status']
                ];
                continue;
            }
        }

        $track = fetchEntityById($db, 'track', $tid, $quality, $platform);
        if (!$track) {
            $lookup = lookupiTunes($db, ['id' => denormalizeId($tid)]);
            if (empty($lookup['results'])) {
                $failed[] = [
                    'trackId' => denormalizeId($tid),
                    'reason' => 'Track not found in iTunes'
                ];
                continue;
            }
            $track = $lookup['results'][0];
            attachMirrors($track, 'track', $tid, $quality, $platform);
        }

        $hasAudio = isset($track['mirrorUrls']['audioUrl']) && !empty($track['mirrorUrls']['audioUrl']['url']);
        if ($hasAudio && !$force) {
            $finalStatus = DOWNLOAD_STATUS_COMPLETED;
            $completedAt = 'NOW()';
        } else {
            $finalStatus = $initialStatus;
            $completedAt = null;
        }

        $sql = "INSERT INTO download_queue (trackId, status, quality, platform, priority, addedAt, completedAt) 
                VALUES (:tid, :status, :qual, :plat, :prio, NOW(), " . ($completedAt ? 'NOW()' : 'NULL') . ")";
        $stmt = getStatement($sql);
        $stmt->bindValue(':tid', $tid);
        $stmt->bindValue(':status', $finalStatus);
        $stmt->bindValue(':qual', $quality);
        $stmt->bindValue(':plat', $platform);
        $stmt->bindValue(':prio', $priority, PDO::PARAM_INT);
        $stmt->execute();

        $downloadId = $db->lastInsertId();

        $trackData = fetchEntityById($db, 'track', $tid, $quality, $platform);
        if (!$trackData) {
            $trackData = $track;
            attachMirrors($trackData, 'track', $tid, $quality, $platform);
        }
        $lyricsData = getLyrics($db, $tid);
        if ($lyricsData['success']) {
            $trackData['lyrics'] = $lyricsData['lyrics'];
        }

        $added[] = [
            'downloadId' => $downloadId,
            'trackId' => denormalizeId($tid),
            'track' => $trackData
        ];
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
function handleDownloadQueue(PDO $db, array $params): array {
    $status = $params['status'] ?? null;
    $limit = min((int)($params['limit'] ?? 100), 2000);
    $offset = (int)($params['offset'] ?? 0);
    $quality = $params['quality'] ?? null;
    $platform = $params['platform'] ?? 'telegram';

    $sql = "SELECT d.* FROM download_queue d";
    $countSql = "SELECT COUNT(*) as total FROM download_queue d";

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
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tid = $row['trackId'];
        $trackData = fetchEntityById($db, 'track', $tid, $quality, $platform);
        if (!$trackData) {
            $trackData = ['trackId' => denormalizeId($tid)];
        }
        $downloadMeta = [
            'download_id' => $row['id'],
            'download_status' => $row['status'],
            'file_path' => $row['filePath'],
            'quality' => $row['quality'],
            'platform' => $row['platform'],
            'added_at' => $row['addedAt'],
            'started_at' => $row['startedAt'],
            'completed_at' => $row['completedAt'],
            'error_message' => $row['errorMessage'],
            'retry_count' => $row['retryCount'],
            'priority' => $row['priority']
        ];
        $items[] = array_merge($trackData, $downloadMeta);
    }

    $countStmt->execute();
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    return [
        'success' => true,
        'total' => (int)$total,
        'limit' => $limit,
        'offset' => $offset,
        'items' => $items
    ];
}

function handleDownloadStatus(PDO $db, array $params): array {
    $id = $params['id'] ?? null;
    $trackId = $params['trackId'] ?? null;
    $quality = $params['quality'] ?? null;
    $platform = $params['platform'] ?? 'telegram';

    if (!$id && !$trackId) {
        throw new Exception('Missing id or trackId parameter', 400);
    }

    if ($id) {
        $stmt = getStatement("SELECT * FROM download_queue WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    } else {
        $tid = normalizeId($trackId);
        $stmt = getStatement("SELECT * FROM download_queue WHERE trackId = :tid ORDER BY id DESC LIMIT 1");
        $stmt->bindValue(':tid', $tid);
    }
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['success' => false, 'error' => 'Download entry not found'];
    }

    $tid = $row['trackId'];
    $trackData = fetchEntityById($db, 'track', $tid, $quality, $platform);
    if (!$trackData) {
        $trackData = ['trackId' => denormalizeId($tid)];
    }

    $downloadMeta = [
        'download_id' => $row['id'],
        'download_status' => $row['status'],
        'file_path' => $row['filePath'],
        'quality' => $row['quality'],
        'platform' => $row['platform'],
        'added_at' => $row['addedAt'],
        'started_at' => $row['startedAt'],
        'completed_at' => $row['completedAt'],
        'error_message' => $row['errorMessage'],
        'retry_count' => $row['retryCount'],
        'priority' => $row['priority']
    ];

    return [
        'success' => true,
        'download' => array_merge($trackData, $downloadMeta)
    ];
}

function handleDownloadUpdate(PDO $db, array $params): array {
    $idParam = $params['id'] ?? $params['ids'] ?? null;
    $trackIdsRaw = $params['trackIds'] ?? [];
    $filterStatus = $params['filterStatus'] ?? null;
    $status = $params['status'] ?? null;
    $filePath = $params['filePath'] ?? null;
    $errorMessage = $params['errorMessage'] ?? null;

    $targetIds = [];
    if ($idParam !== null) {
        $idArray = is_array($idParam) ? $idParam : explode(',', (string)$idParam);
        $targetIds = array_map('intval', $idArray);
    } elseif (!empty($trackIdsRaw)) {
        $trackIds = is_array($trackIdsRaw) ? $trackIdsRaw : explode(',', (string)$trackIdsRaw);
        $normalizedIds = array_map('normalizeId', $trackIds);
        $placeholders = implode(',', array_fill(0, count($normalizedIds), '?'));
        $stmt = $db->prepare("SELECT id FROM download_queue WHERE trackId IN ($placeholders)");
        foreach ($normalizedIds as $i => $tid) $stmt->bindValue($i + 1, $tid);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $targetIds[] = $row['id'];
    } elseif ($filterStatus !== null) {
        $stmt = $db->prepare("SELECT id FROM download_queue WHERE status = :status");
        $stmt->bindValue(':status', $filterStatus);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $targetIds[] = $row['id'];
    }

    if (empty($targetIds)) {
        return ['success' => true, 'updated_count' => 0, 'message' => 'No matching entries found'];
    }

    $updates = [];
    $updateBindings = [];
    if ($status !== null && $status !== '') {
        $allowed = [DOWNLOAD_STATUS_PENDING, DOWNLOAD_STATUS_DOWNLOADING, DOWNLOAD_STATUS_PAUSED, DOWNLOAD_STATUS_COMPLETED, DOWNLOAD_STATUS_FAILED, DOWNLOAD_STATUS_STOPPED];
        if (!in_array($status, $allowed)) throw new Exception('Invalid status', 400);
        $updates[] = "status = ?";
        $updateBindings[] = $status;
        if ($status === DOWNLOAD_STATUS_DOWNLOADING) $updates[] = "startedAt = COALESCE(startedAt, NOW())";
        if ($status === DOWNLOAD_STATUS_COMPLETED) {
            $updates[] = "completedAt = NOW()";
            $updates[] = "errorMessage = NULL";
        }
    }
    if ($filePath !== null) {
        $updates[] = "filePath = ?";
        $updateBindings[] = $filePath;
    }
    if ($errorMessage !== null) {
        $updates[] = "errorMessage = ?";
        $updateBindings[] = $errorMessage;
        if ($status === null) {
            $updates[] = "status = ?";
            $updateBindings[] = DOWNLOAD_STATUS_FAILED;
        }
    }

    if (empty($updates)) throw new Exception('Nothing to update', 400);

    $db->beginTransaction();
    try {
        $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
        $sql = "UPDATE download_queue SET " . implode(', ', $updates) . " WHERE id IN ($placeholders)";
        $stmt = $db->prepare($sql);
        $pos = 1;
        foreach ($updateBindings as $val) {
            $stmt->bindValue($pos++, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        foreach ($targetIds as $id) {
            $stmt->bindValue($pos++, $id, PDO::PARAM_INT);
        }
        $stmt->execute();
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    return ['success' => true, 'updated_count' => count($targetIds), 'message' => 'Updated successfully'];
}

function handleDownloadDelete(PDO $db, array $params): array {
    $idParam = $params['id'] ?? null;
    $idsParam = $params['ids'] ?? null;
    $trackIdsRaw = $params['trackIds'] ?? [];
    $status = $params['status'] ?? null;
    $all = filter_var($params['all'] ?? false, FILTER_VALIDATE_BOOL);

    $singleId = $params['id'] ?? null;
    $singleTrackId = $params['trackId'] ?? null;

    if (!$idParam && !$idsParam && !$trackIdsRaw && !$status && !$all && !$singleId && !$singleTrackId) {
        throw new Exception('No deletion criteria: provide id, trackId, ids, trackIds, status, or all', 400);
    }

    $sql = "DELETE FROM download_queue";
    $bindings = [];
    $conditions = [];

    if ($all) {
        // no WHERE
    } elseif ($idParam !== null) {
        if (is_array($idParam)) {
            $idsArray = array_map('intval', $idParam);
        } else {
            $idsArray = array_map('intval', explode(',', $idParam));
        }
        $placeholders = implode(',', array_fill(0, count($idsArray), '?'));
        $conditions[] = "id IN ($placeholders)";
        $bindings = array_merge($bindings, $idsArray);
    } elseif (!empty($idsParam)) {
        if (is_array($idsParam)) {
            $idsArray = array_map('intval', $idsParam);
        } else {
            $idsArray = array_map('intval', explode(',', $idsParam));
        }
        $placeholders = implode(',', array_fill(0, count($idsArray), '?'));
        $conditions[] = "id IN ($placeholders)";
        $bindings = array_merge($bindings, $idsArray);
    } elseif (!empty($trackIdsRaw)) {
        $trackIds = is_array($trackIdsRaw) ? $trackIdsRaw : explode(',', $trackIdsRaw);
        $normalized = array_map('normalizeId', $trackIds);
        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $conditions[] = "trackId IN ($placeholders)";
        $bindings = array_merge($bindings, $normalized);
    } elseif ($status) {
        $conditions[] = "status = ?";
        $bindings[] = $status;
    } elseif ($singleId) {
        $conditions[] = "id = ?";
        $bindings[] = (int)$singleId;
    } elseif ($singleTrackId) {
        $conditions[] = "trackId = ?";
        $bindings[] = normalizeId($singleTrackId);
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }

    $stmt = getStatement($sql);
    foreach ($bindings as $idx => $val) {
        $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($idx + 1, $val, $type);
    }
    $stmt->execute();
    $deleted = $stmt->rowCount();

    return ['success' => true, 'deleted_count' => $deleted];
}

// ── HTTP Request Handling ─────────────────────────────────
function enableCompression(): void {
    if (ENABLE_GZIP && !headers_sent() && extension_loaded('zlib') && strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false) {
        ini_set('zlib.output_compression', 'On');
        ini_set('zlib.output_compression_level', '6');
    }
}
function respond($data, int $status = 200): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Quality');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}
function handleRequest(): void {
    enableCompression();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') respond([], 200);
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
    $platform = $params['platform'] ?? 'telegram';

    // If platform is not set in mirror/get, default to 'all'
    if ($path === '/mirror/get' && !isset($params['platform'])) {
        $params['platform'] = 'all';
    }

    try {
        switch ($path) {
            case '/search': if (empty($params['term'])) throw new Exception('Missing term', 400); $response = searchiTunes($db, $params); break;
            case '/lookup': if (empty($params['id'])) throw new Exception('Missing id', 400); $response = lookupiTunes($db, $params); break;
            case '/mirror/set': if ($method !== 'POST') throw new Exception('Method not allowed', 405); $response = setMirrorUrl($db, $params['entityType'] ?? '', $params['entityId'] ?? '', $params['urlType'] ?? '', $params['mirrorUrl'] ?? '', $params['quality'] ?? null, $platform); break;
            case '/mirror/get': $response = getMirrorUrls($db, $params['entityType'] ?? '', $params['entityId'] ?? '', $params['urlType'] ?? null, $params['quality'] ?? null, $params['platform'] ?? 'all'); break;
            case '/mirror/delete':
            case '/mirror/remove': if (!in_array($method, ['POST','DELETE'])) throw new Exception('Method not allowed', 405); $response = deleteMirrorUrl($db, $params['entityType'] ?? '', $params['entityId'] ?? '', $params['urlType'] ?? null, $params['quality'] ?? null, $platform); break;
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
                    $trackId = normalizeId($params['id']);
                    $trackStmt = getStatement("SELECT * FROM tracks WHERE trackId = :tid");
                    $trackStmt->bindValue(':tid', $trackId);
                    $trackStmt->execute();
                    $track = $trackStmt->fetch(PDO::FETCH_ASSOC);
                    if ($track && !empty($track['trackName']) && !empty($track['artistName'])) {
                        $lyrics = fetchLyricsFromLrclib($track['trackName'], $track['artistName'], $track['collectionName'] ?? null);
                        if ($lyrics) {
                            saveLyrics($db, $params['id'], $lyrics);
                            $lyricsResult = getLyrics($db, $params['id']);
                        }
                    }
                }
                $response = $lyricsResult;
                break;
            case '/lyrics/save': if ($method !== 'POST') throw new Exception('Method not allowed', 405); if (empty($params['id']) || empty($params['lyrics'])) throw new Exception('Missing parameters', 400); $response = saveLyrics($db, $params['id'], $params['lyrics']); break;
            case '/batch': $response = handleBatchLookup($db, $params); break;
            case '/popular': $response = handlePopular($db, $params); break;
            case '/cache/clear': $response = handleCacheClear($db); break;
            case '/stats': $response = handleStats($db); break;
            case '/health': $response = ['status'=>'ok', 'timestamp'=>date('c'), 'db_size_bytes'=>0]; break;
            case '/db/stats': $response = handleStats($db); break;
            case '/proxy/status': $response = handleProxyStatus($db); break;
            case '/rate-limit/reset': $response = handleResetRateLimit($db); break;
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
            case '/download/update':
                if (!in_array($method, ['POST', 'PUT'])) throw new Exception('Method not allowed', 405);
                $response = handleDownloadUpdate($db, $params);
                break;
            case '/download/delete':
                if (!in_array($method, ['POST', 'DELETE'])) throw new Exception('Method not allowed', 405);
                $response = handleDownloadDelete($db, $params);
                break;
            default: throw new Exception('Endpoint not found', 404);
        }
    } catch (Exception $e) { respond(['success'=>false, 'error'=>$e->getMessage()], $e->getCode() ?: 500); }
    respond($response);
}
if (php_sapi_name() !== 'cli') {
    try { handleRequest(); } catch (Throwable $e) { http_response_code(500); echo json_encode(['success'=>false, 'error'=>'Internal server error', 'message'=>$e->getMessage()]); }
}
