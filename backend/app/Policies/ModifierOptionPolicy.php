<?php

namespace App\Policies;

use App\Models\ModifierOption;
use App\Models\User;

class ModifierOptionPolicy
{
    /**
     * The sold-out switch: staff and owners of the option's restaurant.
     */
    public function updateAvailability(User $user, ModifierOption $option): bool
    {
        return $user->worksAt($option->restaurant_id);
    }
}
