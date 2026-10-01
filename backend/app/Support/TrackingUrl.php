<?php

namespace App\Support;

use App\Models\Order;

/**
 * The customer's link to follow their order on the website. It carries the tracking
 * token, so guests need no account.
 */
final class TrackingUrl
{
    public static function for(Order $order): string
    {
        $domain = $order->restaurant->custom_domain;
        $base = $domain !== null && $domain !== ''
            ? "https://{$domain}"
            : rtrim((string) config('ordering.web_url'), '/');

        return "{$base}/order/{$order->public_id}?".http_build_query(['token' => $order->tracking_token]);
    }
}
