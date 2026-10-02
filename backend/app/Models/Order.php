<?php

namespace App\Models;

use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Status and payment status change only through OrderService, which enforces
 * the lifecycle and writes the audit trail; they are deliberately not fillable.
 *
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property FulfilmentType $fulfilment_type
 * @property Carbon|null $business_date
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $estimated_ready_at
 * @property Carbon|null $placed_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $ready_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $refunded_at
 */
#[Fillable([
    'user_id',
    'fulfilment_type',
    'scheduled_for',
    'customer_name',
    'customer_phone',
    'customer_email',
    'delivery_zone_id',
    'delivery_line1',
    'delivery_line2',
    'delivery_suburb',
    'delivery_state',
    'delivery_postcode',
    'delivery_instructions',
    'dining_table_id',
    'table_label',
    'subtotal_cents',
    'delivery_fee_cents',
    'discount_cents',
    'total_cents',
    'gst_cents',
    'promo_code_id',
    'promo_code',
    'notes',
    'idempotency_key',
    'request_fingerprint',
    'tracking_token',
    'push_token',
])]
#[Hidden(['tracking_token', 'idempotency_key', 'request_fingerprint', 'push_token'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use BelongsToRestaurant, HasFactory, HasUlids;

    /**
     * The ULID goes in public_id (URLs and channel names); the primary key stays an integer.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'fulfilment_type' => FulfilmentType::class,
            'business_date' => 'date:Y-m-d',
            'order_number' => 'integer',
            'scheduled_for' => 'datetime',
            'subtotal_cents' => 'integer',
            'delivery_fee_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'gst_cents' => 'integer',
            'prep_minutes' => 'integer',
            'estimated_ready_at' => 'datetime',
            'placed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'ready_at' => 'datetime',
            'completed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderStatusEvent, $this>
     */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<PromoCode, $this>
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /**
     * The table a dine-in order goes to (null once the table is removed; table_label keeps
     * its name).
     *
     * @return BelongsTo<DiningTable, $this>
     */
    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }

    /**
     * @return BelongsTo<DeliveryZone, $this>
     */
    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    /**
     * The kitchen number as staff and customers see it, e.g. "042".
     *
     * @return Attribute<string|null, never>
     */
    protected function displayNumber(): Attribute
    {
        return Attribute::get(fn (): ?string => self::formatNumber($this->order_number));
    }

    public static function formatNumber(?int $orderNumber): ?string
    {
        return $orderNumber === null ? null : str_pad((string) $orderNumber, 3, '0', STR_PAD_LEFT);
    }

    public function isAsap(): bool
    {
        return $this->scheduled_for === null;
    }
}
