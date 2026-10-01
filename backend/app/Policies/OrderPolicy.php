<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * Accept, reject and move orders along: staff and owners of the order's restaurant.
     */
    public function manage(User $user, Order $order): bool
    {
        return $user->worksAt($order->restaurant_id);
    }
}
