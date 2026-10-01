<?php

namespace App\Http\Resources;

use App\Models\DeliveryZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliveryZone
 */
class DeliveryZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'postcodes' => $this->postcodes,
            'fee_cents' => $this->fee_cents,
            'min_order_cents' => $this->min_order_cents,
            'estimated_minutes' => $this->estimated_minutes,
        ];
    }
}
