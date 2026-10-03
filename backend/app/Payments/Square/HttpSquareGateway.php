<?php

namespace App\Payments\Square;

use App\Enums\SquareEnvironment;
use App\Models\Order;
use App\Models\Restaurant;
use App\Payments\PaymentDeclined;
use App\Payments\PaymentsUnavailable;
use App\Payments\WalletSetup;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Square's REST API, through Laravel's HTTP client and pinned to one API version
 * (services.square.version). Each restaurant's calls use its own Square application's access
 * token, in the environment its application ID is for. A declined card becomes
 * PaymentDeclined; anything else that goes wrong, PaymentsUnavailable (SquareConnectionLost
 * when Square doesn't accept the access token).
 */
final class HttpSquareGateway implements SquareGateway
{
    /** Square's error codes that mean the card or wallet itself was refused. */
    private const CARD_PROBLEMS = ['CARD_TOKEN_EXPIRED', 'CARD_TOKEN_USED'];

    public function merchantName(Restaurant $restaurant): string
    {
        $merchant = $this->json($this->send(fn (): Response => $this->api($restaurant)->get('/v2/merchants/me')))['merchant'] ?? [];

        return is_array($merchant) ? (string) ($merchant['business_name'] ?? $merchant['id'] ?? '') : '';
    }

    public function locations(Restaurant $restaurant): array
    {
        $locations = $this->json($this->send(fn (): Response => $this->api($restaurant)->get('/v2/locations')))['locations'] ?? [];

        return array_values(array_map(
            fn (array $location): SquareLocation => new SquareLocation(
                id: (string) ($location['id'] ?? ''),
                name: (string) ($location['name'] ?? ''),
                currency: (string) ($location['currency'] ?? ''),
                active: ($location['status'] ?? null) === 'ACTIVE',
            ),
            array_filter(is_array($locations) ? $locations : [], 'is_array'),
        ));
    }

    public function registerApplePayDomain(Restaurant $restaurant, ?string $domain): WalletSetup
    {
        // Google Pay needs nothing registered; Apple Pay needs the website's domain.
        if ($domain === null) {
            return new WalletSetup(applePay: true, googlePay: true, domain: null, checkedAt: CarbonImmutable::now());
        }

        $response = $this->send(fn (): Response => $this->api($restaurant)->post('/v2/apple-pay/domains', ['domain_name' => $domain]));

        // Square says why it refused the domain (no verification file, say): the checklist shows it.
        if ($response->clientError() && $response->status() !== 401) {
            return new WalletSetup(true, true, $domain, domainProblem: self::errorDetail($response, withCode: false), checkedAt: CarbonImmutable::now());
        }

        $verified = ($this->json($response)['status'] ?? null) === 'VERIFIED';

        return new WalletSetup(
            applePay: true,
            googlePay: true,
            domain: $domain,
            domainReady: $verified,
            domainProblem: $verified ? null : 'Apple is still checking the domain. Check again in a few minutes.',
            checkedAt: CarbonImmutable::now(),
        );
    }

    public function charge(Order $order, string $sourceId, ?string $verificationToken, string $idempotencyKey): SquarePayment
    {
        $restaurant = $order->restaurant;

        $response = $this->send(fn (): Response => $this->retrying($this->api($restaurant))->post('/v2/payments', array_filter([
            'source_id' => $sourceId,
            'idempotency_key' => $idempotencyKey,
            'amount_money' => ['amount' => $order->total_cents, 'currency' => strtoupper($restaurant->currency)],
            'location_id' => $restaurant->square_location_id,
            'autocomplete' => true,
            // The order, in Square's dashboard and in its webhooks.
            'reference_id' => $order->public_id,
            'note' => "{$restaurant->name} order",
            'buyer_email_address' => $order->customer_email,
            'verification_token' => $verificationToken,
        ], fn (mixed $value): bool => $value !== null && $value !== '')));

        $decline = self::declineCode($response);

        if ($decline !== null) {
            throw PaymentDeclined::because($decline);
        }

        $payment = $this->json($response)['payment'] ?? null;

        if (! is_array($payment) || ! isset($payment['id'])) {
            throw PaymentsUnavailable::because(new RuntimeException('Square answered without the payment.'));
        }

        return new SquarePayment(
            id: (string) $payment['id'],
            status: (string) ($payment['status'] ?? ''),
            amountCents: (int) ($payment['amount_money']['amount'] ?? 0),
        );
    }

    public function refund(Order $order, string $idempotencyKey): string
    {
        if ($order->square_payment_id === null) {
            throw PaymentsUnavailable::because(new RuntimeException("Order {$order->public_id} has no Square payment to refund."));
        }

        $refund = $this->json($this->send(fn (): Response => $this->retrying($this->api($order->restaurant))->post('/v2/refunds', [
            'idempotency_key' => $idempotencyKey,
            'payment_id' => $order->square_payment_id,
            'amount_money' => ['amount' => $order->total_cents, 'currency' => strtoupper($order->restaurant->currency)],
            'reason' => 'Refunded by the restaurant',
        ])))['refund'] ?? null;

        if (! is_array($refund) || ! isset($refund['id'])) {
            throw PaymentsUnavailable::because(new RuntimeException('Square answered without the refund.'));
        }

        return (string) $refund['id'];
    }

    /** Calls for the restaurant, with its access token, in its application's environment. */
    private function api(Restaurant $restaurant): PendingRequest
    {
        $environment = $restaurant->squareEnvironment();
        $token = $restaurant->square_access_token;

        if ($environment === null || ! is_string($token) || $token === '') {
            throw PaymentsUnavailable::notConfigured();
        }

        return $this->client($environment)->withToken($token);
    }

    private function client(SquareEnvironment $environment): PendingRequest
    {
        return Http::baseUrl($environment->host())
            ->withHeaders(['Square-Version' => (string) config('services.square.version')])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);
    }

    /**
     * Tries again when Square can't be reached or has a fault of its own: only for requests
     * with an idempotency key, which Square answers once however often they're sent.
     */
    private function retrying(PendingRequest $request): PendingRequest
    {
        return $request->retry(3, 500, fn (Throwable $exception): bool => $exception instanceof ConnectionException
            || ($exception instanceof RequestException && $exception->response->serverError()), throw: false);
    }

    /**
     * @param  Closure(): Response  $request
     */
    private function send(Closure $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $exception) {
            report($exception);

            throw PaymentsUnavailable::because($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        if ($response->successful()) {
            $json = $response->json();

            return is_array($json) ? $json : [];
        }

        $exception = new RuntimeException("Square answered {$response->status()}: ".self::errorDetail($response));
        report($exception);

        throw $response->status() === 401 ? SquareConnectionLost::from($exception) : PaymentsUnavailable::because($exception);
    }

    /** The first of Square's errors that's about the card or wallet, if any. */
    private static function declineCode(Response $response): ?string
    {
        if ($response->successful()) {
            return null;
        }

        foreach (self::errors($response) as $error) {
            $code = (string) ($error['code'] ?? '');

            if (($error['category'] ?? null) === 'PAYMENT_METHOD_ERROR' || in_array($code, self::CARD_PROBLEMS, true)) {
                return $code;
            }
        }

        return null;
    }

    private static function errorDetail(Response $response, bool $withCode = true): string
    {
        $error = self::errors($response)[0] ?? null;

        if ($error === null) {
            $message = $response->json('message');

            return is_string($message) ? $message : "HTTP {$response->status()}";
        }

        $detail = (string) ($error['detail'] ?? '');

        return $withCode ? trim(($error['code'] ?? '').' '.$detail) : $detail;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function errors(Response $response): array
    {
        $errors = $response->json('errors');

        return is_array($errors) ? array_values(array_filter($errors, 'is_array')) : [];
    }
}
