<?php

namespace Database\Factories;

use App\Enums\DevicePlatform;
use App\Models\PushToken;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PushToken>
 */
class PushTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'user_id' => User::factory(),
            'expo_push_token' => 'ExponentPushToken['.Str::random(22).']',
            'platform' => DevicePlatform::Ios,
        ];
    }
}
