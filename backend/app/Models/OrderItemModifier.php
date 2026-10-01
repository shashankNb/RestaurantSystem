<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\OrderItemModifierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['modifier_option_id', 'group_name', 'name', 'price_delta_cents'])]
class OrderItemModifier extends Model
{
    /** @use HasFactory<OrderItemModifierFactory> */
    use BelongsToRestaurant, HasFactory;

    protected static function booted(): void
    {
        static::creating(function (OrderItemModifier $modifier): void {
            $modifier->restaurant_id ??= $modifier->orderItem?->restaurant_id;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_delta_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<ModifierOption, $this>
     */
    public function modifierOption(): BelongsTo
    {
        return $this->belongsTo(ModifierOption::class);
    }
}
