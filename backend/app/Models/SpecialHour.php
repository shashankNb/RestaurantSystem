<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\SpecialHourFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Replaces the regular hours for one local date: a public holiday closure or
 * a one-off change of hours.
 *
 * @property Carbon $date
 * @property string|null $opens_at HH:MM:SS
 * @property string|null $closes_at HH:MM:SS
 */
#[Fillable(['date', 'is_closed', 'opens_at', 'closes_at', 'note'])]
class SpecialHour extends Model
{
    /** @use HasFactory<SpecialHourFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'is_closed' => 'boolean',
        ];
    }
}
