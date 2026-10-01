<?php

namespace App\Models;

use App\Enums\DevicePlatform;
use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\PushTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Expo push token registered by a signed-in customer's device. Guests' tokens
 * are stored on their order instead.
 */
#[Fillable(['user_id', 'expo_push_token', 'platform'])]
class PushToken extends Model
{
    /** @use HasFactory<PushTokenFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
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
