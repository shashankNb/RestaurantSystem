<?php

namespace App\Models;

use App\Casts\Secret;
use App\Enums\RestaurantRole;
use App\Payments\WalletSetup;
use App\Support\RestaurantOrigins;
use Database\Factories\RestaurantFactory;
use Filament\Models\Contracts\HasAvatar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
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
 * @property array<string, mixed>|null $stripe_wallets
 */
#[Fillable([
    'name',
    'slug',
    'custom_domain',
    'stripe_publishable_key',
    'stripe_secret_key',
    'stripe_webhook_secret',
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
    'dine_in_enabled',
    'default_prep_minutes',
    'auto_reject_minutes',
])]
// Its own Stripe account's secrets: never in a response, a log or an export.
#[Hidden(['stripe_secret_key', 'stripe_webhook_secret'])]
#[RouteKey('slug')]
class Restaurant extends Model implements HasAvatar
{
    /** @use HasFactory<RestaurantFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // Its custom domain may have changed: the API's CORS origins come from these.
        static::saved(fn () => RestaurantOrigins::forget());
        static::deleted(fn () => RestaurantOrigins::forget());

        // The last Apple Pay and Google Pay check was of the old key's Stripe account.
        static::saving(function (Restaurant $restaurant): void {
            if ($restaurant->isDirty('stripe_secret_key') && ! $restaurant->isDirty('stripe_wallets')) {
                $restaurant->stripe_wallets = null;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address' => 'array',
            // Encrypted with APP_KEY: losing or changing it means entering the keys again.
            'stripe_secret_key' => Secret::class,
            'stripe_webhook_secret' => Secret::class,
            'stripe_wallets' => 'array',
            'is_accepting_orders' => 'boolean',
            'pickup_enabled' => 'boolean',
            'delivery_enabled' => 'boolean',
            'dine_in_enabled' => 'boolean',
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
     * @return HasMany<DiningTable, $this>
     */
    public function diningTables(): HasMany
    {
        // Each table knows its restaurant without another query (its QR code links to the
        // restaurant's own site).
        return $this->hasMany(DiningTable::class)->chaperone();
    }

    /**
     * The tables customers can order from, in the order staff set (then by label, so "2"
     * comes before "10").
     *
     * @return HasMany<DiningTable, $this>
     */
    public function activeDiningTables(): HasMany
    {
        return $this->diningTables()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderByRaw('LENGTH(label)')
            ->orderBy('label');
    }

    /**
     * Payments go to the restaurant's own Stripe account, so it can take orders only once its
     * keys are in: the secret key to charge, and the webhook secret to hear that a payment
     * went through (without it, paid orders would never reach the kitchen).
     */
    public function acceptsPayments(): bool
    {
        // Test keys or live keys: test keys take test payments, with Stripe's test cards.
        return str_starts_with((string) $this->stripe_publishable_key, 'pk_')
            && filled($this->stripe_secret_key)
            && filled($this->stripe_webhook_secret);
    }

    /**
     * The restaurant's ordering website, for QR codes and links in emails: its own domain,
     * or else the shared one (ORDERING_WEB_URL).
     */
    public function webUrl(): string
    {
        return filled($this->custom_domain)
            ? "https://{$this->custom_domain}"
            : rtrim((string) config('ordering.web_url'), '/');
    }

    /**
     * The website's domain, for registering with the restaurant's Stripe account so Apple Pay
     * and Google Pay show there. Null while it has no public one: Stripe can't register
     * localhost, an IP address or a .test or .local name.
     */
    public function walletDomain(): ?string
    {
        $host = strtolower((string) parse_url($this->webUrl(), PHP_URL_HOST));

        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
            return null;
        }

        return $host;
    }

    /** The last check of its Stripe account for Apple Pay and Google Pay, if any. */
    public function walletSetup(): ?WalletSetup
    {
        return WalletSetup::fromArray($this->stripe_wallets);
    }

    /** Customers can order to a table: dine-in is on and there's a table to choose. */
    public function offersDineIn(): bool
    {
        return $this->dine_in_enabled && $this->activeDiningTables->isNotEmpty();
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
