<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RestaurantResource;
use App\Models\Restaurant;
use App\Services\OpeningHoursService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantController extends Controller
{
    /**
     * How far ahead holidays and one-off changes are listed.
     */
    private const SPECIAL_HOURS_DAYS = 30;

    public function show(Restaurant $restaurant, OpeningHoursService $hours): RestaurantResource
    {
        $now = CarbonImmutable::now();
        $today = $now->setTimezone($restaurant->timezone)->toDateString();

        $restaurant->load([
            'openingHours',
            'deliveryZones' => fn (HasMany $query) => $query->where('is_active', true)->orderBy('id'),
            'activeDiningTables',
            'specialHours' => fn (HasMany $query) => $query->whereBetween('date', [
                $today,
                $now->setTimezone($restaurant->timezone)->addDays(self::SPECIAL_HOURS_DAYS)->toDateString(),
            ]),
        ]);

        return new RestaurantResource($restaurant, $hours->status($restaurant, $now));
    }
}
