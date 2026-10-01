<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Customers can delete their own account from the app. Staff and owners are removed by
     * an owner in the back office first, so a restaurant is never left without its owner.
     */
    public function delete(User $user, User $account): Response
    {
        if (! $user->is($account)) {
            return Response::deny('You can only delete your own account.');
        }

        if ($account->memberships()->exists()) {
            return Response::deny('Accounts with staff or owner access to a restaurant can’t be deleted in the app. Ask an owner of the restaurant to remove your access in the back office, then try again.');
        }

        return Response::allow();
    }
}
