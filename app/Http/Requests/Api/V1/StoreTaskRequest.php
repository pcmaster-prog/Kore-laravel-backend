<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'         => ['required', 'string', 'max:180'],
            'description'   => ['nullable', 'string'],
            'priority'      => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'due_date'      => ['nullable', 'date'],
            'checklist'     => ['nullable', 'array'],
            'empleado_ids'  => ['nullable', 'array'],
            'empleado_ids.*'=> ['uuid'],
        ];
    }
}
