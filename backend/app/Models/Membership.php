<?php

namespace App\Models;

use App\Enums\RestaurantRole;
use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's role at a restaurant: a row of the restaurant_user pivot, as a model
 * so the back office can manage staff accounts as a tenant-owned resource.
 *
 * @property RestaurantRole $role
 */
#[Table('restaurant_user')]
#[Fillable(['user_id', 'role'])]
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => RestaurantRole::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
