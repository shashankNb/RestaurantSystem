<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PushToken;
use App\Support\OrderMessages;
use Illuminate\Support\Facades\Http;

/**
 * Sends push notifications through Expo's push API. A customer's devices are the push
 * token saved on the order (guests) and the tokens saved for their account at this
 * restaurant. Tokens Expo reports as no longer registered are forgotten.
 */
final class PushNotifications
{
    public function sendOrderUpdate(Order $order): void
    {
        $message = OrderMessages::forCustomer($order);
        $tokens = $this->tokensFor($order);

        if ($message === null || $tokens === []) {
            return;
        }

        $messages = array_map(fn (string $token): array => [
            'to' => $token,
            'title' => $message['title'],
            'body' => $message['body'],
            'sound' => 'default',
            // Android: the app's "Order updates" channel (created before asking permission).
            'channelId' => 'default',
            'data' => ['public_id' => $order->public_id, 'status' => $order->status->value],
        ], $tokens);

        $accessToken = config('services.expo.access_token');
        $request = Http::acceptJson()->asJson()->timeout(10);

        if (is_string($accessToken) && $accessToken !== '') {
            $request = $request->withToken($accessToken);
        }

        $response = $request->post((string) config('services.expo.push_url'), $messages)->throw();

        /** @var list<array{status?: string, details?: array{error?: string}}> $tickets */
        $tickets = $response->json('data', []);

        foreach ($tickets as $index => $ticket) {
            if (($ticket['status'] ?? null) === 'error' && ($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                $this->forget($order, $tokens[$index] ?? null);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function tokensFor(Order $order): array
    {
        $tokens = $order->push_token === null ? [] : [$order->push_token];

        if ($order->user_id !== null) {
            array_push($tokens, ...PushToken::query()
                ->where('restaurant_id', $order->restaurant_id)
                ->where('user_id', $order->user_id)
                ->pluck('expo_push_token')
                ->all());
        }

        return array_values(array_unique($tokens));
    }

    private function forget(Order $order, ?string $token): void
    {
        if ($token === null) {
            return;
        }

        PushToken::query()->where('expo_push_token', $token)->delete();

        if ($order->push_token === $token) {
            $order->forceFill(['push_token' => null])->save();
        }
    }
}
