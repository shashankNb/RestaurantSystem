<?php

namespace App\Http\Controllers\Api;

use App\Enums\SquareEnvironment;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessSquareEvent;
use App\Models\SquareEvent;
use App\Payments\InvalidWebhook;
use App\Payments\Square\SquareApp;
use App\Payments\Square\SquareWebhook;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The platform's Square webhooks, one per environment (/square/webhook/sandbox and
 * /square/webhook/production): events for every restaurant connected to the platform's
 * Square application. Only a correctly signed request is accepted. Events about our orders
 * and connections are stored once and processed straight away (retried on the queue if that
 * fails); the restaurant's other Square activity, like its in-person sales, is ignored.
 */
class SquareWebhookController extends Controller
{
    public function __invoke(Request $request, string $environment): JsonResponse
    {
        $environment = SquareEnvironment::from($environment);
        $webhook = new SquareWebhook(SquareApp::for($environment)?->webhookSignatureKey, self::notificationUrl($environment));

        try {
            $event = $webhook->verify($request->getContent(), $request->header('x-square-hmacsha256-signature'));
        } catch (InvalidWebhook $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }

        if (! ProcessSquareEvent::concernsUs($event, $environment)) {
            return response()->json(['received' => true]);
        }

        try {
            $stored = SquareEvent::query()->create([
                'environment' => $environment,
                'event_id' => $event['event_id'],
                'merchant_id' => $event['merchant_id'] ?? null,
                'type' => $event['type'],
                'payload' => $event,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        try {
            ProcessSquareEvent::dispatchSync($stored->id);
        } catch (Throwable $exception) {
            report($exception);
            ProcessSquareEvent::dispatch($stored->id);
        }

        return response()->json(['received' => true]);
    }

    /** The URL to give Square's webhook subscription for the environment: Square signs with it. */
    public static function notificationUrl(SquareEnvironment $environment): string
    {
        return rtrim((string) config('app.url'), '/').route('square.webhook', ['environment' => $environment->value], absolute: false);
    }
}
