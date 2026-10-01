<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\ModifierOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['modifier_group_id', 'name', 'price_delta_cents', 'is_available', 'sort_order'])]
class ModifierOption extends Model
{
    /** @use HasFactory<ModifierOptionFactory> */
    use BelongsToRestaurant, HasFactory;

    protected static function booted(): void
    {
        // An option always belongs to its group's restaurant.
        static::creating(function (ModifierOption $option): void {
            $option->restaurant_id ??= $option->group?->restaurant_id;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_delta_cents' => 'integer',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ModifierGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }
}
