<?php

namespace App\Http\Resources;

use App\Models\SpecialHour;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A date whose regular hours are replaced: closed all day, or different times.
 *
 * @mixin SpecialHour
 */
class SpecialHourResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $open = ! $this->is_closed && $this->opens_at !== null && $this->closes_at !== null;

        return [
            'date' => $this->date->toDateString(),
            'is_closed' => ! $open,
            'opens_at' => $open ? substr((string) $this->opens_at, 0, 5) : null,
            'closes_at' => $open ? substr((string) $this->closes_at, 0, 5) : null,
            'note' => $this->note,
        ];
    }
}
