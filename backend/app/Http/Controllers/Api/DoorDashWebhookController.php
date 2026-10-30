<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\CourierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * DoorDash's webhook, one per restaurant (/doordash/webhook/{slug}): the restaurant's own
 * DoorDash account sends its deliveries' progress here. DoorDash doesn't sign its webhooks;
 * instead its "Basic Auth" option sends the value the owner pasted in, the one the back office
 * made for this restaurant, in the Authorization header. Only a request carrying it is
 * accepted, and it only ever touches that restaurant's orders. News is applied straight away;
 * if that fails, DoorDash sends it again (twice), and the every-minute check
 * (orders:sync-couriers) catches anything still missed.
 */
class DoorDashWebhookController extends Controller
{
    public function __invoke(Request $request, Restaurant $restaurant, CourierService $couriers): JsonResponse
    {
        $expected = $restaurant->doordash_webhook_secret;

        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, self::credential($request))) {
            // Usually a webhook still set up with an old value: its news waits for the next check.
            Log::warning('A DoorDash webhook was refused.', [
                'restaurant' => $restaurant->slug,
                'reason' => is_string($expected) && $expected !== '' ? 'Its Authorization header isn’t this restaurant’s value.' : 'This restaurant has no DoorDash webhook value.',
            ]);

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $couriers->receive($restaurant, $request->json()->all());

        return response()->json(['received' => true]);
    }

    /**
     * The value from the Authorization header, with or without a scheme in front ("Basic …"),
     * since owners may paste it either way.
     */
    private static function credential(Request $request): string
    {
        return (string) preg_replace('/^(?:(?:basic|bearer)\s+)+/i', '', trim((string) $request->header('Authorization', '')));
    }
}
