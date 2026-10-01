<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\DeliveryZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property list<string> $postcodes
 */
#[Fillable(['name', 'postcodes', 'fee_cents', 'min_order_cents', 'estimated_minutes', 'is_active'])]
class DeliveryZone extends Model
{
    /** @use HasFactory<DeliveryZoneFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'postcodes' => 'array',
            'fee_cents' => 'integer',
            'min_order_cents' => 'integer',
            'estimated_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function coversPostcode(string $postcode): bool
    {
        return in_array(trim($postcode), $this->postcodes, true);
    }
}
