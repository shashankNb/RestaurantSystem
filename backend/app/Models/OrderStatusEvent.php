<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\OrderStatusEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail row; written only by OrderService.
 *
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 */
#[Fillable(['from_status', 'to_status', 'user_id', 'note'])]
class OrderStatusEvent extends Model
{
    /** @use HasFactory<OrderStatusEventFactory> */
    use BelongsToRestaurant, HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (OrderStatusEvent $event): void {
            $event->restaurant_id ??= $event->order?->restaurant_id;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
