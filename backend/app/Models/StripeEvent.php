<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A received Stripe webhook event. The unique stripe_event_id makes processing idempotent.
 *
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 */
#[Fillable(['stripe_event_id', 'type', 'payload', 'processed_at'])]
class StripeEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
