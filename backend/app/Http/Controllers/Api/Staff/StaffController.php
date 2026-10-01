<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Base for the kitchen endpoints. Staff and owners act for one restaurant: the one named by
 * the `restaurant` parameter (its slug), or the only one they work at.
 */
abstract class StaffController extends Controller
{
    /**
     * @throws AuthorizationException when they don't work there
     * @throws ValidationException when they work at several and didn't say which
     */
    protected function restaurant(Request $request): Restaurant
    {
        /** @var User $user */
        $user = $request->user();
        $memberships = $user->memberships()->with('restaurant')->get();
        $slug = $request->input('restaurant');

        if (is_string($slug) && $slug !== '') {
            $membership = $memberships->first(fn (Membership $membership): bool => $membership->restaurant->slug === $slug);

            if ($membership === null) {
                throw new AuthorizationException('You don’t have staff access to that restaurant.');
            }

            return $membership->restaurant;
        }

        return match ($memberships->count()) {
            0 => throw new AuthorizationException('This account doesn’t have staff access to a restaurant.'),
            1 => $memberships->firstOrFail()->restaurant,
            default => throw ValidationException::withMessages([
                'restaurant' => 'You work at more than one restaurant. Say which one with its slug.',
            ]),
        };
    }
}
