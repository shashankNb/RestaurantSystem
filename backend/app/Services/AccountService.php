<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AccountService
{
    /**
     * Name kept on a deleted customer's past orders.
     */
    public const DELETED_CUSTOMER_NAME = 'Deleted customer';

    /**
     * Deletes a customer's account. Their orders are financial records, so they stay, but
     * everything that identifies the person is removed from them first. Saved addresses
     * and push tokens are deleted with the account (foreign-key cascades).
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->orders()->update([
                'user_id' => null,
                'customer_name' => self::DELETED_CUSTOMER_NAME,
                'customer_phone' => null,
                'customer_email' => null,
                'delivery_line1' => null,
                'delivery_line2' => null,
                'delivery_instructions' => null,
                'notes' => null,
                'push_token' => null,
            ]);

            $user->tokens()->delete();
            $user->delete();
        });
    }
}
