<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiningTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; 
    }

    public function rules(): array
    {
        return [
            'number' => [
                'required', 'integer', 'min:1', 'max:999',
                Rule::unique('dining_tables', 'number')->ignore($this->route('dining_table')),
            ],
            'is_active' => ['boolean'],
        ];
    }
}
