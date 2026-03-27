-- CTFTimeParser Database Schema
-- MySQL 8+ / utf8mb4

CREATE TABLE IF NOT EXISTS `parser_buffer` (
    `event_id`   INT UNSIGNED NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT NOW(),
    PRIMARY KEY (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ctf_events` (
    `id`          INT UNSIGNED  NOT NULL,
    `title`       VARCHAR(255)  NOT NULL,
    `url`         VARCHAR(512)  DEFAULT NULL,
    `ctftime_url` VARCHAR(512)  DEFAULT NULL,
    `start_time`  DATETIME      DEFAULT NULL,
    `finish_time` DATETIME      DEFAULT NULL,
    `format`      VARCHAR(64)   DEFAULT NULL,
    `weight`      DECIMAL(8,5)  DEFAULT NULL,
    `onsite`      TINYINT(1)    NOT NULL DEFAULT 0,
    `location`    VARCHAR(255)  DEFAULT NULL,
    `description` TEXT          DEFAULT NULL,
    `logo_url`    VARCHAR(512)  DEFAULT NULL,
    `is_safe`     TINYINT(1)    NOT NULL DEFAULT 1,
    `posted_at`   DATETIME      DEFAULT NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT NOW(),
    PRIMARY KEY (`id`),
    INDEX `idx_posted_at` (`posted_at`),
    INDEX `idx_start_time` (`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
