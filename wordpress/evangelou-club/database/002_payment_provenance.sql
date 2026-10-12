-- Evangelou Club — separate Club database, migration 002 (payment provenance).
--
-- Storage for EVC_Sql_Provenance_Store (Task 1D-G). Additive only: the 001
-- tables, including the redemption ledger, are untouched. Applied ONLY
-- through EVC_Migrator (version + SHA-256 checksum, named lock).
--
-- Status: executed ONLY in disposable CI/test databases. NOT wired to any
-- payment, PMPro or WooCommerce flow; nothing writes these tables in
-- production. Never run against a production, staging or customer database
-- without explicit approval and a backup.
--
-- Conventions (same as 001): InnoDB, utf8mb4, ASCII binary identifiers,
-- *_utc = UTC DATETIME(6). All identifiers are opaque 64-hex values computed
-- by a future trusted adapter (HMAC, not reversible). No names, e-mails,
-- raw order/transaction numbers, card data or secrets are stored.
-- Opaque ids are VARCHAR(64) + CHECK length 64 (not CHAR(64) as in 001):
-- MariaDB 10.11 rejects STORED generated columns that read a CHAR column
-- (error 1901, value depends on PAD_CHAR_TO_FULL_LENGTH), verified locally.
--
-- Invariants (see interface-evc-provenance-store.php). The store re-checks
-- every rule inside its transaction; these constraints are the backstop:
--   I1 PK movement_id
--   I2 PK (alias_namespace, alias_ref); at most one order anchor per movement
--   I3 PK periods.movement_id; composite FK binds the period to the exact
--      movement, member, level AND PMPro membership row (pmpro_row_ref)
--   I4 one initial period per member; no period has two successors; the
--      predecessor belongs to the same member; member head = chain tip
--   I5 PK correction_id; UNIQUE (movement_id, seq); prior correction belongs
--      to the same movement; runtime user gets no UPDATE/DELETE on history
--   I7/I8 evc_pay_correction_heads is the per-movement lock row. It has NO
--      foreign key to movements on purpose: a correction verified before its
--      payment (refund-first) creates the head and stays UNRESOLVED until a
--      trusted reconciliation records the movement under the same id.

CREATE TABLE evc_pay_movements (
  movement_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  environment VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  wp_user_id BIGINT UNSIGNED NOT NULL,
  level_id INT UNSIGNED NOT NULL,
  pmpro_row_ref VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  verified_at_utc DATETIME(6) NOT NULL,
  amount_minor INT UNSIGNED NOT NULL,
  currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  authority VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  evidence_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  recorded_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (movement_id),
  UNIQUE KEY uq_pay_mv_period_binding (movement_id, wp_user_id, level_id, pmpro_row_ref),
  KEY ix_pay_mv_member_time (wp_user_id, verified_at_utc),
  CONSTRAINT ck_pay_mv_ids CHECK (CHAR_LENGTH(movement_id) = 64 AND CHAR_LENGTH(pmpro_row_ref) = 64 AND wp_user_id > 0 AND level_id > 0),
  CONSTRAINT ck_pay_mv_state CHECK (state = 'confirmed'),
  CONSTRAINT ck_pay_mv_whole_second CHECK (MICROSECOND(verified_at_utc) = 0),
  CONSTRAINT ck_pay_mv_amount CHECK (amount_minor > 0),
  CONSTRAINT ck_pay_mv_authority CHECK (authority IN ('gateway_api', 'order_state', 'manual_admin'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE evc_pay_aliases (
  alias_namespace VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  alias_ref VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  movement_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  anchor_movement_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN alias_namespace IN ('wc_order', 'pmpro_order', 'manual_receipt') THEN movement_id ELSE NULL END) STORED,
  recorded_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (alias_namespace, alias_ref),
  UNIQUE KEY uq_pay_alias_one_anchor (anchor_movement_id),
  KEY ix_pay_alias_movement (movement_id),
  CONSTRAINT fk_pay_alias_movement FOREIGN KEY (movement_id) REFERENCES evc_pay_movements (movement_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_pay_alias_ref CHECK (CHAR_LENGTH(alias_ref) = 64),
  CONSTRAINT ck_pay_alias_namespace CHECK (alias_namespace IN ('wc_order', 'pmpro_order', 'manual_receipt', 'viva_transaction', 'paypal_transaction'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE evc_pay_periods (
  movement_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  wp_user_id BIGINT UNSIGNED NOT NULL,
  level_id INT UNSIGNED NOT NULL,
  pmpro_row_ref VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  start_utc DATETIME(6) NOT NULL,
  end_utc DATETIME(6) NOT NULL,
  kind VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  predecessor_movement_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  first_of_member BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN predecessor_movement_id IS NULL THEN wp_user_id ELSE NULL END) STORED,
  PRIMARY KEY (movement_id),
  UNIQUE KEY uq_pay_period_member_key (movement_id, wp_user_id),
  UNIQUE KEY uq_pay_period_successor (predecessor_movement_id),
  UNIQUE KEY uq_pay_period_one_initial (first_of_member),
  KEY ix_pay_period_predecessor_member (predecessor_movement_id, wp_user_id),
  CONSTRAINT fk_pay_period_movement FOREIGN KEY (movement_id, wp_user_id, level_id, pmpro_row_ref) REFERENCES evc_pay_movements (movement_id, wp_user_id, level_id, pmpro_row_ref) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_pay_period_predecessor FOREIGN KEY (predecessor_movement_id, wp_user_id) REFERENCES evc_pay_periods (movement_id, wp_user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_pay_period_order CHECK (end_utc > start_utc),
  CONSTRAINT ck_pay_period_kind CHECK (kind IN ('initial', 'early', 'late')),
  CONSTRAINT ck_pay_period_initial CHECK ((kind = 'initial') = (predecessor_movement_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-member chain head: the row locked (SELECT ... FOR UPDATE) by every
-- write that extends a member's chain; latest_movement_id is the tip that
-- a new period's predecessor must equal (compare-and-set).
CREATE TABLE evc_pay_member_heads (
  wp_user_id BIGINT UNSIGNED NOT NULL,
  latest_movement_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  version BIGINT UNSIGNED NOT NULL,
  updated_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (wp_user_id),
  KEY ix_pay_head_latest (latest_movement_id, wp_user_id),
  CONSTRAINT fk_pay_head_latest FOREIGN KEY (latest_movement_id, wp_user_id) REFERENCES evc_pay_periods (movement_id, wp_user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_pay_head_member CHECK (wp_user_id > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-movement correction head: the lock row for I1/I7/I8. Created by the
-- first write that touches a movement id (payment or correction). Unresolved
-- obligations = heads with corrections and no evc_pay_movements row.
CREATE TABLE evc_pay_correction_heads (
  movement_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  latest_correction_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  latest_kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
  correction_count INT UNSIGNED NOT NULL,
  version BIGINT UNSIGNED NOT NULL,
  created_at_utc DATETIME(6) NOT NULL,
  updated_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (movement_id),
  CONSTRAINT ck_pay_chead_id CHECK (CHAR_LENGTH(movement_id) = 64),
  CONSTRAINT ck_pay_chead_consistent CHECK ((correction_count = 0) = (latest_correction_id IS NULL) AND (latest_correction_id IS NULL) = (latest_kind IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE evc_pay_corrections (
  correction_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  movement_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  seq INT UNSIGNED NOT NULL,
  kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  authority VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  effective_at_utc DATETIME(6) NOT NULL,
  prior_correction_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  recorded_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (correction_id),
  UNIQUE KEY uq_pay_corr_seq (movement_id, seq),
  UNIQUE KEY uq_pay_corr_identity (correction_id, movement_id),
  KEY ix_pay_corr_prior (prior_correction_id, movement_id),
  CONSTRAINT fk_pay_corr_head FOREIGN KEY (movement_id) REFERENCES evc_pay_correction_heads (movement_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_pay_corr_prior FOREIGN KEY (prior_correction_id, movement_id) REFERENCES evc_pay_corrections (correction_id, movement_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_pay_corr_id CHECK (CHAR_LENGTH(correction_id) = 64),
  CONSTRAINT ck_pay_corr_kind CHECK (kind IN ('refunded', 'partially_refunded', 'reversed', 'voided')),
  CONSTRAINT ck_pay_corr_authority CHECK (authority IN ('gateway_api', 'order_state', 'manual_admin')),
  CONSTRAINT ck_pay_corr_seq CHECK (seq >= 1 AND (seq = 1) = (prior_correction_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
