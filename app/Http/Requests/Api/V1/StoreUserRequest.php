<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:200', Rule::unique('users', 'email')],
            'password' => ['nullable', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
            'role' => ['required', Rule::in(['admin', 'supervisor', 'empleado', 'aspirante', 'empleado_prueba'])],
            'section' => ['nullable', 'string', 'max:120'],
            'employee_code' => ['nullable', 'string', 'max:50'],
            'position_title' => ['nullable', 'string', 'max:120'],
            'hired_at' => ['nullable', 'date'],
            'check_in_time' => ['nullable', 'date_format:H:i'],
            'payment_type' => ['nullable', 'in:hourly,daily'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'daily_rate' => ['nullable', 'numeric', 'min:0'],
            'rfc' => ['nullable', 'string', 'size:13'],
            'nss' => ['nullable', 'string', 'max:20'],
            'curp' => ['nullable', 'string', 'size:18', 'regex:/^[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[0-9A-Z]{2}$/'],
        ];
    }
}
