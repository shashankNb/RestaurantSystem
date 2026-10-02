<?php

namespace Tests\Support;

use App\Models\Order;
use App\Models\Restaurant;
use App\Payments\PaymentGateway;
use App\Payments\PaymentIntent;
use App\Payments\PaymentsUnavailable;
use App\Payments\WalletSetup;
use Carbon\CarbonImmutable;
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

    /** @var list<string> each call's restaurant slug, as Stripe would get each restaurant's key */
    public array $restaurants = [];

    public int $createCalls = 0;

    /** Makes the next call fail, as if Stripe were down. */
    public bool $failNext = false;

    /** The Stripe account's payment method settings, as in a new account: Google Pay off. */
    public bool $applePayOn = true;

    public bool $googlePayOn = false;

    /** @var list<string> domains registered for wallets */
    public array $domains = [];

    public int $turnOnCalls = 0;

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
        $this->failIfAsked($order);

        if (isset($this->keys[$idempotencyKey])) {
            return $this->intents[$this->keys[$idempotencyKey]];
        }

        $id = 'pi_fake_'.Str::random(16);
        $this->keys[$idempotencyKey] = $id;

        return $this->intents[$id] = new PaymentIntent($id, "{$id}_secret_".Str::random(12), 'requires_payment_method', $order->total_cents);
    }

    public function retrievePaymentIntent(Order $order): PaymentIntent
    {
        $this->failIfAsked($order);
        $id = (string) $order->stripe_payment_intent_id;

        return $this->intents[$id] ?? throw PaymentsUnavailable::because(new RuntimeException("No such PaymentIntent: {$id}"));
    }

    public function cancelPaymentIntent(Order $order): void
    {
        $this->failIfAsked($order);
        $this->cancelled[] = (string) $order->stripe_payment_intent_id;
    }

    public function refund(Order $order, string $idempotencyKey): string
    {
        $this->failIfAsked($order);

        if (isset($this->keys[$idempotencyKey])) {
            return $this->keys[$idempotencyKey];
        }

        $id = 're_fake_'.Str::random(16);
        $this->keys[$idempotencyKey] = $id;
        $this->refunds[] = ['order' => $order->public_id, 'key' => $idempotencyKey, 'id' => $id, 'amount' => $order->total_cents];

        return $id;
    }

    public function accountName(Restaurant $restaurant): string
    {
        if (blank($restaurant->stripe_secret_key)) {
            throw PaymentsUnavailable::notConfigured();
        }

        if ($this->failNext) {
            $this->failNext = false;

            throw PaymentsUnavailable::because(new RuntimeException('Invalid API Key provided'));
        }

        return "{$restaurant->name} (Stripe)";
    }

    public function prepareWallets(Restaurant $restaurant, ?string $domain): WalletSetup
    {
        $this->failIfAskedFor($restaurant);

        if ($domain !== null && ! in_array($domain, $this->domains, true)) {
            $this->domains[] = $domain;
        }

        return new WalletSetup($this->applePayOn, $this->googlePayOn, $domain, domainReady: $domain !== null, checkedAt: CarbonImmutable::now());
    }

    public function turnOnWallets(Restaurant $restaurant): void
    {
        $this->failIfAskedFor($restaurant);
        $this->turnOnCalls++;
        $this->applePayOn = true;
        $this->googlePayOn = true;
    }

    private function failIfAskedFor(Restaurant $restaurant): void
    {
        if (blank($restaurant->stripe_secret_key)) {
            throw PaymentsUnavailable::notConfigured();
        }

        if ($this->failNext) {
            $this->failNext = false;

            throw PaymentsUnavailable::because(new RuntimeException('Stripe is down'));
        }
    }

    /** Like the real gateway: no secret key, no payments; and the call can be made to fail. */
    private function failIfAsked(Order $order): void
    {
        $this->restaurants[] = $order->restaurant->slug;

        if (blank($order->restaurant->stripe_secret_key)) {
            throw PaymentsUnavailable::notConfigured();
        }

        if ($this->failNext) {
            $this->failNext = false;

            throw PaymentsUnavailable::because(new RuntimeException('Stripe is down'));
        }
    }
}
