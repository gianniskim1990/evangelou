-- Evangelou Club — separate Club database, migration 005 (web push subscriptions of members).
--
-- Owner decision D26 part 2 (STAGE-08 round 12): a member who taps «Θέλω ειδοποιήσεις» in the member app gives
-- explicit consent; the browser's push subscription is stored here so the shop can send short notifications from
-- its own server (no third-party push service). Design: docs/member-push.md.
-- Approval covers the CODE only: applying it to a staging or production database needs the owner's separate
-- go-ahead (EVC_CLUB_MIGRATIONS_TARGET 5, then the migrations page).
--
-- Applied ONLY through EVC_Migrator (version + SHA-256 checksum, named lock), i.e. through the STAGE-04A status
-- page / command behind EVC_CLUB_MIGRATIONS_ENABLED, after its separation checks.
--
-- ADDITIVE ONLY: one new table; no 001 / 002 / 003 / 004 table is touched. Plain CREATE TABLE (no IF NOT EXISTS),
-- same reason as 001: an existing differently-shaped table must fail loudly.
--
-- Runtime grants the Club database user needs on this table: SELECT, INSERT, UPDATE (never DELETE: a stopped or
-- dead subscription is marked with revoked_at_utc and kept). The readiness page checks them (SHOW GRANTS).
--
-- What is stored (and nothing else):
--   * member_id           the Club member (evc_members.id), never a name, e-mail or phone number;
--   * endpoint            the push service URL the browser gave (needed to send; https, an allowlisted push host,
--                         checked by EVC_Push_Endpoint before storing AND before every send); never logged;
--   * endpoint_hash       SHA-256 hex of endpoint: the unique key (one row per browser subscription);
--   * p256dh, auth        the subscription's public key and auth secret (base64url; RFC 8291 message encryption);
--   * push_service        coarse: fcm / apple / mozilla / windows (from the endpoint host);
--   * agent_family        coarse: chrome / safari / firefox / edge / samsung / other (never the User-Agent text);
--   * consent_at_utc      when the member tapped «Θέλω ειδοποιήσεις» (the GDPR basis is that explicit consent);
--   * created / updated, last success, success and consecutive failure counters, last failure;
--   * revoked_at_utc + revoked_reason: 'member' (the member stopped them or signed out) or 'gone' (the push service
--                         answered 404 / 410). A revoked row is never sent to again.
--
-- Status: executed ONLY in disposable CI/test databases.
--
-- Conventions (same as 001-004): InnoDB, utf8mb4, ASCII binary identifiers and hashes, *_utc = UTC DATETIME(6),
-- bounded column sizes.

CREATE TABLE evc_push_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id BIGINT UNSIGNED NOT NULL,
  endpoint_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  endpoint VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  p256dh VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  auth VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  push_service VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  agent_family VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  consent_at_utc DATETIME(6) NOT NULL,
  created_at_utc DATETIME(6) NOT NULL,
  updated_at_utc DATETIME(6) NOT NULL,
  last_success_at_utc DATETIME(6) NULL,
  success_count INT UNSIGNED NOT NULL DEFAULT 0,
  failure_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_failure_at_utc DATETIME(6) NULL,
  revoked_at_utc DATETIME(6) NULL,
  revoked_reason VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  KEY ix_push_member (member_id, revoked_at_utc),
  KEY ix_push_active (revoked_at_utc, id),
  CONSTRAINT fk_push_member FOREIGN KEY (member_id) REFERENCES evc_members (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_push_endpoint CHECK (endpoint LIKE 'https://%' AND CHAR_LENGTH(endpoint) BETWEEN 12 AND 1024),
  CONSTRAINT ck_push_hash CHECK (CHAR_LENGTH(endpoint_hash) = 64),
  CONSTRAINT ck_push_keys CHECK (CHAR_LENGTH(p256dh) BETWEEN 86 AND 100 AND CHAR_LENGTH(auth) BETWEEN 22 AND 32),
  CONSTRAINT ck_push_service CHECK (push_service IN ('fcm', 'apple', 'mozilla', 'windows')),
  CONSTRAINT ck_push_agent CHECK (agent_family IN ('chrome', 'safari', 'firefox', 'edge', 'samsung', 'other')),
  CONSTRAINT ck_push_revoked CHECK (
    (revoked_at_utc IS NULL AND revoked_reason IS NULL)
    OR (revoked_at_utc IS NOT NULL AND revoked_reason IS NOT NULL AND revoked_reason IN ('member', 'gone'))
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
