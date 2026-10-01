<?php

namespace Tests\Support;

use App\Models\Order;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Stripe webhook events for tests, signed the way Stripe signs them: an HMAC-SHA256 of
 * "{timestamp}.{body}" with the endpoint's signing secret, in the Stripe-Signature header.
 */
final class StripeEvents
{
    public const SECRET = 'whsec_test_secret';

    /**
     * @return array<string, mixed>
     */
    public static function succeeded(Order $order, ?string $eventId = null, ?int $amount = null): array
    {
        return self::event('payment_intent.succeeded', $order, $eventId, [
            'amount' => $amount ?? $order->total_cents,
            'amount_received' => $amount ?? $order->total_cents,
            'status' => 'succeeded',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function failed(Order $order, ?string $eventId = null): array
    {
        return self::event('payment_intent.payment_failed', $order, $eventId, [
            'amount' => $order->total_cents,
            'amount_received' => 0,
            'status' => 'requires_payment_method',
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function send(array $event, ?int $signedAt = null, string $secret = self::SECRET): TestResponse
    {
        $body = (string) json_encode($event);
        $signedAt ??= time();
        $signature = hash_hmac('sha256', "{$signedAt}.{$body}", $secret);

        return test()->call('POST', '/api/v1/stripe/webhook', server: [
            'HTTP_STRIPE_SIGNATURE' => "t={$signedAt},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: $body);
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private static function event(string $type, Order $order, ?string $eventId, array $intent): array
    {
        return [
            'id' => $eventId ?? 'evt_test_'.Str::random(16),
            'object' => 'event',
            'type' => $type,
            'created' => time(),
            'livemode' => false,
            'data' => [
                'object' => [
                    'id' => $order->stripe_payment_intent_id,
                    'object' => 'payment_intent',
                    'currency' => 'aud',
                    'metadata' => ['order_public_id' => $order->public_id],
                    ...$intent,
                ],
            ],
        ];
    }
}
