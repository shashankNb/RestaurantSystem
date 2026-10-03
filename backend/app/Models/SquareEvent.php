<?php

namespace App\Models;

use App\Enums\SquareEnvironment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A webhook event from the platform's Square application, about one of the Square accounts
 * connected to it (merchant_id). Unique per environment, which makes processing idempotent.
 *
 * @property SquareEnvironment $environment
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 */
#[Fillable(['environment', 'event_id', 'merchant_id', 'type', 'payload', 'processed_at'])]
class SquareEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => SquareEnvironment::class,
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
