<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DeliveryCheckRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'postcode' => ['required', 'string', 'regex:/^\d{4}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'postcode.required' => 'Enter your postcode.',
            'postcode.regex' => 'Enter a 4-digit postcode, like 3006.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('postcode'))) {
            $this->merge(['postcode' => preg_replace('/\s+/', '', $this->input('postcode'))]);
        }
    }
}
