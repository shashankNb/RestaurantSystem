<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\OpeningHourFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One regular weekly shift, in the restaurant's local time. A day can have
 * several shifts (lunch and dinner).
 *
 * @property int $day_of_week 0 = Sunday … 6 = Saturday
 * @property string $opens_at HH:MM:SS
 * @property string $closes_at HH:MM:SS
 */
#[Fillable(['day_of_week', 'opens_at', 'closes_at'])]
class OpeningHour extends Model
{
    /** @use HasFactory<OpeningHourFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    /**
     * A closing time at or before the opening time means the shift ends the next day.
     */
    public function closesAfterMidnight(): bool
    {
        return $this->closes_at <= $this->opens_at;
    }
}
