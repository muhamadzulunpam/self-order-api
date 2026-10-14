<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiningTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'is_active' => $this->is_active,
            'order_url' => $this->orderUrl(),
            'orders_count' => $this->whenCounted('orders'),
        ];
    }
}
