<?php

namespace Tests\Support;

use App\Models\Order;
use App\Models\Restaurant;
use App\Payments\PaymentDeclined;
use App\Payments\PaymentsUnavailable;
use App\Payments\Square\SquareApp;
use App\Payments\Square\SquareConnection;
use App\Payments\Square\SquareConnectionLost;
use App\Payments\Square\SquareGateway;
use App\Payments\Square\SquareLocation;
use App\Payments\Square\SquarePayment;
use App\Payments\WalletSetup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stands in for Square in tests. Like Square, repeating an idempotency key returns the first
 * payment or refund instead of making another.
 */
final class FakeSquareGateway implements SquareGateway
{
    /** @var array<string, SquarePayment> by idempotency key */
    public array $charges = [];

    /** @var list<array{order: string, source: string, verification: ?string, key: string}> */
    public array $chargeRequests = [];

    /** @var array<string, string> idempotency key => refund ID */
    public array $refunds = [];

    /** Makes the next call fail, as if Square were down. */
    public bool $failNext = false;

    /** A Square decline code (CARD_DECLINED, say) for the next charge. */
    public ?string $declineNext = null;

    /** The status new payments get. */
    public string $paymentStatus = 'COMPLETED';

    public string $merchantId = 'MSQUARE123';

    public string $merchantName = 'Momo House on Square';

    /** @var list<SquareLocation> */
    public array $locations;

    /** @var list<string> codes swapped for tokens */
    public array $codes = [];

    /** @var list<string> domains registered for Apple Pay */
    public array $domains = [];

    public bool $domainsVerified = true;

    public int $refreshes = 0;

    /** @var list<string> merchants whose refresh token Square refuses, as after a revocation */
    public array $refuseRefreshFor = [];

    /** @var list<string> merchants whose access was revoked */
    public array $revoked = [];

    public function __construct()
    {
        $this->locations = [new SquareLocation('LMAIN', 'Main Street', 'AUD', true)];
    }

    public static function install(): self
    {
        $fake = new self;
        app()->instance(SquareGateway::class, $fake);

        return $fake;
    }

    public function exchangeCode(SquareApp $app, string $code): SquareConnection
    {
        $this->failIfAsked();
        $this->codes[] = $code;

        return new SquareConnection($this->merchantId, 'EAAA-'.Str::random(20), 'EQAA-'.Str::random(20), CarbonImmutable::now()->addDays(30));
    }

    public function refresh(Restaurant $restaurant): SquareConnection
    {
        $this->failIfAskedFor($restaurant);

        if (in_array($restaurant->square_merchant_id, $this->refuseRefreshFor, true)) {
            throw SquareConnectionLost::from(new RuntimeException('The refresh token was revoked.'));
        }

        $this->refreshes++;

        return new SquareConnection((string) $restaurant->square_merchant_id, 'EAAA-renewed-'.$this->refreshes, (string) $restaurant->square_refresh_token, CarbonImmutable::now()->addDays(30));
    }

    public function revoke(Restaurant $restaurant): void
    {
        $this->failIfAskedFor($restaurant);
        $this->revoked[] = (string) $restaurant->square_merchant_id;
    }

    public function merchantName(Restaurant $restaurant): string
    {
        $this->failIfAskedFor($restaurant);

        return $this->merchantName;
    }

    public function locations(Restaurant $restaurant): array
    {
        $this->failIfAskedFor($restaurant);

        return $this->locations;
    }

    public function registerApplePayDomain(Restaurant $restaurant, ?string $domain): WalletSetup
    {
        $this->failIfAskedFor($restaurant);

        if ($domain !== null) {
            $this->domains[] = $domain;
        }

        return new WalletSetup(
            applePay: true,
            googlePay: true,
            domain: $domain,
            domainReady: $domain !== null && $this->domainsVerified,
            domainProblem: $domain !== null && ! $this->domainsVerified ? 'Apple is still checking the domain. Check again in a few minutes.' : null,
            checkedAt: CarbonImmutable::now(),
        );
    }

    public function charge(Order $order, string $sourceId, ?string $verificationToken, string $idempotencyKey): SquarePayment
    {
        $this->failIfAskedFor($order->restaurant);
        $this->chargeRequests[] = ['order' => $order->public_id, 'source' => $sourceId, 'verification' => $verificationToken, 'key' => $idempotencyKey];

        if (isset($this->charges[$idempotencyKey])) {
            return $this->charges[$idempotencyKey];
        }

        if ($this->declineNext !== null) {
            $code = $this->declineNext;
            $this->declineNext = null;

            throw PaymentDeclined::because($code);
        }

        return $this->charges[$idempotencyKey] = new SquarePayment('sqpay_'.Str::random(20), $this->paymentStatus, $order->total_cents);
    }

    public function refund(Order $order, string $idempotencyKey): string
    {
        $this->failIfAskedFor($order->restaurant);

        return $this->refunds[$idempotencyKey] ??= 'sqref_'.Str::random(20);
    }

    private function failIfAskedFor(Restaurant $restaurant): void
    {
        if (blank($restaurant->square_access_token)) {
            throw PaymentsUnavailable::notConfigured();
        }

        $this->failIfAsked();
    }

    private function failIfAsked(): void
    {
        if ($this->failNext) {
            $this->failNext = false;

            throw PaymentsUnavailable::because(new RuntimeException('Square is down'));
        }
    }
}
