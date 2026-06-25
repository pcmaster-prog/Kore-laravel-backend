<?php

namespace App\Http\Requests\Api\V1;

use App\Services\RecaptchaValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'admin_name' => ['required', 'string', 'max:160'],
            'admin_email' => ['required', 'email', 'max:200', Rule::unique('users', 'email')],
            'admin_password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()->symbols()->uncompromised()],
            'empresa_nombre' => ['required', 'string', 'max:160'],
            'industry' => ['nullable', 'string', 'max:100'],
            'employee_count_range' => ['nullable', 'string', 'max:50'],
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', 'max:50'],
            'recaptcha_token' => ['required', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $token = $this->input('recaptcha_token');
            if (! RecaptchaValidator::validate($token, 'register')) {
                $validator->errors()->add('recaptcha_token', 'La verificación de seguridad falló. Intenta de nuevo.');
            }
        });
    }
}
