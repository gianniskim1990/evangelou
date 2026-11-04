-- Evangelou Club — separate Club database, migration 003 (failed QR scan counters).
--
-- STAGE-05C, owner decision D9 (2026-11-04): about 10 failed QR scans per 5 minutes per tablet;
-- the owner approved this small table for the counters. Approval covers the CODE only: applying
-- it to a staging or production database needs the owner's separate go-ahead.
--
-- Applied ONLY through EVC_Migrator (version + SHA-256 checksum, named lock), i.e. through the
-- STAGE-04A status page / command behind EVC_CLUB_MIGRATIONS_ENABLED, after its separation checks.
-- Additive only: no 001 / 002 table is touched. Plain CREATE TABLE (no IF NOT EXISTS), same reason
-- as 001: an existing differently-shaped table must fail loudly.
--
-- Status: executed ONLY in disposable CI/test databases.
--
-- Conventions (same as 001/002): InnoDB, utf8mb4, ASCII binary identifiers, *_utc = UTC DATETIME(6).
-- limiter_key is SHA-256 hex of "evc-lookup-limit-v1|<action>|session:<session_ref>", where
-- session_ref is already a keyed hash of the WordPress session token (EVC_Staff_Session): no token,
-- QR token, member id, name or other personal data is stored. One row = one tablet session in one
-- fixed 5-minute window; failures is incremented atomically (INSERT ... ON DUPLICATE KEY UPDATE).
-- Rows older than one day are pruned by the runtime limiter (DELETE on this table only).

CREATE TABLE evc_lookup_failures (
  limiter_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  window_start_utc DATETIME(6) NOT NULL,
  failures INT UNSIGNED NOT NULL,
  updated_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (limiter_key, window_start_utc),
  KEY ix_lookup_failures_window (window_start_utc),
  CONSTRAINT ck_lookup_failures_count CHECK (failures > 0),
  CONSTRAINT ck_lookup_failures_window CHECK (MICROSECOND(window_start_utc) = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
