<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Each restaurant's owner enters its own Stripe keys in the back office (Restaurant
    // settings → Payments). These are only the demo restaurant's, for local development:
    // DemoRestaurantSeeder gives them to it.
    'stripe' => [
        // Publishable key (pk_…).
        'key' => env('STRIPE_KEY'),
        // Secret key (sk_…).
        'secret' => env('STRIPE_SECRET'),
        // Signing secret (whsec_…) for POST /api/v1/stripe/webhook/himalayan-momo-house.
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'expo' => [
        'push_url' => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
        // Only needed if "enhanced push security" is on for the Expo project.
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
