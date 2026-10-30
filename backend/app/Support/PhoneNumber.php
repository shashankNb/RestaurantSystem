<?php

namespace App\Support;

/**
 * Phone numbers in E.164 (+61491570110), the form couriers need them in. Australian numbers
 * written the local way, 0491 570 110 or (03) 5550 1234, get the country code.
 */
final class PhoneNumber
{
    /** The number in E.164, or null when it can't be one. */
    public static function e164(?string $number, string $countryCode = '61'): ?string
    {
        $number = trim((string) $number);
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        $e164 = match (true) {
            $digits === '' => null,
            str_starts_with($number, '+') => "+{$digits}",
            str_starts_with($digits, '00') => '+'.substr($digits, 2),
            str_starts_with($digits, '0') => "+{$countryCode}".substr($digits, 1),
            str_starts_with($digits, $countryCode) => "+{$digits}",
            default => null,
        };

        // A country code and at least 8 digits, 15 at most.
        return $e164 !== null && preg_match('/^\+[1-9]\d{7,14}$/', $e164) === 1 ? $e164 : null;
    }
}
