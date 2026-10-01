<?php

namespace App\Models;

use App\Enums\RestaurantRole;
use Database\Factories\RestaurantFactory;
use Filament\Models\Contracts\HasAvatar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * The tenant. Everything a restaurant owns hangs off one of these relationships.
 *
 * @property array{line1?: ?string, line2?: ?string, suburb?: ?string, state?: ?string, postcode?: ?string, country?: ?string}|null $address
 */
#[Fillable([
    'name',
    'slug',
    'custom_domain',
    'description',
    'timezone',
    'currency',
    'phone',
    'email',
    'address',
    'abn',
    'logo',
    'cover_image',
    'brand_color',
    'is_accepting_orders',
    'pickup_enabled',
    'delivery_enabled',
    'default_prep_minutes',
    'auto_reject_minutes',
])]
#[RouteKey('slug')]
class Restaurant extends Model implements HasAvatar
{
    /** @use HasFactory<RestaurantFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address' => 'array',
            'is_accepting_orders' => 'boolean',
            'pickup_enabled' => 'boolean',
            'delivery_enabled' => 'boolean',
            'default_prep_minutes' => 'integer',
            'auto_reject_minutes' => 'integer',
        ];
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function owners(): BelongsToMany
    {
        return $this->users()->wherePivot('role', RestaurantRole::Owner->value);
    }

    /**
     * @return HasMany<OpeningHour, $this>
     */
    public function openingHours(): HasMany
    {
        return $this->hasMany(OpeningHour::class);
    }

    /**
     * @return HasMany<SpecialHour, $this>
     */
    public function specialHours(): HasMany
    {
        return $this->hasMany(SpecialHour::class);
    }

    /**
     * @return HasMany<MenuCategory, $this>
     */
    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class);
    }

    /**
     * @return HasMany<MenuItem, $this>
     */
    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    /**
     * @return HasMany<ModifierGroup, $this>
     */
    public function modifierGroups(): HasMany
    {
        return $this->hasMany(ModifierGroup::class);
    }

    /**
     * @return HasMany<ModifierOption, $this>
     */
    public function modifierOptions(): HasMany
    {
        return $this->hasMany(ModifierOption::class);
    }

    /**
     * @return HasMany<DeliveryZone, $this>
     */
    public function deliveryZones(): HasMany
    {
        return $this->hasMany(DeliveryZone::class);
    }

    /**
     * @return HasMany<PromoCode, $this>
     */
    public function promoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<PushToken, $this>
     */
    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => self::mediaUrl($this->logo));
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function coverImageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => self::mediaUrl($this->cover_image));
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->logo_url;
    }

    /**
     * Public URL for a file on the media disk (logos, cover images, menu photos).
     */
    public static function mediaUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk(config('ordering.media_disk'))->url($path);
    }
}
