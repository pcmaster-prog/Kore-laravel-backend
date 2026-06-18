<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'           => ['sometimes', 'string', 'max:160'],
            'email'          => ['sometimes', 'email', 'max:200', Rule::unique('users', 'email')->ignore($this->route('id'))],
            'password'       => ['sometimes', 'nullable', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
            'role'           => ['sometimes', Rule::in(['admin', 'supervisor', 'empleado', 'aspirante', 'empleado_prueba'])],
            'section'        => ['sometimes', 'nullable', 'string', 'max:120'],
            'employee_code'  => ['sometimes', 'nullable', 'string', 'max:50'],
            'position_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'hired_at'       => ['sometimes', 'nullable', 'date'],
            'payment_type'   => ['sometimes', 'nullable', 'in:hourly,daily'],
            'hourly_rate'    => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'daily_rate'     => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rfc'            => ['sometimes', 'nullable', 'string', 'size:13'],
            'nss'            => ['sometimes', 'nullable', 'string', 'max:20'],
            'curp'           => ['sometimes', 'nullable', 'string', 'size:18', 'regex:/^[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[0-9A-Z]{2}$/'],
        ];
    }
}
