<?php

namespace App\Support;

/**
 * Formats integer cents for customer-facing messages: 2500 → "$25.00".
 */
final class Money
{
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '−' : '';

        return $sign.'$'.number_format(abs($cents) / 100, 2);
    }
}
