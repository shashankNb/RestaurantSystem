<?php

namespace App\Http\Middleware;

use App\Support\RestaurantOrigins;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets each restaurant's own website call the API: adds every restaurant's custom domain to
 * the CORS origins from CORS_ALLOWED_ORIGINS. Runs before Laravel's HandleCors, which reads
 * the list on each request.
 */
class AllowRestaurantOrigins
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*') && $request->headers->has('Origin')) {
            config(['cors.allowed_origins' => array_values(array_unique([
                ...(array) config('cors.configured_origins', []),
                ...RestaurantOrigins::all(),
            ]))]);
        }

        return $next($request);
    }
}
