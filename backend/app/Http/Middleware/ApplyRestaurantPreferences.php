<?php

namespace App\Http\Middleware;

use App\Filament\Support\BrandPalette;
use App\Models\Restaurant;
use Closure;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentColor;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Back office: show every date and time in the restaurant's own timezone (the
 * database stays in UTC) and use the restaurant's brand colour.
 */
class ApplyRestaurantPreferences
{
    public function handle(Request $request, Closure $next): Response
    {
        $restaurant = Filament::getTenant();

        if ($restaurant instanceof Restaurant) {
            FilamentTimezone::set($restaurant->timezone);
            FilamentColor::register(['primary' => BrandPalette::fromHex($restaurant->brand_color)]);
        }

        return $next($request);
    }
}
