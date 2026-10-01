<?php

namespace App\Payments;

use App\Models\Order;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent as StripePaymentIntent;
use Stripe\StripeClient;

/**
 * Stripe PaymentIntents with automatic payment methods, which gives cards, Apple Pay and
 * Google Pay through PaymentSheet (iOS, Android) and the Payment Element (web).
 */
final class StripePaymentGateway implements PaymentGateway
{
    private ?StripeClient $client = null;

    public function __construct(private readonly ?string $secretKey) {}

    public function createPaymentIntent(Order $order, string $idempotencyKey): PaymentIntent
    {
        $restaurant = $order->restaurant;

        return $this->call(fn (StripeClient $stripe) => self::toPaymentIntent($stripe->paymentIntents->create([
            'amount' => $order->total_cents,
            'currency' => strtolower($restaurant->currency),
            'automatic_payment_methods' => ['enabled' => true],
            'description' => "{$restaurant->name} order",
            'metadata' => [
                'order_public_id' => $order->public_id,
                'restaurant_id' => (string) $restaurant->id,
            ],
        ], ['idempotency_key' => $idempotencyKey])));
    }

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return $this->call(fn (StripeClient $stripe) => self::toPaymentIntent($stripe->paymentIntents->retrieve($paymentIntentId)));
    }

    public function cancelPaymentIntent(string $paymentIntentId): void
    {
        try {
            $this->call(fn (StripeClient $stripe) => $stripe->paymentIntents->cancel($paymentIntentId));
        } catch (PaymentsUnavailable $exception) {
            // Already paid or already cancelled: nothing to stop. The webhook handles a late payment.
            if (! $exception->getPrevious() instanceof InvalidRequestException) {
                throw $exception;
            }
        }
    }

    public function refund(Order $order, string $idempotencyKey): string
    {
        return $this->call(fn (StripeClient $stripe) => $stripe->refunds->create([
            'payment_intent' => (string) $order->stripe_payment_intent_id,
            'metadata' => ['order_public_id' => $order->public_id],
        ], ['idempotency_key' => $idempotencyKey])->id);
    }

    /**
     * @template T
     *
     * @param  callable(StripeClient): T  $request
     * @return T
     */
    private function call(callable $request): mixed
    {
        if ($this->secretKey === null || $this->secretKey === '') {
            throw PaymentsUnavailable::notConfigured();
        }

        $this->client ??= new StripeClient($this->secretKey);

        try {
            return $request($this->client);
        } catch (ApiErrorException $exception) {
            report($exception);

            throw PaymentsUnavailable::because($exception);
        }
    }

    private static function toPaymentIntent(StripePaymentIntent $intent): PaymentIntent
    {
        return new PaymentIntent($intent->id, (string) $intent->client_secret, $intent->status, $intent->amount);
    }
}
