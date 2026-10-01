<?php

namespace App\Http\Resources;

use App\Models\ModifierOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ModifierOption
 */
class ModifierOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price_delta_cents' => $this->price_delta_cents,
            'is_available' => $this->is_available,
        ];
    }
}
