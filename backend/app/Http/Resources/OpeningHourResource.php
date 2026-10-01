<?php

namespace App\Http\Resources;

use App\Models\OpeningHour;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One weekly shift. Times are local HH:MM; a closing time at or before the opening time
 * means the shift ends after midnight.
 *
 * @mixin OpeningHour
 */
class OpeningHourResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'day_of_week' => $this->day_of_week,
            'opens_at' => substr($this->opens_at, 0, 5),
            'closes_at' => substr($this->closes_at, 0, 5),
        ];
    }
}
