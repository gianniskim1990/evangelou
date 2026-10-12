<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Versioned, purpose-separated HMAC key ring for payment identity (Task 1D-I).
 *
 * Secrets are INJECTED by a trusted configuration boundary (future: server
 * side constants/environment, never the database, WordPress options, Git or
 * logs). Nothing here generates, persists, prints or exports key material:
 * keys never leave this object; callers get HMAC outputs only.
 *
 * Each version is one master secret (raw bytes, >= 32 bytes). Per-purpose
 * keys are derived with HKDF-SHA256 whose info string binds purpose,
 * version and environment, so a movement id can never equal an alias ref,
 * correction id or row ref, and live/sandbox keys always differ.
 *
 * Versions:
 *  - identity_version: the PINNED version used to mint NEW canonical
 *    movement and correction ids. Identity is meant to stay stable; a new
 *    identity version is only for recovery after compromise/loss and never
 *    re-derives existing ids;
 *  - issued_identity_versions: every identity version EVER used. If any of
 *    them has no secret here, the identity history is INCOMPLETE and the
 *    resolver refuses to mint new ids (an old payment could otherwise get a
 *    second id);
 *  - alias_version: version used for NEW alias references; retained older
 *    versions are still tried for lookups (candidate lookup).
 * Production rotation, backup and compromise procedures are NOT implemented
 * (see README): this class only makes versions explicit and fails closed.
 */
final class EVC_Payment_Key_Ring {
    const PURPOSE_MOVEMENT = 'movement';
    const PURPOSE_ALIAS = 'alias';
    const PURPOSE_CORRECTION = 'correction';
    const PURPOSE_ROW = 'row';
    const PURPOSE_CHECK = 'check';

    const MIN_SECRET_BYTES = 32;
    /** Rejects obviously non-random material (e.g. repeated characters). */
    const MIN_DISTINCT_BYTES = 12;
    const HKDF_SALT = 'evangelou-club/payment-identity/hkdf/v1';

    /** @var string */
    private $environment;
    /** @var array<int,string> version => master secret */
    private $secrets;
    /** @var int */
    private $identity_version;
    /** @var int */
    private $alias_version;
    /** @var int[] */
    private $issued_identity_versions;

    /**
     * @param array<int,string> $secrets                 version => raw secret bytes (decode base64/hex BEFORE)
     * @param int[]             $issued_identity_versions every identity version ever used in this environment
     * @param array<int,string> $expected_fingerprints    version => fingerprint() recorded when the key was
     *                                                    first used. A WRONG key under a known version would
     *                                                    silently produce new identities; with fingerprints it
     *                                                    fails closed. (Persisting them independently of the
     *                                                    key, e.g. in the Club DB, is future migration 003.)
     */
    public function __construct(string $environment, array $secrets, int $identity_version, int $alias_version, array $issued_identity_versions, array $expected_fingerprints = array()) {
        if (!preg_match(EVC_Verified_Movement::ENVIRONMENT_PATTERN, $environment)) {
            throw new InvalidArgumentException('Invalid key ring environment.');
        }
        if ($secrets === array()) {
            throw new InvalidArgumentException('Payment identity key material is missing.');
        }
        foreach ($secrets as $version => $secret) {
            if (!is_int($version) || $version < 1 || !is_string($secret)) {
                throw new InvalidArgumentException('Key versions must be positive integers with string secrets.');
            }
            if (strlen($secret) < self::MIN_SECRET_BYTES || count(count_chars($secret, 1)) < self::MIN_DISTINCT_BYTES) {
                throw new InvalidArgumentException('Payment identity key too weak (need >= 32 random bytes).');
            }
        }
        if (!isset($secrets[$identity_version]) || !isset($secrets[$alias_version])) {
            throw new InvalidArgumentException('Current identity and alias key versions must be present.');
        }
        foreach ($issued_identity_versions as $v) {
            if (!is_int($v) || $v < 1) {
                throw new InvalidArgumentException('Issued identity versions must be positive integers.');
            }
        }
        if (!in_array($identity_version, $issued_identity_versions, true)) {
            throw new InvalidArgumentException('The pinned identity version must be listed as issued.');
        }
        $issued = array_values(array_unique($issued_identity_versions));
        rsort($issued);
        $this->environment = $environment;
        $this->secrets = $secrets;
        $this->identity_version = $identity_version;
        $this->alias_version = $alias_version;
        $this->issued_identity_versions = $issued;
        foreach ($expected_fingerprints as $version => $expected) {
            if (!is_int($version) || !isset($secrets[$version]) || !is_string($expected) || !hash_equals($expected, $this->fingerprint($version))) {
                throw new InvalidArgumentException('Payment identity key does not match its recorded fingerprint.');
            }
        }
    }

    /** Non-secret check value of a key version (HMAC of a constant under its own purpose key). */
    public function fingerprint(int $version): string {
        return $this->mac(self::PURPOSE_CHECK, $version, 'evc.payment.key-check.v1');
    }

    public function environment(): string {
        return $this->environment;
    }

    public function identity_version(): int {
        return $this->identity_version;
    }

    public function alias_version(): int {
        return $this->alias_version;
    }

    /** @return int[] every version with key material, newest first */
    public function retained_versions(): array {
        $v = array_keys($this->secrets);
        rsort($v);
        return $v;
    }

    /** @return int[] issued identity versions whose key material is still present, newest first */
    public function available_identity_versions(): array {
        return array_values(array_filter($this->issued_identity_versions, function (int $v) {
            return isset($this->secrets[$v]);
        }));
    }

    /** False when any identity version ever issued is no longer available: minting new ids must stop. */
    public function identity_history_complete(): bool {
        return count($this->available_identity_versions()) === count($this->issued_identity_versions);
    }

    public function has_version(int $version): bool {
        return isset($this->secrets[$version]);
    }

    /** HMAC-SHA256 (64 lowercase hex) of $message under the purpose key of $version. */
    public function mac(string $purpose, int $version, string $message): string {
        if (!in_array($purpose, array(self::PURPOSE_MOVEMENT, self::PURPOSE_ALIAS, self::PURPOSE_CORRECTION, self::PURPOSE_ROW, self::PURPOSE_CHECK), true)) {
            throw new InvalidArgumentException('Unknown key purpose.');
        }
        if (!isset($this->secrets[$version])) {
            throw new EVC_Payment_Key_Unavailable_Exception('Key version not available.');
        }
        $key = hash_hkdf('sha256', $this->secrets[$version], 32, 'evc/' . $purpose . '/v' . $version . '/' . $this->environment, self::HKDF_SALT);
        return hash_hmac('sha256', $message, $key);
    }

    public function __debugInfo() {
        return array(
            'environment' => $this->environment,
            'versions' => $this->retained_versions(),
            'identity_version' => $this->identity_version,
            'alias_version' => $this->alias_version,
            'issued_identity_versions' => $this->issued_identity_versions,
            'secrets' => '[redacted]',
        );
    }

    public function __serialize(): array {
        throw new LogicException('Key ring cannot be serialized.');
    }

    public function __unserialize(array $data): void {
        throw new LogicException('Key ring cannot be unserialized.');
    }
}

/** Requested key version is not configured: fail closed. */
final class EVC_Payment_Key_Unavailable_Exception extends RuntimeException {
}
