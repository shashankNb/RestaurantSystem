<?php

namespace App\Payments\Square;

use App\Payments\InvalidWebhook;

/**
 * Checks that a webhook really came from Square: its x-square-hmacsha256-signature header
 * must be an HMAC-SHA256 of the subscription's notification URL followed by the raw body,
 * keyed with the subscription's signature key (base64). Square signs the URL exactly as it's
 * set in the subscription, so it's built from APP_URL, not from the request.
 */
final class SquareWebhook
{
    public function __construct(
        private readonly ?string $signatureKey,
        private readonly string $notificationUrl,
    ) {}

    /**
     * @return array<string, mixed> the event
     *
     * @throws InvalidWebhook
     */
    public function verify(string $payload, ?string $signature): array
    {
        if ($this->signatureKey === null || $this->signatureKey === '') {
            throw new InvalidWebhook('The Square webhook signature key isn’t configured.');
        }

        if ($signature === null || $signature === '') {
            throw new InvalidWebhook('Missing x-square-hmacsha256-signature header.');
        }

        $expected = base64_encode(hash_hmac('sha256', $this->notificationUrl.$payload, $this->signatureKey, true));

        if (! hash_equals($expected, $signature)) {
            throw new InvalidWebhook('The webhook signature is invalid.');
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! isset($event['event_id'], $event['type']) || ! is_string($event['event_id']) || ! is_string($event['type'])) {
            throw new InvalidWebhook('The webhook body isn’t a Square event.');
        }

        return $event;
    }
}
