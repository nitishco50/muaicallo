-- allomusic backend schema (MySQL 5.7+ / MariaDB 10.4+)
-- Charset utf8mb4 so emoji and Indic text survive.

CREATE TABLE IF NOT EXISTS admins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(100) NOT NULL DEFAULT 'Admin',
  role ENUM('owner','admin') NOT NULL DEFAULT 'admin',
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  last_ip VARCHAR(45) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid CHAR(36) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(100) NOT NULL DEFAULT '',
  status ENUM('active','banned') NOT NULL DEFAULT 'active',
  plan VARCHAR(32) NOT NULL DEFAULT 'free',
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  last_seen_at DATETIME NULL,
  last_ip VARCHAR(45) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_uuid (uuid),
  UNIQUE KEY uq_user_email (email),
  KEY idx_user_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  device_id VARCHAR(128) NOT NULL DEFAULT '',
  device_name VARCHAR(128) NOT NULL DEFAULT '',
  app_version VARCHAR(32) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_token_user (user_id),
  KEY idx_token_expiry (expires_at),
  CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  device_id VARCHAR(128) NOT NULL,
  model VARCHAR(128) NOT NULL DEFAULT '',
  sdk VARCHAR(16) NOT NULL DEFAULT '',
  app_version VARCHAR(32) NOT NULL DEFAULT '',
  last_seen DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_device (user_id, device_id),
  KEY idx_device_user (user_id),
  CONSTRAINT fk_device_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  ok TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_attempt_lookup (email, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) NOT NULL,
  v MEDIUMTEXT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_data (
  user_id BIGINT UNSIGNED NOT NULL,
  ns VARCHAR(64) NOT NULL,
  json MEDIUMTEXT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, ns),
  CONSTRAINT fk_data_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  read_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_notice_user (user_id, created_at),
  CONSTRAINT fk_notice_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_icons (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  icon_key VARCHAR(64) NOT NULL,
  name VARCHAR(100) NOT NULL,
  alias VARCHAR(100) NOT NULL DEFAULT '',
  preview VARCHAR(255) NOT NULL DEFAULT '',
  sort INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_icon_key (icon_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id BIGINT UNSIGNED NULL,
  action VARCHAR(64) NOT NULL,
  target VARCHAR(190) NOT NULL DEFAULT '',
  meta TEXT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_time (created_at),
  CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(32) NOT NULL,
  track_id VARCHAR(64) NOT NULL DEFAULT '',
  title VARCHAR(255) NOT NULL DEFAULT '',
  artist VARCHAR(255) NOT NULL DEFAULT '',
  album VARCHAR(255) NOT NULL DEFAULT '',
  art_url VARCHAR(512) NOT NULL DEFAULT '',
  genre VARCHAR(128) NOT NULL DEFAULT '',
  source VARCHAR(32) NOT NULL DEFAULT '',
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  listen_ms INT UNSIGNED NOT NULL DEFAULT 0,
  extra TEXT NULL,
  client_ts BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_events_user_time (user_id, created_at),
  KEY idx_events_user_type (user_id, type),
  KEY idx_events_user_track (user_id, track_id),
  CONSTRAINT fk_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_signals (
  user_id BIGINT UNSIGNED NOT NULL,
  signal_key VARCHAR(64) NOT NULL,
  num DOUBLE NULL,
  txt VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, signal_key),
  CONSTRAINT fk_signals_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Community: one row per listener and song for likes/dislikes, bookmarks,
-- comments, comment likes/reports, and who follows whom. The song itself is
-- identified by a stable key (the YouTube video id, or a hashed local id) so
-- the same track is one community object across phones.
CREATE TABLE IF NOT EXISTS song_reactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  song_key VARCHAR(191) NOT NULL,
  value TINYINT NOT NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  artist VARCHAR(255) NOT NULL DEFAULT '',
  art_url VARCHAR(512) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reaction_user_song (user_id, song_key),
  KEY idx_reaction_song_value (song_key, value),
  KEY idx_reaction_user_time (user_id, updated_at),
  CONSTRAINT fk_reaction_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS song_bookmarks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  song_key VARCHAR(191) NOT NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  artist VARCHAR(255) NOT NULL DEFAULT '',
  art_url VARCHAR(512) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bookmark_user_song (user_id, song_key),
  KEY idx_bookmark_song (song_key),
  KEY idx_bookmark_user_time (user_id, created_at),
  CONSTRAINT fk_bookmark_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS song_comments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  song_key VARCHAR(191) NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  body VARCHAR(1000) NOT NULL,
  status ENUM('visible', 'hidden') NOT NULL DEFAULT 'visible',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_comments_song_time (song_key, created_at),
  KEY idx_comments_user_time (user_id, created_at),
  KEY idx_comments_parent (parent_id),
  CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comment_likes (
  comment_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (comment_id, user_id),
  CONSTRAINT fk_comment_like_comment FOREIGN KEY (comment_id) REFERENCES song_comments (id) ON DELETE CASCADE,
  CONSTRAINT fk_comment_like_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comment_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  comment_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_comment_report (comment_id, user_id),
  CONSTRAINT fk_comment_report_comment FOREIGN KEY (comment_id) REFERENCES song_comments (id) ON DELETE CASCADE,
  CONSTRAINT fk_comment_report_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_follows (
  follower_id BIGINT UNSIGNED NOT NULL,
  following_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (follower_id, following_id),
  KEY idx_follows_following (following_id, created_at),
  CONSTRAINT fk_follows_follower FOREIGN KEY (follower_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_follows_following FOREIGN KEY (following_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS artist_follows (
  user_id BIGINT UNSIGNED NOT NULL,
  artist VARCHAR(191) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, artist),
  KEY idx_artist_follows_artist (artist),
  CONSTRAINT fk_artist_follow_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default app configuration. INSERT IGNORE keeps re-runs safe.
INSERT IGNORE INTO settings (k, v) VALUES
  ('app_name', 'allomusic'),
  ('maintenance', '0'),
  ('maintenance_message', 'allomusic is being updated. Please try again shortly.'),
  ('min_version_code', '1'),
  ('latest_version_code', '6'),
  ('update_url', 'https://github.com/nitishco50/muaicallo/releases/latest'),
  ('force_update', '0'),
  ('icon_set', 'user_custom'),
  ('announcement', ''),
  ('brand_logo', ''),
  ('flags_json', '{"downloads":true,"offline":true,"listen_together":true}');

-- Icon sets the APK ships with. The Android app maps these keys to activity-alias names.
INSERT IGNORE INTO app_icons (icon_key, name, alias, preview, sort) VALUES
  ('default', 'AlloMusic Default', 'LauncherDefault', '', 0),
  ('user_custom', 'AlloMusic Custom (uploaded)', 'LauncherUserCustom', '', 1),
  ('liquid_light', 'Liquid Glass Light', 'LauncherGlassLight', '', 2),
  ('liquid_dark', 'Liquid Glass Dark', 'LauncherGlassDark', '', 3),
  ('liquid_clear', 'Liquid Glass Clear', 'LauncherGlassClear', '', 4),
  ('liquid_clear_dark', 'Liquid Glass Clear Dark', 'LauncherGlassClearDark', '', 5),
  ('samsung_light', 'Samsung Glass Light', 'LauncherSamsungGlass', '', 6),
  ('samsung_dark', 'Samsung Glass Dark', 'LauncherSamsungGlassDark', '', 7),
  ('samsung_clear', 'Samsung Glass Clear', 'LauncherSamsungGlassClear', '', 8),
  ('samsung_clear_dark', 'Samsung Glass Clear Dark', 'LauncherSamsungGlassClearDark', '', 9),
  ('pixel_light', 'Pixel Glass Light', 'LauncherPixelGlass', '', 10),
  ('pixel_dark', 'Pixel Glass Dark', 'LauncherPixelGlassDark', '', 11),
  ('pixel_clear', 'Pixel Glass Clear', 'LauncherPixelGlassClear', '', 12),
  ('pixel_clear_dark', 'Pixel Glass Clear Dark', 'LauncherPixelGlassClearDark', '', 13);
