<?php

namespace App\Policies;

use App\Models\Restaurant;
use App\Models\User;

class RestaurantPolicy
{
    /**
     * Change settings, branding and hours: owners only.
     */
    public function update(User $user, Restaurant $restaurant): bool
    {
        return $user->isOwnerOf($restaurant);
    }
}
