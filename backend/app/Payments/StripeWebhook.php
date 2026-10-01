<?php

namespace App\Payments;

use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Checks that a webhook really came from Stripe: the Stripe-Signature header must be an
 * HMAC of the raw body with the endpoint's signing secret, made within the last five
 * minutes (so an old request can't be replayed).
 */
final class StripeWebhook
{
    public const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly ?string $signingSecret) {}

    /**
     * @throws InvalidWebhook
     */
    public function verify(string $payload, ?string $signatureHeader): Event
    {
        if ($this->signingSecret === null || $this->signingSecret === '') {
            throw new InvalidWebhook('The webhook signing secret isn’t configured.');
        }

        if ($signatureHeader === null || $signatureHeader === '') {
            throw new InvalidWebhook('Missing Stripe-Signature header.');
        }

        try {
            return Webhook::constructEvent($payload, $signatureHeader, $this->signingSecret, self::TOLERANCE_SECONDS);
        } catch (SignatureVerificationException|UnexpectedValueException $exception) {
            throw new InvalidWebhook('The webhook signature is invalid.', previous: $exception);
        }
    }
}
