-- MusicMan Database Schema

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') DEFAULT 'user',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    api_token VARCHAR(255) NOT NULL UNIQUE,
    user_id INT,
    type ENUM('bot', 'client', 'application') DEFAULT 'application',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(255),
    target_id VARCHAR(255),
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Update existing tables (Add columns if they don't exist)
-- Note: In a real migration we'd check existence, but for this overhaul we define the desired state.

-- Tracks table update
-- We need to make sure the tracks table exists first as defined in index.php
CREATE TABLE IF NOT EXISTS tracks (
    trackId VARCHAR(255) PRIMARY KEY,
    collectionId VARCHAR(255),
    trackName VARCHAR(255),
    artistName VARCHAR(255),
    lyrics TEXT,
    downloaded BOOLEAN DEFAULT FALSE,
    INDEX (collectionId)
) ENGINE=InnoDB;

-- Collections table update
CREATE TABLE IF NOT EXISTS collections (
    collectionId VARCHAR(255) PRIMARY KEY,
    collectionName VARCHAR(255),
    trackCount INT,
    downloaded BOOLEAN DEFAULT FALSE
) ENGINE=InnoDB;

-- Download Queue update
CREATE TABLE IF NOT EXISTS download_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    trackId VARCHAR(255) NOT NULL,
    collectionId VARCHAR(255),
    user_id INT,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    progress INT DEFAULT 0,
    status_step VARCHAR(255),
    filePath TEXT,
    quality VARCHAR(10),
    platform VARCHAR(50) DEFAULT 'telegram',
    addedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    startedAt DATETIME,
    completedAt DATETIME,
    errorMessage TEXT,
    retryCount INT DEFAULT 0,
    priority INT DEFAULT 0,
    FOREIGN KEY (trackId) REFERENCES tracks(trackId) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
