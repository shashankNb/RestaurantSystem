<?php

namespace App\Policies;

use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Saved addresses belong to one customer. Anyone else is told it doesn't exist.
 */
class CustomerAddressPolicy
{
    public function update(User $user, CustomerAddress $address): Response
    {
        return $address->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, CustomerAddress $address): Response
    {
        return $this->update($user, $address);
    }
}
