<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Models\Restaurant;
use App\Services\PricingService;
use Carbon\CarbonImmutable;

class QuoteController extends Controller
{
    /**
     * Prices a cart. Always 200 for a well-formed cart: problems that stop it being ordered
     * come back in `errors`, alongside the totals, so the cart can show both.
     */
    public function __invoke(QuoteRequest $request, Restaurant $restaurant, PricingService $pricing): QuoteResource
    {
        return new QuoteResource($pricing->quote($restaurant, $request->cart(), CarbonImmutable::now()));
    }
}
