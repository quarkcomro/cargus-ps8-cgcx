/**
 * sql/install.sql
 * Database tables for Cargus PrestaShop Integration - V2.3.4 Compatible
 */

/* Cache table for Ship & Go lockers/points */
CREATE TABLE IF NOT EXISTS `PREFIX_cargus_pudo` (
    `id_pudo` VARCHAR(50) NOT NULL,
    `name` VARCHAR(128) NOT NULL,
    `city` VARCHAR(64) NOT NULL,
    `county` VARCHAR(64) NOT NULL,
    `address` TEXT NOT NULL,
    `latitude` DECIMAL(10, 8) NOT NULL,
    `longitude` DECIMAL(11, 8) NOT NULL,
    `type` VARCHAR(20) NOT NULL, /* Locker or Point */
    `schedule` TEXT NULL,
    `is_active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_pudo`),
    INDEX `idx_city` (`city`),
    INDEX `idx_county` (`county`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Table to associate a cart/order with the selected PUDO locker */
CREATE TABLE IF NOT EXISTS `PREFIX_cargus_order_pudo` (
    `id_cart` INT(10) UNSIGNED NOT NULL,
    `id_order` INT(10) UNSIGNED DEFAULT 0,
    `id_pudo` VARCHAR(50) NOT NULL,
    `pudo_details` TEXT NULL,
    PRIMARY KEY (`id_cart`),
    INDEX `idx_order` (`id_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Cache table for Counties/Cities to speed up normalization */
CREATE TABLE IF NOT EXISTS `PREFIX_cargus_geo_cache` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cargus_city_id` VARCHAR(50) NOT NULL,
    `city_name` VARCHAR(100) NOT NULL,
    `county_name` VARCHAR(100) NOT NULL,
    `normalized_name` VARCHAR(100) NOT NULL,
    INDEX `idx_normalized_name` (`normalized_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;