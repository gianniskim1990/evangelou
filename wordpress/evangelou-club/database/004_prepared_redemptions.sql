-- Evangelou Club — separate Club database, migration 004 (prepared redemption snapshots).
--
-- Owner decision D24 (2026-11-20): the till records HOW the free coffee was prepared (dose, sugar,
-- sweetener, milk, size, quick notes and a short staff note). Design: docs/prepared-redemption-transaction.md.
-- Approval covers the CODE only: applying it to a staging or production database needs the owner's
-- separate go-ahead (EVC_CLUB_MIGRATIONS_TARGET 4, then the migrations page).
--
-- Applied ONLY through EVC_Migrator (version + SHA-256 checksum, named lock), i.e. through the STAGE-04A
-- status page / command behind EVC_CLUB_MIGRATIONS_ENABLED, after its separation checks.
--
-- ADDITIVE ONLY:
--   * evc_redemptions gets four NULLABLE columns. Every existing (v1) row keeps them NULL and stays valid;
--     the v1 redeem route keeps inserting rows without them. The unique keys uq_redemptions_one_per_day and
--     uq_redemptions_request are NOT touched: they stay the only uniqueness authorities for v1 AND v2 rows,
--     so one free coffee per member per Athens day holds across both routes. The preparation is never part
--     of any key.
--   * evc_redemption_preparations is a new table: at most one snapshot per redemption (primary key = the
--     redemption id, foreign key RESTRICT). No 001 / 002 / 003 table is dropped or rewritten.
--
-- The staff note (note_text) is plain text written by the till staff: at most 80 characters, already trimmed
-- and normalised by the server (EVC_Prep_Note), never HTML, never logged, never shown to the customer. Its
-- retention is an OPEN owner decision (D9): see docs/go-live.md.
--
-- Status: executed ONLY in disposable CI/test databases.
--
-- Conventions (same as 001-003): InnoDB, utf8mb4, ASCII binary identifiers and hashes.

ALTER TABLE evc_redemptions
  ADD COLUMN fingerprint_version VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ADD COLUMN catalogue_version INT UNSIGNED NULL,
  ADD COLUMN prep_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ADD COLUMN snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ADD CONSTRAINT ck_redemptions_prepared CHECK (
    (fingerprint_version IS NULL AND catalogue_version IS NULL AND prep_hash IS NULL AND snapshot_hash IS NULL)
    OR (fingerprint_version = 'evc-redeem-v2' AND catalogue_version > 0 AND prep_hash IS NOT NULL AND snapshot_hash IS NOT NULL)
  );

CREATE TABLE evc_redemption_preparations (
  redemption_id BIGINT UNSIGNED NOT NULL,
  schema_version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  prep_version VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  catalogue_version INT UNSIGNED NOT NULL,
  coffee_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  coffee_label VARCHAR(255) NOT NULL,
  modifiers_json TEXT NOT NULL,
  quick_notes_json TEXT NOT NULL,
  note_text VARCHAR(80) NULL,
  prep_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (redemption_id),
  CONSTRAINT fk_preparations_redemption FOREIGN KEY (redemption_id) REFERENCES evc_redemptions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_preparations_catalogue CHECK (catalogue_version > 0),
  CONSTRAINT ck_preparations_coffee CHECK (CHAR_LENGTH(coffee_code) > 0),
  CONSTRAINT ck_preparations_note CHECK (note_text IS NULL OR CHAR_LENGTH(note_text) BETWEEN 1 AND 80)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
