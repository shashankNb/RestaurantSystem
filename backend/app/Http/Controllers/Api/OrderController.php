<?php

namespace App\Http\Controllers\Api;

use App\Data\QuoteError;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * Creates an order waiting for payment and returns the PaymentIntent's client secret.
     * 201 for a new order; 200 when the same Idempotency-Key and request were sent before.
     */
    public function store(StoreOrderRequest $request, Restaurant $restaurant, CheckoutService $checkout): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user('sanctum');

        try {
            $result = $checkout->checkout(
                $restaurant,
                $request->cart(),
                $request->customer(),
                $user,
                $request->idempotencyKey(),
                $request->fingerprint(),
                CarbonImmutable::now(),
            );
        } catch (CheckoutException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                ...($exception->quote === null ? [] : ['errors' => self::fieldErrors($exception->quote->errors)]),
            ], $exception->status);
        }

        $order = $result->order->load(['restaurant', 'items.modifiers', 'statusEvents']);

        return response()->json([
            'data' => [
                'order' => new OrderResource($order),
                'tracking_token' => $order->tracking_token,
                'payment' => [
                    'payment_intent_id' => $result->paymentIntent->id,
                    'client_secret' => $result->paymentIntent->clientSecret,
                    'status' => $result->paymentIntent->status,
                    'amount_cents' => $result->paymentIntent->amountCents,
                    'currency' => strtolower($restaurant->currency),
                ],
            ],
        ], $result->created ? 201 : 200);
    }

    /**
     * Order tracking, for the customer who placed it: with the tracking token (guests), or
     * signed in as them. Anyone else gets a 404, so the order's existence isn't revealed.
     */
    public function show(Request $request, string $publicId): OrderResource
    {
        $order = Order::query()->where('public_id', $publicId)->first();
        $token = $request->query('token');
        /** @var User|null $user */
        $user = $request->user('sanctum');

        $allowed = $order !== null && (
            (is_string($token) && hash_equals($order->tracking_token, $token))
            || ($user !== null && $order->user_id === $user->id)
        );

        abort_unless($allowed, 404);

        return new OrderResource($order->load(['restaurant', 'items.modifiers', 'statusEvents']));
    }

    /**
     * @param  list<QuoteError>  $errors
     * @return array<string, list<string>>
     */
    private static function fieldErrors(array $errors): array
    {
        $fields = [];

        foreach ($errors as $error) {
            $fields[$error->field ?? 'items'][] = $error->message;
        }

        return $fields;
    }
}
