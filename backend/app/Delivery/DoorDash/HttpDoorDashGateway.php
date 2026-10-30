<?php

namespace App\Delivery\DoorDash;

use App\Delivery\CourierUnavailable;
use App\Models\Restaurant;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * DoorDash's Drive API (v2), through Laravel's HTTP client. Each restaurant's calls are signed
 * with a fresh JWT from its own access key. DoorDash saying no (400, 404, 409, 422) becomes
 * DoorDashRefused; a key it doesn't accept (401, 403), DoorDashKeyRejected; anything else that
 * goes wrong, CourierUnavailable.
 */
final class HttpDoorDashGateway implements DoorDashGateway
{
    public function quote(Restaurant $restaurant, array $delivery): DoorDashDelivery
    {
        return $this->parse($this->send(fn (): Response => $this->api($restaurant)->post('/drive/v2/quotes', $delivery)));
    }

    public function acceptQuote(Restaurant $restaurant, string $externalDeliveryId): DoorDashDelivery
    {
        // The body is optional (it can change the tip or the phone number), and an object.
        return $this->parse($this->send(fn (): Response => $this->api($restaurant)
            ->withBody('{}', 'application/json')
            ->post('/drive/v2/quotes/'.rawurlencode($externalDeliveryId).'/accept')));
    }

    public function delivery(Restaurant $restaurant, string $externalDeliveryId): DoorDashDelivery
    {
        return $this->parse($this->send(fn (): Response => $this->retrying($this->api($restaurant))
            ->get('/drive/v2/deliveries/'.rawurlencode($externalDeliveryId))));
    }

    public function cancel(Restaurant $restaurant, string $externalDeliveryId): DoorDashDelivery
    {
        return $this->parse($this->send(fn (): Response => $this->api($restaurant)
            ->withBody('{}', 'application/json')
            ->put('/drive/v2/deliveries/'.rawurlencode($externalDeliveryId).'/cancel')));
    }

    public function checkCredentials(Restaurant $restaurant): void
    {
        // A delivery that can't exist: DoorDash answers 404 when it accepts the key.
        $response = $this->send(fn (): Response => $this->api($restaurant)
            ->get('/drive/v2/deliveries/key-check-'.Str::lower(Str::random(16))));

        if ($response->status() !== 404) {
            $this->parse($response);
        }
    }

    /** Calls for the restaurant, signed with its access key. */
    private function api(Restaurant $restaurant): PendingRequest
    {
        if (! $restaurant->doordashConfigured()) {
            throw CourierUnavailable::notConfigured();
        }

        $token = DoorDashJwt::make(
            (string) $restaurant->doordash_developer_id,
            (string) $restaurant->doordash_key_id,
            (string) $restaurant->doordash_signing_secret,
            time(),
        );

        return Http::baseUrl((string) config('services.doordash.url'))
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);
    }

    /** Looking something up is tried again when DoorDash can't be reached or has a fault of its own. */
    private function retrying(PendingRequest $request): PendingRequest
    {
        return $request->retry(3, 300, fn (Throwable $exception): bool => $exception instanceof ConnectionException
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

            throw CourierUnavailable::because($exception);
        }
    }

    private function parse(Response $response): DoorDashDelivery
    {
        if ($response->successful()) {
            $json = $response->json();

            return DoorDashDelivery::fromArray(is_array($json) ? $json : []);
        }

        if (in_array($response->status(), [400, 404, 409, 422], true)) {
            $fieldErrors = self::fieldErrors($response);

            throw new DoorDashRefused($response->status(), self::reason($response), array_key_first($fieldErrors), $fieldErrors);
        }

        $exception = new RuntimeException("DoorDash answered {$response->status()}: ".self::reason($response));
        report($exception);

        throw in_array($response->status(), [401, 403], true)
            ? DoorDashKeyRejected::from($exception)
            : CourierUnavailable::because($exception);
    }

    /**
     * DoorDash's explanation: each field it objected to and why, or else its message
     * ("Allowed distance between addresses exceeded").
     */
    private static function reason(Response $response): string
    {
        $fieldErrors = self::fieldErrors($response);

        if ($fieldErrors !== []) {
            return implode('; ', array_map(
                fn (string $field, string $error): string => "{$field}: {$error}",
                array_keys($fieldErrors),
                $fieldErrors,
            ));
        }

        $message = $response->json('message');

        return is_string($message) && $message !== '' ? $message : "HTTP {$response->status()}";
    }

    /**
     * DoorDash's field_errors, by field: [{"field": "dropoff_address", "error": "…"}].
     *
     * @return array<string, string>
     */
    private static function fieldErrors(Response $response): array
    {
        $errors = $response->json('field_errors');
        $byField = [];

        foreach (is_array($errors) ? $errors : [] as $error) {
            $field = is_array($error) ? ($error['field'] ?? null) : null;
            $message = is_array($error) ? ($error['error'] ?? null) : null;

            if (is_string($field) && $field !== '') {
                $byField[$field] = is_string($message) ? $message : '';
            }
        }

        return $byField;
    }
}
