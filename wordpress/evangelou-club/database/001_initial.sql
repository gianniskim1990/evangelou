-- Evangelou Club — separate Club database, migration 001 (initial schema).
--
-- Applied ONLY through EVC_Migrator, which records version + SHA-256 checksum
-- in evc_schema_migrations and refuses to continue if an applied file later
-- changes. Plain CREATE TABLE (no IF NOT EXISTS) is deliberate: running this
-- against a database that already holds differently-shaped evc_* tables must
-- fail loudly instead of silently keeping the old shape.
--
-- Status: executed ONLY in disposable CI/test databases. Never run against a
-- production, staging or customer database without explicit approval.
--
-- Conventions:
--   * InnoDB, utf8mb4. Identifiers/codes are ASCII with binary collation.
--   * *_utc columns hold UTC instants (application writes UTC; the gateway
--     also pins the session time_zone to +00:00).
--   * business_date is the Europe/Athens calendar day, computed in PHP.
--   * No names, phone numbers, e-mail addresses, credentials or payment data
--     are stored here. Contact data stays in WordPress/FluentCRM.
--   * MySQL >= 8.0.16 / MariaDB >= 10.2 enforce CHECK constraints; older
--     servers parse and ignore them, so PHP validates the same rules too.

CREATE TABLE evc_members (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_public_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  wp_user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at_utc DATETIME(6) NOT NULL,
  updated_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_members_public_id (member_public_id),
  UNIQUE KEY uq_members_wp_user (wp_user_id),
  CONSTRAINT ck_members_status CHECK (status IN ('enabled', 'disabled')),
  CONSTRAINT ck_members_wp_user CHECK (wp_user_id > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- QR tokens: structure only in this migration (issuance/rotation is a later
-- task). Only SHA-256 digests are stored. At most one ACTIVE token per member
-- is enforced by the unique key on a stored generated column that is NULL for
-- revoked rows (NULLs never collide in a UNIQUE index).
CREATE TABLE evc_qr_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  issued_at_utc DATETIME(6) NOT NULL,
  revoked_at_utc DATETIME(6) NULL,
  active_member_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN member_id ELSE NULL END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qr_tokens_hash (token_hash),
  UNIQUE KEY uq_qr_tokens_one_active (active_member_id),
  KEY ix_qr_tokens_member (member_id),
  CONSTRAINT fk_qr_tokens_member FOREIGN KEY (member_id) REFERENCES evc_members (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_qr_tokens_status CHECK (status IN ('active', 'revoked'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Redemption ledger. One row = one successful benefit redemption AND the
-- coffee served with it, written by a single INSERT so the coffee selection
-- can never be missing from a committed redemption.
--   uq_redemptions_one_per_day : the business invariant (race-free, DB-enforced)
--   uq_redemptions_request     : idempotent retries of the same request
-- membership_* columns snapshot the entitlement that justified the redemption.
CREATE TABLE evc_redemptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id BIGINT UNSIGNED NOT NULL,
  benefit_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_date DATE NOT NULL,
  coffee_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  staff_wp_user_id BIGINT UNSIGNED NOT NULL,
  membership_source VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  membership_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  membership_level_ref VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  membership_expires_at_utc DATETIME(6) NOT NULL,
  redeemed_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_redemptions_one_per_day (member_id, benefit_type, business_date),
  UNIQUE KEY uq_redemptions_request (request_id),
  KEY ix_redemptions_member_time (member_id, redeemed_at_utc),
  CONSTRAINT fk_redemptions_member FOREIGN KEY (member_id) REFERENCES evc_members (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_redemptions_coffee CHECK (CHAR_LENGTH(coffee_code) > 0),
  CONSTRAINT ck_redemptions_staff CHECK (staff_wp_user_id > 0),
  CONSTRAINT ck_redemptions_membership CHECK (membership_status = 'active')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only audit trail. Successful redemptions write their event in the
-- same transaction as the ledger row. details_json must never contain
-- personal data, raw QR tokens or SQL error text.
CREATE TABLE evc_audit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  outcome VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  member_id BIGINT UNSIGNED NULL,
  redemption_id BIGINT UNSIGNED NULL,
  request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  staff_wp_user_id BIGINT UNSIGNED NULL,
  occurred_at_utc DATETIME(6) NOT NULL,
  details_json TEXT NULL,
  PRIMARY KEY (id),
  KEY ix_audit_member_time (member_id, occurred_at_utc),
  KEY ix_audit_request (request_id),
  KEY ix_audit_redemption (redemption_id),
  CONSTRAINT fk_audit_member FOREIGN KEY (member_id) REFERENCES evc_members (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_audit_redemption FOREIGN KEY (redemption_id) REFERENCES evc_redemptions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
