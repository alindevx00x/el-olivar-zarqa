-- Developer: Alindevx00x (https://alindevx00x.me)
CREATE DATABASE IF NOT EXISTS el_olivar_zarqa
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE el_olivar_zarqa;

CREATE TABLE IF NOT EXISTS stay_inquiries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    arrival_date DATE NOT NULL,
    departure_date DATE NOT NULL,
    guests TINYINT UNSIGNED NOT NULL,
    message TEXT NULL,
    consent_at DATETIME NOT NULL,
    status ENUM('new', 'contacted', 'closed') NOT NULL DEFAULT 'new',
    admin_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_inquiries_created_at (created_at),
    KEY idx_inquiries_dates (arrival_date, departure_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
