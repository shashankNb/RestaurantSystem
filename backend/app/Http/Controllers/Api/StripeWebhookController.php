<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStripeEvent;
use App\Models\StripeEvent;
use App\Payments\InvalidWebhook;
use App\Payments\StripeWebhook;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Stripe's webhook. Only a correctly signed request is accepted. Each event is stored once
 * (a replay is acknowledged and ignored) and processed straight away, so a paid order
 * reaches the kitchen within a second. If processing fails, it's retried on the queue.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhook $webhook): JsonResponse
    {
        try {
            $event = $webhook->verify($request->getContent(), $request->header('Stripe-Signature'));
        } catch (InvalidWebhook $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }

        if (! in_array($event->type, ProcessStripeEvent::HANDLED, true)) {
            return response()->json(['received' => true]);
        }

        try {
            $stored = StripeEvent::query()->create([
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
