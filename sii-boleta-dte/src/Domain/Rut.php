<?php
declare(strict_types=1);

namespace Sii\BoletaDte\Domain;

/**
 * Utility helpers to work with Chilean RUT numbers.
 */
final class Rut {
    private const GENERIC = '666666666';

    /**
     * Removes non RUT characters and uppercases the verifier digit.
     */
    public static function clean(string $value): string {
        $clean = preg_replace('/[^0-9kK]/', '', $value);
        return strtoupper($clean ?? '');
    }

    /**
     * Returns true when the provided RUT has a valid verification digit.
     */
    public static function isValid(string $value): bool {
        $clean = self::clean($value);
        if (strlen($clean) < 2) {
            return false;
        }

        $body = substr($clean, 0, -1);
        $dv   = substr($clean, -1);

        if (!ctype_digit($body)) {
            return false;
        }

        return self::computeVerificationDigit($body) === $dv;
    }

    /**
     * Formats the RUT using thousands separators and an hyphen.
     */
    public static function format(string $value): string {
        $clean = self::clean($value);
        if ('' === $clean) {
            return '';
        }

        if (strlen($clean) === 1) {
            return $clean;
        }

        $body = substr($clean, 0, -1);
        $dv   = substr($clean, -1);

        $formatted = '';
        while (strlen($body) > 3) {
            $formatted = '.' . substr($body, -3) . $formatted;
            $body      = substr($body, 0, -3);
        }

        return $body . $formatted . '-' . $dv;
    }

    /**
     * Detects the generic SII RUT used for anonymous buyers.
     */
    public static function isGeneric(string $value): bool {
        return self::clean($value) === self::GENERIC;
    }

    private static function computeVerificationDigit(string $body): string {
        $sum        = 0;
        $multiplier = 2;

        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += ((int) $body[$i]) * $multiplier;
            $multiplier = ($multiplier === 7) ? 2 : $multiplier + 1;
        }

        $remainder = 11 - ($sum % 11);
        return match ($remainder) {
            11 => '0',
            10 => 'K',
            default => (string) $remainder,
        };
    }
}
