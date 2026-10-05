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
        } catch (SignatureVerificationException $exception) {
            throw new InvalidWebhook(self::reason($exception->getMessage()), previous: $exception);
        } catch (UnexpectedValueException $exception) {
            throw new InvalidWebhook('The webhook body isn’t a Stripe event.', previous: $exception);
        }
    }

    /**
     * Why the signature was refused, from Stripe's own reason, in words that say what to fix.
     * Stripe shows this answer next to the failed delivery, and it's logged.
     */
    private static function reason(string $stripeReason): string
    {
        return match (true) {
            str_contains($stripeReason, 'No signatures found matching the expected signature') => 'The webhook signature is invalid: it doesn’t match this restaurant’s signing secret. Copy this endpoint’s signing secret from Stripe into Restaurant settings → Payments → Stripe.',
            str_contains($stripeReason, 'Timestamp outside the tolerance zone') => 'The webhook signature is invalid: it was made more than 5 minutes from this server’s time. Check the server’s clock.',
            default => 'The webhook signature is invalid: the Stripe-Signature header isn’t in Stripe’s format.',
        };
    }
}
