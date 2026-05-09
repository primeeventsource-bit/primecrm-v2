<?php

declare(strict_types=1);

namespace App\Core\Shared\Services;

/**
 * Centralized phone number handling.
 *
 * All persisted phone numbers are stored in E.164 format (e.g. +14155552671).
 * The phone_hash column stores SHA-256 of the E.164 string for fast equality
 * lookups against DNC lists without exposing the number in indexes.
 *
 * For US/CA-focused operations this implementation is sufficient. For
 * international expansion, swap in giggsey/libphonenumber-for-php and
 * preserve this interface — callers shouldn't change.
 */
final class PhoneNormalizer
{
    /**
     * Normalize a free-form phone string to E.164.
     * Returns null if the input cannot be sensibly parsed.
     */
    public function normalize(string $phone, string $defaultCountry = 'US'): ?string
    {
        // Strip everything except digits and leading +
        $cleaned = preg_replace('/[^\d+]/', '', $phone) ?? '';

        if ($cleaned === '') {
            return null;
        }

        // Already E.164
        if (str_starts_with($cleaned, '+')) {
            $digits = substr($cleaned, 1);

            return $this->isValidLength($digits) ? '+'.$digits : null;
        }

        // US/CA: 10 digits → prepend +1; 11 digits starting with 1 → prepend +
        if ($defaultCountry === 'US' || $defaultCountry === 'CA') {
            if (strlen($cleaned) === 10) {
                return '+1'.$cleaned;
            }
            if (strlen($cleaned) === 11 && str_starts_with($cleaned, '1')) {
                return '+'.$cleaned;
            }
        }

        // Fallback: assume already includes country code
        return $this->isValidLength($cleaned) ? '+'.$cleaned : null;
    }

    public function hash(string $e164Phone): string
    {
        return hash('sha256', $e164Phone);
    }

    /**
     * Convenience: normalize and hash in one call.
     * Returns [normalized, hash] or null if normalization fails.
     */
    public function normalizeAndHash(string $phone, string $defaultCountry = 'US'): ?array
    {
        $normalized = $this->normalize($phone, $defaultCountry);

        if ($normalized === null) {
            return null;
        }

        return [$normalized, $this->hash($normalized)];
    }

    private function isValidLength(string $digits): bool
    {
        $len = strlen($digits);

        // E.164 allows 7 to 15 digits total (incl. country code)
        return $len >= 7 && $len <= 15;
    }
}
