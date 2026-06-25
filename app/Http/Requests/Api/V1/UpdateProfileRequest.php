<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'email' => ['sometimes', 'email', 'max:200', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'curp' => ['sometimes', 'nullable', 'string', 'size:18', 'regex:/^[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[0-9A-Z]{2}$/'],
        ];
    }
}
