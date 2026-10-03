<?php

namespace Tests\Support;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for Stripe's API under the Stripe SDK, to test StripePaymentGateway itself: each
 * request ("GET /v1/payment_method_domains") gets its canned JSON, and is recorded. A request
 * with no canned answer gets Stripe's 404. Call reset() after the test.
 */
final class FakeStripeHttpClient implements ClientInterface
{
    /** @var list<array{request: string, params: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param  array<string, array<string, mixed>>  $responses  by "METHOD /path"
     */
    private function __construct(private array $responses) {}

    /**
     * @param  array<string, array<string, mixed>>  $responses  by "METHOD /path"
     */
    public static function install(array $responses): self
    {
        $fake = new self($responses);
        ApiRequestor::setHttpClient($fake);

        return $fake;
    }

    public static function reset(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    /** @return list<string> */
    public function requested(): array
    {
        return array_column($this->requests, 'request');
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $request = strtoupper($method).' '.parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['request' => $request, 'params' => $params];

        if (! isset($this->responses[$request])) {
            return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => "No canned answer for {$request}"]]), 404, []];
        }

        return [json_encode($this->responses[$request]), 200, []];
    }
}
