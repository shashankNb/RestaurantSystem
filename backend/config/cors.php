<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // The API only: it uses bearer tokens, so no cookies or credentials are shared.
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // The ordering website's origins, comma-separated (the Expo dev server locally,
    // https://example-restaurant.com.au in production). Native apps don't send an Origin.
    // CORS_ALLOWED_ORIGINS (local development, preview addresses). Each restaurant's own
    // domain is added to this on each request (App\Http\Middleware\AllowRestaurantOrigins).
    'configured_origins' => $configured = array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:8081, https://food.plexuslogics.com')),
    ))),

    'allowed_origins' => $configured,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Lets the web app read how long to wait after a 429.
    'exposed_headers' => ['Retry-After'],

    'max_age' => 600,

    'supports_credentials' => false,

];
