<?php

namespace App\Http\Resources;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user. `restaurants` lists where they work, so the app knows whether to
 * offer the kitchen screens. Expects memberships.restaurant loaded.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'restaurants' => $this->memberships->map(fn (Membership $membership): array => [
                'id' => $membership->restaurant_id,
                'slug' => $membership->restaurant->slug,
                'name' => $membership->restaurant->name,
                'role' => $membership->role->value,
            ])->values(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
