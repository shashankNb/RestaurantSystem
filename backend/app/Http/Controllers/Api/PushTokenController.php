<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PushTokenRequest;
use App\Models\Order;
use App\Models\PushToken;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Registers a device for push notifications. Signed in: the device gets notifications about
 * all of the customer's orders at this restaurant. As a guest: about one order, proved by
 * its tracking token.
 */
class PushTokenController extends Controller
{
    public function store(PushTokenRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user('sanctum');
        $token = $request->string('expo_push_token')->toString();

        if ($user !== null) {
            $restaurant = Restaurant::query()->where('slug', $request->string('restaurant')->toString())->firstOrFail();

            // A token belongs to one device; if someone else signed in on it, it's theirs now.
            PushToken::query()->firstOrNew(['expo_push_token' => $token])->forceFill([
                'restaurant_id' => $restaurant->id,
                'user_id' => $user->id,
                'platform' => $request->string('platform')->toString(),
            ])->save();

            return response()->json(['data' => ['registered' => true]], 201);
        }

        $order = Order::query()->where('public_id', $request->string('order.public_id')->toString())->first();
        $trackingToken = $request->string('order.tracking_token')->toString();

        abort_unless($order !== null && hash_equals($order->tracking_token, $trackingToken), 404);

        $order->forceFill(['push_token' => $token])->save();

        return response()->json(['data' => ['registered' => true]], 201);
    }
}
