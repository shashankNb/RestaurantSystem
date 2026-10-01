<?php

namespace App\Http\Requests;

use App\Enums\DevicePlatform;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Signed in: the restaurant the app belongs to. As a guest: the order to follow, proved
 * by its tracking token.
 */
class PushTokenRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $guest = $this->user('sanctum') === null;

        return [
            'expo_push_token' => ['required', 'string', 'max:255', 'regex:/^Expo(nent)?PushToken\[[^\]]+\]$/'],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            'restaurant' => [$guest ? 'nullable' : 'required', 'string'],
            'order' => [$guest ? 'required' : 'nullable', 'array'],
            'order.public_id' => [$guest ? 'required' : 'nullable', 'string'],
            'order.tracking_token' => [$guest ? 'required' : 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expo_push_token.regex' => 'That isn’t an Expo push token.',
            'order.required' => 'Sign in, or say which order the notifications are for.',
        ];
    }
}
