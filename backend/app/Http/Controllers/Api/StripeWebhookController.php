<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStripeEvent;
use App\Models\Restaurant;
use App\Models\StripeEvent;
use App\Payments\InvalidWebhook;
use App\Payments\StripeWebhook;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stripe's webhook, one per restaurant (/stripe/webhook/{slug}): the restaurant's own Stripe
 * account sends its events here, signed with the signing secret the owner entered. Only a
 * correctly signed request is accepted, and its events only ever touch that restaurant's
 * orders. Each event is stored once (a replay is acknowledged and ignored) and processed
 * straight away, so a paid order reaches the kitchen within a second. If processing fails,
 * it's retried on the queue.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, Restaurant $restaurant): JsonResponse
    {
        $webhook = new StripeWebhook($restaurant->stripe_webhook_secret);

        try {
            $event = $webhook->verify($request->getContent(), $request->header('Stripe-Signature'));
        } catch (InvalidWebhook $exception) {
            // Usually a signing secret that doesn't match the one in the back office: its orders
            // would wait for payment until checked another way.
            Log::warning('A Stripe webhook was refused.', ['restaurant' => $restaurant->slug, 'reason' => $exception->getMessage()]);

            return response()->json(['message' => $exception->getMessage()], 400);
        }

        if (! in_array($event->type, ProcessStripeEvent::HANDLED, true)) {
            return response()->json(['received' => true]);
        }

        try {
            $stored = StripeEvent::query()->create([
                'restaurant_id' => $restaurant->id,
                'stripe_event_id' => $event->id,
                'type' => $event->type,
                'payload' => $event->toArray(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        try {
            ProcessStripeEvent::dispatchSync($stored->id);
        } catch (Throwable $exception) {
            report($exception);
            ProcessStripeEvent::dispatch($stored->id);
        }

        return response()->json(['received' => true]);
    }
}
