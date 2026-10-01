<?php

namespace App\Policies;

use App\Models\MenuItem;
use App\Models\User;

class MenuItemPolicy
{
    /**
     * The sold-out switch: staff and owners of the item's restaurant.
     */
    public function updateAvailability(User $user, MenuItem $item): bool
    {
        return $user->worksAt($item->restaurant_id);
    }
}
