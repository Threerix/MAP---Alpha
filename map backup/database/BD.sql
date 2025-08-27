CREATE DATABASE IF NOT EXISTS map_database;

USE map_database;

CREATE TABLE IF NOT EXISTS users (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    session_token VARCHAR(32) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS favorites (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) UNSIGNED NOT NULL,
    type VARCHAR(20) NOT NULL,
    name VARCHAR(255) NOT NULL,
    artist VARCHAR(255) NULL,
    date_added DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_favorite (user_id, type, name, artist)
);

CREATE TABLE ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    item_type VARCHAR(20) NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    item_artist VARCHAR(255) NULL,
    rating INT NOT NULL, -- de 1 a 5
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY user_item_unique (user_id, item_type, item_name, item_artist)
);

ALTER TABLE users
ADD COLUMN spotify_user_id VARCHAR(255) NULL,
ADD COLUMN spotify_access_token TEXT NULL,
ADD COLUMN spotify_refresh_token TEXT NULL,
ADD COLUMN spotify_expires_at DATETIME NULL;