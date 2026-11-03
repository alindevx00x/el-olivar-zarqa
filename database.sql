-- Developer: Alindevx00x (https://alindevx00x.me)
-- Database schema for El Olivar Zarqa
-- Compatible with local dev (MAMP) and live servers (CloudPanel / cPanel / Docker)
--
-- CLI Import example:
--   mysql -u elolivarzarqadbusr -p elolivarzarqadb < database.sql
-- Or select your database in phpMyAdmin and import this file.

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

CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO site_settings (setting_key, setting_value)
VALUES 
    ('whatsapp_number', '212600000000'),
    ('whatsapp_enabled', '1');
