CREATE DATABASE IF NOT EXISTS `akino_app`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `akino_app`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(20) NOT NULL,
  `name` VARCHAR(120) DEFAULT NULL,
  `email` VARCHAR(190) DEFAULT NULL,
  `gender` VARCHAR(40) DEFAULT NULL,
  `birth_date` DATE DEFAULT NULL,
  `avatar_path` VARCHAR(255) NOT NULL DEFAULT 'img/people/image_2025-11-10_00-02-43.png',
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `is_blocked` TINYINT(1) NOT NULL DEFAULT 0,
  `blocked_at` DATETIME DEFAULT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_phone_unique` (`phone`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `auth_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(20) NOT NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `intent` ENUM('login', 'subscribe') NOT NULL DEFAULT 'login',
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `auth_codes_phone_index` (`phone`),
  KEY `auth_codes_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(80) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `duration_days` INT NOT NULL DEFAULT 30,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_plans_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_subscriptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `plan_id` BIGINT UNSIGNED NOT NULL,
  `started_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_subscriptions_user_id_index` (`user_id`),
  KEY `user_subscriptions_plan_id_index` (`plan_id`),
  KEY `user_subscriptions_ends_at_index` (`ends_at`),
  CONSTRAINT `user_subscriptions_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_subscriptions_plan_id_foreign`
    FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `movies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(160) NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `content_type` ENUM('movie', 'series') NOT NULL DEFAULT 'movie',
  `release_year` SMALLINT UNSIGNED NOT NULL,
  `rating` DECIMAL(3,1) NOT NULL DEFAULT 0.0,
  `genre` VARCHAR(120) NOT NULL,
  `country` VARCHAR(120) DEFAULT NULL,
  `director` VARCHAR(160) DEFAULT NULL,
  `duration_text` VARCHAR(60) DEFAULT NULL,
  `age_rating` VARCHAR(20) DEFAULT NULL,
  `description` TEXT NOT NULL,
  `poster_path` VARCHAR(255) NOT NULL,
  `card_path` VARCHAR(255) NOT NULL,
  `hero_path` VARCHAR(255) NOT NULL,
  `media_path` VARCHAR(255) DEFAULT NULL,
  `slider_order` INT DEFAULT NULL,
  `recommended_order` INT DEFAULT NULL,
  `new_order` INT DEFAULT NULL,
  `editors_choice_order` INT DEFAULT NULL,
  `for_you_order` INT DEFAULT NULL,
  `catalog_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `movies_slug_unique` (`slug`),
  KEY `movies_type_catalog_index` (`content_type`, `catalog_order`),
  KEY `movies_slider_order_index` (`slider_order`),
  KEY `movies_recommended_order_index` (`recommended_order`),
  KEY `movies_new_order_index` (`new_order`),
  KEY `movies_editors_choice_order_index` (`editors_choice_order`),
  KEY `movies_for_you_order_index` (`for_you_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seasons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `series_id` BIGINT UNSIGNED NOT NULL,
  `season_number` SMALLINT UNSIGNED NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `poster_path` VARCHAR(255) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `seasons_series_season_unique` (`series_id`, `season_number`),
  KEY `seasons_series_sort_index` (`series_id`, `sort_order`),
  CONSTRAINT `seasons_series_id_foreign`
    FOREIGN KEY (`series_id`) REFERENCES `movies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `episodes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `season_id` BIGINT UNSIGNED NOT NULL,
  `episode_number` SMALLINT UNSIGNED NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `duration_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `video_path` VARCHAR(255) DEFAULT NULL,
  `preview_path` VARCHAR(255) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `episodes_season_episode_unique` (`season_id`, `episode_number`),
  KEY `episodes_season_sort_index` (`season_id`, `sort_order`),
  CONSTRAINT `episodes_season_id_foreign`
    FOREIGN KEY (`season_id`) REFERENCES `seasons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `movie_favorites` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `movie_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `movie_favorites_user_movie_unique` (`user_id`, `movie_id`),
  KEY `movie_favorites_movie_id_index` (`movie_id`),
  CONSTRAINT `movie_favorites_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `movie_favorites_movie_id_foreign`
    FOREIGN KEY (`movie_id`) REFERENCES `movies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `watch_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `movie_id` BIGINT UNSIGNED NOT NULL,
  `views_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `first_viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `watch_history_user_movie_unique` (`user_id`, `movie_id`),
  KEY `watch_history_movie_id_index` (`movie_id`),
  KEY `watch_history_last_viewed_at_index` (`last_viewed_at`),
  CONSTRAINT `watch_history_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `watch_history_movie_id_foreign`
    FOREIGN KEY (`movie_id`) REFERENCES `movies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `watch_progress` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `movie_id` BIGINT UNSIGNED NOT NULL,
  `episode_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `position_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `completed_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `is_completed` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `watch_progress_user_item_unique` (`user_id`, `movie_id`, `episode_id`),
  KEY `watch_progress_user_updated_index` (`user_id`, `updated_at`),
  KEY `watch_progress_movie_episode_index` (`movie_id`, `episode_id`),
  CONSTRAINT `watch_progress_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `watch_progress_movie_id_foreign`
    FOREIGN KEY (`movie_id`) REFERENCES `movies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login` VARCHAR(60) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(120) NOT NULL,
  `avatar_path` VARCHAR(255) NOT NULL DEFAULT 'img/people/image_2025-11-10_00-02-43.png',
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_accounts_login_unique` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_user_action_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_account_id` BIGINT UNSIGNED DEFAULT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `action_type` VARCHAR(80) NOT NULL,
  `action_summary` VARCHAR(255) NOT NULL,
  `details_json` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `admin_user_action_logs_user_created_index` (`user_id`, `created_at`),
  KEY `admin_user_action_logs_admin_created_index` (`admin_account_id`, `created_at`),
  CONSTRAINT `admin_user_action_logs_admin_account_id_foreign`
    FOREIGN KEY (`admin_account_id`) REFERENCES `admin_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `admin_user_action_logs_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `subscription_plans` (`code`, `name`, `description`, `price`, `duration_days`)
VALUES ('akino-single', 'AKINO', 'Единая подписка AKINO на 30 дней.', 499.00, 30)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`),
  `price` = VALUES(`price`),
  `duration_days` = VALUES(`duration_days`);
