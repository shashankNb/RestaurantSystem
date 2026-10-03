<?php

namespace App\Support;

use App\Models\Restaurant;
use Illuminate\Support\Facades\Cache;

/**
 * The web addresses of restaurants' own ordering sites (https://{custom_domain}), which may
 * call the API from the browser. Cached; a restaurant being saved or deleted clears it.
 */
final class RestaurantOrigins
{
    private const CACHE_KEY = 'cors:restaurant-origins';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        /** @var list<string> */
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => Restaurant::query()
            ->whereNotNull('custom_domain')
            ->where('custom_domain', '!=', '')
            ->pluck('custom_domain')
            // Browsers send the host in lower case, and CORS compares it exactly.
            ->map(fn (string $domain): string => 'https://'.strtolower($domain))
            ->values()
            ->all());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
