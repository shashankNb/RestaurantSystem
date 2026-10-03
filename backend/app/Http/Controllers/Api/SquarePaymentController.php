<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SquarePaymentRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\CheckoutException;
use App\Services\SquareCheckoutService;
use Illuminate\Http\JsonResponse;

/**
 * Pays a Square order with the token from the app's Square payment form. 200 when it's
 * paid (the order is placed and goes to the kitchen), 202 while Square finishes it, 402
 * when the card is declined (the message says what to do), 409 when the order can't be
 * paid any more, and 503 when Square can't be reached.
 */
class SquarePaymentController extends Controller
{
    public function store(SquarePaymentRequest $request, string $publicId, SquareCheckoutService $square): JsonResponse
    {
        $order = Order::query()->where('public_id', $publicId)->first();

        abort_unless($order !== null && hash_equals($order->tracking_token, $request->trackingToken()), 404);

        try {
            $result = $square->pay($order, $request->sourceId(), $request->verificationToken(), $request->idempotencyKey());
        } catch (CheckoutException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }

        return response()->json([
            'data' => ['order' => new OrderResource($result['order']->load(['restaurant', 'items.modifiers', 'statusEvents']))],
        ], $result['completed'] ? 200 : 202);
    }
}
