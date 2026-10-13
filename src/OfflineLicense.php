<?php

declare(strict_types=1);

namespace BuTing\CliAppUpdater;

use DateTimeImmutable;
use RuntimeException;

final class OfflineLicense
{
    /** Verifies update entitlement only. publishedAt must come from trusted release metadata. */
    public static function verify(string $document, string $publicKey, string $productCode, string $clientKey, string $domain = '', ?string $publishedAt = null): array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('Offline license verification requires ext-sodium');
        }
        if (strlen($document) > 65536) {
            throw new RuntimeException('License document is too large');
        }
        $public = base64_decode($publicKey, true);
        if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('Invalid trusted public key');
        }
        $envelope = json_decode($document, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($envelope) || ($envelope['algorithm'] ?? '') !== 'Ed25519'
            || ($envelope['key_id'] ?? '') !== hash('sha256', $public)
            || !is_string($envelope['payload'] ?? null) || !is_string($envelope['signature'] ?? null)) {
            throw new RuntimeException('Invalid license envelope or untrusted key');
        }
        $payload = base64_decode($envelope['payload'], true);
        $signature = base64_decode($envelope['signature'], true);
        if ($payload === false || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $payload, $public)) {
            throw new RuntimeException('Invalid license signature');
        }
        $license = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($license) || ($license['schema'] ?? '') !== 'apps.update-license.v1'
            || ($license['key_id'] ?? '') !== $envelope['key_id'] || ($license['product_code'] ?? '') !== $productCode
            || !is_string($license['license_id'] ?? null) || !is_array($license['constraints'] ?? null)
            || !array_key_exists('updates_until', $license)) {
            throw new RuntimeException('License payload does not match the expected product or schema');
        }
        self::date($license['issued_at'] ?? '');
        $constraints = ['client_key' => $clientKey, 'domain' => self::domain($domain)];
        foreach ($license['constraints'] as $name => $expected) {
            if (!isset($constraints[$name]) || !is_string($expected) || $expected === '' || !hash_equals($expected, $constraints[$name])) {
                throw new RuntimeException('License binding is not satisfied: ' . $name);
            }
        }
        $until = $license['updates_until'] === null ? null : self::date($license['updates_until']);
        if ($publishedAt !== null && $until !== null && self::date($publishedAt) > $until) {
            throw new RuntimeException('Release was published after the update entitlement cutoff');
        }
        return $license;
    }

    public static function domain(string $domain): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if ($domain === '') {
            return '';
        }
        if (function_exists('idn_to_ascii')) {
            $domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
        }
        if (strlen($domain) > 253 || strpos($domain, '.') === false || filter_var($domain, FILTER_VALIDATE_IP)
            || !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new \InvalidArgumentException('domain must be a hostname without scheme, port or path (use punycode for IDNs)');
        }
        return $domain;
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new RuntimeException('Expected an ISO 8601 timestamp with explicit timezone');
        }
        return $date;
    }
}
