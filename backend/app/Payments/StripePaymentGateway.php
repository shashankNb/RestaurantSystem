<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent as StripePaymentIntent;
use Stripe\PaymentMethodConfiguration;
use Stripe\PaymentMethodDomain;
use Stripe\StripeClient;

/**
 * Stripe PaymentIntents with automatic payment methods, which gives cards, Apple Pay and
 * Google Pay through PaymentSheet (iOS, Android) and the Payment Element (web). Each order
 * is charged, and refunded, in its restaurant's own Stripe account, with the secret key
 * the owner entered in the back office.
 */
final class StripePaymentGateway implements PaymentGateway
{
    /** @var array<string, StripeClient> by secret key */
    private array $clients = [];

    public function createPaymentIntent(Order $order, string $idempotencyKey): PaymentIntent
    {
        $restaurant = $order->restaurant;

        return $this->call($restaurant, fn (StripeClient $stripe) => self::toPaymentIntent($stripe->paymentIntents->create([
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

    public function retrievePaymentIntent(Order $order): PaymentIntent
    {
        return $this->call($order->restaurant, fn (StripeClient $stripe) => self::toPaymentIntent(
            $stripe->paymentIntents->retrieve((string) $order->stripe_payment_intent_id),
        ));
    }

    public function cancelPaymentIntent(Order $order): void
    {
        try {
            $this->call($order->restaurant, fn (StripeClient $stripe) => $stripe->paymentIntents->cancel((string) $order->stripe_payment_intent_id));
        } catch (PaymentsUnavailable $exception) {
            // Already paid or already cancelled: nothing to stop. The webhook handles a late payment.
            if (! $exception->getPrevious() instanceof InvalidRequestException) {
                throw $exception;
            }
        }
    }

    public function refund(Order $order, string $idempotencyKey): string
    {
        return $this->call($order->restaurant, fn (StripeClient $stripe) => $stripe->refunds->create([
            'payment_intent' => (string) $order->stripe_payment_intent_id,
            'metadata' => ['order_public_id' => $order->public_id],
        ], ['idempotency_key' => $idempotencyKey])->id);
    }

    public function accountName(Restaurant $restaurant): string
    {
        return $this->call($restaurant, function (StripeClient $stripe): string {
            $account = $stripe->accounts->retrieve();

            return $account->settings->dashboard->display_name
                ?? $account->business_profile->name
                ?? $account->email
                ?? $account->id;
        });
    }

    public function prepareWallets(Restaurant $restaurant, ?string $domain): WalletSetup
    {
        return $this->call($restaurant, function (StripeClient $stripe) use ($domain): WalletSetup {
            $settings = self::defaultConfiguration($stripe);
            $registered = $domain === null ? null : self::registeredDomain($stripe, $domain);
            $problems = $registered === null ? [] : array_filter([
                $registered->apple_pay->status === 'active' ? null : ($registered->apple_pay->status_details->error_message ?? 'Apple Pay isn’t active on this domain yet.'),
                $registered->google_pay->status === 'active' ? null : ($registered->google_pay->status_details->error_message ?? 'Google Pay isn’t active on this domain yet.'),
            ]);

            return new WalletSetup(
                applePay: self::isOn($settings?->apple_pay),
                googlePay: self::isOn($settings?->google_pay),
                domain: $domain,
                domainReady: $registered !== null && $registered->enabled && $problems === [],
                domainProblem: $problems === [] ? null : implode(' ', array_unique($problems)),
                checkedAt: CarbonImmutable::now(),
            );
        });
    }

    public function turnOnWallets(Restaurant $restaurant): void
    {
        $this->call($restaurant, function (StripeClient $stripe): void {
            $settings = self::defaultConfiguration($stripe) ?? throw PaymentsUnavailable::because(
                new RuntimeException('The Stripe account has no default payment method settings.'),
            );

            $stripe->paymentMethodConfigurations->update($settings->id, [
                'apple_pay' => ['display_preference' => ['preference' => 'on']],
                'google_pay' => ['display_preference' => ['preference' => 'on']],
            ]);
        });
    }

    /** The account's own payment method settings (Settings → Payment methods). */
    private static function defaultConfiguration(StripeClient $stripe): ?PaymentMethodConfiguration
    {
        foreach ($stripe->paymentMethodConfigurations->all(['limit' => 100])->data as $configuration) {
            if ($configuration->is_default && $configuration->active) {
                return $configuration;
            }
        }

        return null;
    }

    /** The website's domain, registered for wallets: found, switched back on, or added. */
    private static function registeredDomain(StripeClient $stripe, string $domain): PaymentMethodDomain
    {
        $existing = $stripe->paymentMethodDomains->all(['domain_name' => $domain, 'limit' => 1])->data[0] ?? null;

        if ($existing === null) {
            return $stripe->paymentMethodDomains->create(['domain_name' => $domain]);
        }

        if (! $existing->enabled) {
            return $stripe->paymentMethodDomains->update($existing->id, ['enabled' => true]);
        }

        return $existing;
    }

    /**
     * A payment method in the account's settings: switched on, and Stripe offers it.
     *
     * @param  null|object{available: bool, display_preference: object{value: string}}  $method
     */
    private static function isOn(?object $method): bool
    {
        return $method !== null && $method->available && $method->display_preference->value === 'on';
    }

    /**
     * @template T
     *
     * @param  callable(StripeClient): T  $request
     * @return T
     */
    private function call(Restaurant $restaurant, callable $request): mixed
    {
        $secretKey = $restaurant->stripe_secret_key;

        if (! is_string($secretKey) || $secretKey === '') {
            throw PaymentsUnavailable::notConfigured();
        }

        $this->clients[$secretKey] ??= new StripeClient($secretKey);

        try {
            return $request($this->clients[$secretKey]);
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
