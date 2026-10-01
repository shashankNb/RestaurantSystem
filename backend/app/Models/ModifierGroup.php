<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\ModifierGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A set of choices shared by many items, e.g. "Choose filling" or "Spice level".
 */
#[Fillable(['name', 'min_select', 'max_select', 'sort_order'])]
class ModifierGroup extends Model
{
    /** @use HasFactory<ModifierGroupFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_select' => 'integer',
            'max_select' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<ModifierOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsToMany<MenuItem, $this>
     */
    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class)->withPivot('sort_order');
    }

    public function isRequired(): bool
    {
        return $this->min_select > 0;
    }

    /**
     * The selection rule in words, as customers see it: "Required · choose 1",
     * "Optional · choose up to 2", "Required · choose 1 to 3".
     */
    public function selectionRule(): string
    {
        $prefix = $this->isRequired() ? 'Required' : 'Optional';

        return match (true) {
            $this->min_select === $this->max_select => "{$prefix} · choose {$this->max_select}",
            $this->min_select === 0 => "{$prefix} · choose up to {$this->max_select}",
            default => "{$prefix} · choose {$this->min_select} to {$this->max_select}",
        };
    }
}
