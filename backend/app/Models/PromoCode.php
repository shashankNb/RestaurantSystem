<?php

namespace App\Models;

use App\Enums\PromoCodeType;
use App\Models\Concerns\BelongsToRestaurant;
use App\Support\Money;
use Database\Factories\PromoCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property PromoCodeType $type
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
#[Fillable([
    'code',
    'type',
    'value',
    'min_order_cents',
    'starts_at',
    'ends_at',
    'max_uses',
    'is_active',
])]
class PromoCode extends Model
{
    /** @use HasFactory<PromoCodeFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PromoCodeType::class,
            'value' => 'integer',
            'min_order_cents' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_uses' => 'integer',
            'uses_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Codes are stored upper-case so lookups don't depend on how customers type them.
     *
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::set(fn (string $value): string => self::normalise($value));
    }

    public static function normalise(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** "10% off" or "$5.00 off". */
    public function describe(): string
    {
        return $this->type === PromoCodeType::Percent
            ? "{$this->value}% off"
            : Money::format($this->value).' off';
    }
}
