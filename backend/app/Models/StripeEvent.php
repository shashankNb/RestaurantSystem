<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Stripe webhook event received at a restaurant's endpoint. Unique per restaurant, which
 * makes processing idempotent (restaurants sharing a Stripe account each get a copy).
 *
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 */
#[Fillable(['restaurant_id', 'stripe_event_id', 'type', 'payload', 'processed_at'])]
class StripeEvent extends Model
{
    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

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
