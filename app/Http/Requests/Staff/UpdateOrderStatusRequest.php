<?php

namespace App\Http\Requests\Staff;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // akses dibatasi middleware role dan aturan di OrderService
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::enum(OrderStatus::class)->only([
                    OrderStatus::Preparing,
                    OrderStatus::Ready,
                    OrderStatus::Completed,
                ]),
            ],
        ];
    }
}
