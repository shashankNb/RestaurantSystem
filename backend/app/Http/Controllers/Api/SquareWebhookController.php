<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSquareEvent;
use App\Models\Restaurant;
use App\Models\SquareEvent;
use App\Payments\InvalidWebhook;
use App\Payments\Square\SquareWebhook;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Square's webhook, one per restaurant (/square/webhook/{slug}): the restaurant's own Square
 * application sends its events here, signed with the signature key the owner entered. Only a
 * correctly signed request is accepted, and its events only ever touch that restaurant's
 * orders. Events about its online orders are stored once and processed straight away
 * (retried on the queue if that fails); its other Square activity, like its in-person sales,
 * is acknowledged and ignored.
 */
class SquareWebhookController extends Controller
{
    public function __invoke(Request $request, Restaurant $restaurant): JsonResponse
    {
        $webhook = new SquareWebhook($restaurant->square_webhook_signature_key, self::notificationUrl($restaurant));

        try {
            $event = $webhook->verify($request->getContent(), $request->header('x-square-hmacsha256-signature'));
        } catch (InvalidWebhook $exception) {
            // Usually a signing secret that doesn't match the one in the back office: its orders
            // would wait for payment until checked another way.
            Log::warning('A Square webhook was refused.', ['restaurant' => $restaurant->slug, 'reason' => $exception->getMessage()]);

            return response()->json(['message' => $exception->getMessage()], 400);
        }

        if (! ProcessSquareEvent::concernsUs($event, $restaurant)) {
            return response()->json(['received' => true]);
        }

        try {
            $stored = SquareEvent::query()->create([
                'restaurant_id' => $restaurant->id,
                'event_id' => $event['event_id'],
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

    /**
     * The URL for the restaurant's Square webhook subscription, which Square signs with: built
     * from APP_URL, as the back office shows it.
     */
    public static function notificationUrl(Restaurant $restaurant): string
    {
        return rtrim((string) config('app.url'), '/').route('square.webhook', ['restaurant' => $restaurant], absolute: false);
    }
}
