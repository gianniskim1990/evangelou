-- DRAFT ONLY. Review before applying to isolated staging database.
-- No SQL has been executed. No WordPress production tables are touched.
-- UTC timestamps; Athens business day stored separately.
-- No personal contact details, credentials or payment data.

CREATE TABLE IF NOT EXISTS evc_members (
  member_ref VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  wp_user_id BIGINT UNSIGNED NOT NULL,
  qr_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (member_ref),
  UNIQUE KEY uq_wp_user (wp_user_id),
  UNIQUE KEY uq_qr_hash (qr_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evc_redemptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_ref VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  benefit_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_date DATE NOT NULL,
  request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  redeemed_at_utc DATETIME(6) NOT NULL,
  staff_wp_user_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_one_per_business_day (member_ref, benefit_type, business_date),
  UNIQUE KEY uq_idempotency (request_id),
  KEY ix_member_history (member_ref, redeemed_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evc_coffee_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_ref VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  redemption_id BIGINT UNSIGNED NULL,
  coffee_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  recorded_at_utc DATETIME(6) NOT NULL,
  staff_wp_user_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_redemption_history (redemption_id),
  KEY ix_member_history (member_ref, recorded_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
