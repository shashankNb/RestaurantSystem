<?php

namespace Tests\Support;

use App\Models\Order;
use App\Payments\PaymentGateway;
use App\Payments\PaymentIntent;
use App\Payments\PaymentsUnavailable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stands in for Stripe in tests. Like Stripe, repeating an idempotency key returns the
 * first result instead of creating another PaymentIntent or refund.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, PaymentIntent> by PaymentIntent ID */
    public array $intents = [];

    /** @var list<array{order: string, key: string, id: string, amount: int}> */
    public array $refunds = [];

    /** @var list<string> */
    public array $cancelled = [];

    public int $createCalls = 0;

    /** Makes the next call fail, as if Stripe were down. */
    public bool $failNext = false;

    /** @var array<string, string> idempotency key => PaymentIntent or refund ID */
    private array $keys = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(PaymentGateway::class, $fake);

        return $fake;
    }

    public function createPaymentIntent(Order $order, string $idempotencyKey): PaymentIntent
    {
        $this->createCalls++;
        $this->failIfAsked();

        if (isset($this->keys[$idempotencyKey])) {
            return $this->intents[$this->keys[$idempotencyKey]];
        }

        $id = 'pi_fake_'.Str::random(16);
        $this->keys[$idempotencyKey] = $id;

        return $this->intents[$id] = new PaymentIntent($id, "{$id}_secret_".Str::random(12), 'requires_payment_method', $order->total_cents);
    }

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        $this->failIfAsked();

        return $this->intents[$paymentIntentId] ?? throw PaymentsUnavailable::because(new RuntimeException("No such PaymentIntent: {$paymentIntentId}"));
    }

    public function cancelPaymentIntent(string $paymentIntentId): void
    {
        $this->failIfAsked();
        $this->cancelled[] = $paymentIntentId;
    }

    public function refund(Order $order, string $idempotencyKey): string
    {
        $this->failIfAsked();

        if (isset($this->keys[$idempotencyKey])) {
            return $this->keys[$idempotencyKey];
        }

        $id = 're_fake_'.Str::random(16);
        $this->keys[$idempotencyKey] = $id;
        $this->refunds[] = ['order' => $order->public_id, 'key' => $idempotencyKey, 'id' => $id, 'amount' => $order->total_cents];

        return $id;
    }

    private function failIfAsked(): void
    {
        if ($this->failNext) {
            $this->failNext = false;

            throw PaymentsUnavailable::because(new RuntimeException('Stripe is down'));
        }
    }
}
