<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeliveryCheckRequest;
use App\Http\Resources\DeliveryZoneResource;
use App\Models\Restaurant;
use App\Services\DeliveryService;
use Illuminate\Http\JsonResponse;

class DeliveryCheckController extends Controller
{
    public function __invoke(DeliveryCheckRequest $request, Restaurant $restaurant, DeliveryService $delivery): JsonResponse
    {
        $check = $delivery->check($restaurant, $request->string('postcode')->toString());

        return response()->json([
            'data' => [
                'deliverable' => $check->isDeliverable(),
                'zone' => $check->zone === null ? null : new DeliveryZoneResource($check->zone),
                'message' => $check->message,
            ],
        ]);
    }
}
