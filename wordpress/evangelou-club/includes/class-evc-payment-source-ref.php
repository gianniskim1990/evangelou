<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * One RAW source identifier, scoped to provider, merchant/account and
 * environment (Task 1D-I). Pure value object: it proves nothing about
 * payment and carries no trust level of its own.
 *
 * Identifiers are kept EXACTLY as the provider issued them: no trimming,
 * case folding or other normalisation (provider id semantics are not ours
 * to change). Anything outside the accepted shape is rejected instead.
 * The raw identifier is never printed: __debugInfo() redacts it and there
 * is no __toString(). It must not be stored or logged; only keyed HMACs
 * derived by EVC_Payment_Identity leave the process.
 *
 * Kinds (what the identifier names, NOT whether money moved):
 *   capture         a provider money transaction (PayPal capture id, Viva
 *                   charge/capture transaction id) -- a settlement anchor
 *   receipt         a Club-issued manual payment receipt -- settlement anchor
 *   authorization   a held authorization (never money by itself)
 *   provider_order  a provider order (PayPal order id, Viva orderCode)
 *   refund          a provider refund/void/reversal transaction id
 *   commerce_order  a WooCommerce order id or PMPro order code
 */
final class EVC_Payment_Source_Ref {
    const PROVIDER_PAYPAL = 'paypal';
    const PROVIDER_VIVA = 'viva';
    const PROVIDER_MANUAL = 'manual';
    const PROVIDER_WOOCOMMERCE = 'woocommerce';
    const PROVIDER_PMPRO = 'pmpro';

    const KIND_CAPTURE = 'capture';
    const KIND_RECEIPT = 'receipt';
    const KIND_AUTHORIZATION = 'authorization';
    const KIND_PROVIDER_ORDER = 'provider_order';
    const KIND_REFUND = 'refund';
    const KIND_COMMERCE_ORDER = 'commerce_order';

    /** Printable ASCII without spaces; provider ids in scope are alphanumeric, UUID or hex. */
    const TOKEN_PATTERN = '/^[\x21-\x7E]+$/D';
    const MAX_SCOPE_BYTES = 128;
    const MAX_ID_BYTES = 255;

    /** @var string */
    private $provider;
    /** @var string */
    private $merchant_scope;
    /** @var string */
    private $environment;
    /** @var string */
    private $kind;
    /** @var string */
    private $id;

    public function __construct(string $provider, string $merchant_scope, string $environment, string $kind, string $id) {
        $kinds = self::kinds_by_provider();
        if (!isset($kinds[$provider])) {
            throw new InvalidArgumentException('Unknown payment source provider.');
        }
        if (!in_array($kind, $kinds[$provider], true)) {
            throw new InvalidArgumentException('Identifier kind not valid for this provider.');
        }
        if (!preg_match(EVC_Verified_Movement::ENVIRONMENT_PATTERN, $environment)) {
            throw new InvalidArgumentException('Invalid environment.');
        }
        if (strlen($merchant_scope) > self::MAX_SCOPE_BYTES || !preg_match(self::TOKEN_PATTERN, $merchant_scope)) {
            throw new InvalidArgumentException('Merchant scope must be 1-128 printable ASCII characters without spaces.');
        }
        if (strlen($id) > self::MAX_ID_BYTES || !preg_match(self::TOKEN_PATTERN, $id)) {
            throw new InvalidArgumentException('Source identifier must be 1-255 printable ASCII characters without spaces (never normalised).');
        }
        $this->provider = $provider;
        $this->merchant_scope = $merchant_scope;
        $this->environment = $environment;
        $this->kind = $kind;
        $this->id = $id;
    }

    /** @return array<string,string[]> */
    public static function kinds_by_provider(): array {
        return array(
            self::PROVIDER_PAYPAL => array(self::KIND_CAPTURE, self::KIND_AUTHORIZATION, self::KIND_PROVIDER_ORDER, self::KIND_REFUND),
            self::PROVIDER_VIVA => array(self::KIND_CAPTURE, self::KIND_AUTHORIZATION, self::KIND_PROVIDER_ORDER, self::KIND_REFUND),
            self::PROVIDER_MANUAL => array(self::KIND_RECEIPT),
            self::PROVIDER_WOOCOMMERCE => array(self::KIND_COMMERCE_ORDER),
            self::PROVIDER_PMPRO => array(self::KIND_COMMERCE_ORDER),
        );
    }

    public function provider(): string {
        return $this->provider;
    }

    public function merchant_scope(): string {
        return $this->merchant_scope;
    }

    public function environment(): string {
        return $this->environment;
    }

    public function kind(): string {
        return $this->kind;
    }

    /** Raw identifier: for HMAC derivation and provider lookups only. Never log or store it. */
    public function id(): string {
        return $this->id;
    }

    /** A settlement anchor identifies money that moved (capture or Club receipt), never an order. */
    public function is_settlement_anchor(): bool {
        return $this->kind === self::KIND_CAPTURE || $this->kind === self::KIND_RECEIPT;
    }

    public function same_as(EVC_Payment_Source_Ref $other): bool {
        return $this->provider === $other->provider
            && $this->merchant_scope === $other->merchant_scope
            && $this->environment === $other->environment
            && $this->kind === $other->kind
            && $this->id === $other->id;
    }

    public function __debugInfo() {
        return array(
            'provider' => $this->provider,
            'merchant_scope' => $this->merchant_scope,
            'environment' => $this->environment,
            'kind' => $this->kind,
            'id' => '[redacted]',
        );
    }
}
