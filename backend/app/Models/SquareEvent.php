<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A webhook event from a restaurant's own Square application, received at its endpoint.
 * Unique per restaurant, which makes processing idempotent.
 *
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 */
#[Fillable(['restaurant_id', 'event_id', 'type', 'payload', 'processed_at'])]
class SquareEvent extends Model
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
