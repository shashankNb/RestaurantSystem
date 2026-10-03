<?php

namespace App\Payments;

use RuntimeException;

/**
 * The customer's card or wallet was declined. The message tells them what to do, and is
 * safe to show; the API answers 402.
 */
final class PaymentDeclined extends RuntimeException
{
    public const GENERIC = 'Your card was declined. Try another card, or contact your bank.';

    /** Square's decline codes, as customers should hear them. */
    private const MESSAGES = [
        'INSUFFICIENT_FUNDS' => 'Your card was declined: there isn’t enough money in the account. Try another card.',
        'CVV_FAILURE' => 'The card’s security code didn’t match. Check it and try again.',
        'VERIFY_CVV_FAILURE' => 'The card’s security code didn’t match. Check it and try again.',
        'ADDRESS_VERIFICATION_FAILURE' => 'The postcode didn’t match the card. Check it and try again.',
        'VERIFY_AVS_FAILURE' => 'The postcode didn’t match the card. Check it and try again.',
        'INVALID_POSTAL_CODE' => 'The postcode didn’t match the card. Check it and try again.',
        'INVALID_EXPIRATION' => 'The card’s expiry date is wrong, or the card has expired. Check it, or use another card.',
        'EXPIRATION_FAILURE' => 'The card’s expiry date is wrong, or the card has expired. Check it, or use another card.',
        'CARD_EXPIRED' => 'The card has expired. Use another card.',
        'CARD_NOT_SUPPORTED' => 'This card can’t be used here. Use another card.',
        'CARD_TOKEN_EXPIRED' => 'Your card details timed out. Enter them again.',
        'CARD_TOKEN_USED' => 'Your card details were already used. Enter them again.',
        'CARD_DECLINED_VERIFICATION_REQUIRED' => 'Your bank needs to check this payment. Enter your card again to verify it.',
    ];

    public static function because(string $code): self
    {
        return new self(self::MESSAGES[$code] ?? self::GENERIC);
    }
}
