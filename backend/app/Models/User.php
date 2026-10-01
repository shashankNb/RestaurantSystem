<?php

namespace App\Models;

use App\Enums\RestaurantRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

/**
 * Customers, kitchen staff and owners share this table. What a user may do at a
 * restaurant comes from their membership (restaurant_user) there, if any.
 */
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsToMany<Restaurant, $this>
     */
    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
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

    public function roleAt(Restaurant|int $restaurant): ?RestaurantRole
    {
        return $this->memberships()
            ->where('restaurant_id', $restaurant instanceof Restaurant ? $restaurant->getKey() : $restaurant)
            ->first()
            ?->role;
    }

    public function isOwnerOf(Restaurant|int $restaurant): bool
    {
        return $this->roleAt($restaurant) === RestaurantRole::Owner;
    }

    /**
     * Owners and staff can both use the kitchen screens.
     */
    public function worksAt(Restaurant|int $restaurant): bool
    {
        return $this->roleAt($restaurant) !== null;
    }

    /**
     * The back office is for owners only; staff use the kitchen screens.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->memberships()->where('role', RestaurantRole::Owner)->exists();
    }

    /**
     * @return Collection<int, Restaurant>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->restaurants()->wherePivot('role', RestaurantRole::Owner->value)->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Restaurant && $this->isOwnerOf($tenant);
    }
}
