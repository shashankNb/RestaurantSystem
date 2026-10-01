<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media disk
    |--------------------------------------------------------------------------
    |
    | Filesystem disk for restaurant logos, cover images and menu photos. Use
    | "public" locally (run `artisan storage:link`) and "s3" in production.
    |
    */

    'media_disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Ordering website
    |--------------------------------------------------------------------------
    |
    | The customer website, for links in emails (order tracking). Each restaurant
    | uses its custom_domain when it has one.
    |
    */

    'web_url' => env('ORDERING_WEB_URL', 'http://localhost:8081'),

    /*
    |--------------------------------------------------------------------------
    | Unpaid orders
    |--------------------------------------------------------------------------
    |
    | An order still waiting for payment after this many minutes is cancelled,
    | and its Stripe PaymentIntent with it.
    |
    */

    'pending_payment_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Business day
    |--------------------------------------------------------------------------
    |
    | Daily order numbers (001, 002…) restart each business day. The day changes
    | at this local hour rather than midnight, so a late shift keeps counting.
    |
    */

    'business_day_starts_at_hour' => 4,

];
