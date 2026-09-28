-- ============================================================================
-- 093: Self-service password reset (email link + 6-digit OTP).
--
-- Supersedes the "no forgot-password endpoint" assumption documented in
-- backend/config/rate_limits.php: resets were previously admin-driven only.
--
-- Security shape:
--   * Only SHA-256 HASHES of the emailed token and the OTP are stored. A dump
--     of this table therefore does not hand an attacker a working reset link.
--   * token_hash is UNIQUE so a lookup is a single index hit and a collision is
--     impossible rather than merely unlikely.
--   * otp_verified gates the final step: holding the link alone is not enough,
--     the 6-digit code must also have been entered.
--   * otp_attempts is incremented on every wrong code and the row is burned at
--     MAX_OTP_ATTEMPTS, so a 6-digit space cannot be walked (1e6 guesses).
--   * expires_at + consumed_at make a reset single-use and time-boxed.
--
-- Idempotent: re-running is a no-op.
-- ============================================================================

CREATE TABLE IF NOT EXISTS password_resets (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT          NOT NULL,
  email         VARCHAR(255) NOT NULL,
  -- SHA-256 hex of the random 32-byte token placed in the emailed link.
  token_hash    CHAR(64)     NOT NULL,
  -- SHA-256 hex of the 6-digit code the user must also type.
  otp_hash      CHAR(64)     NOT NULL,
  otp_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  otp_verified  TINYINT(1)  NOT NULL DEFAULT 0,
  expires_at    DATETIME     NOT NULL,
  consumed_at   DATETIME     NULL DEFAULT NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_resets_token (token_hash),
  KEY idx_password_resets_user (user_id),
  KEY idx_password_resets_email (email),
  KEY idx_password_resets_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Housekeeping: rows are only meaningful until they expire. The lookup
-- predicate filters on expires_at/consumed_at, so an index is what keeps the
-- verification path from degrading into a scan as this table grows.
