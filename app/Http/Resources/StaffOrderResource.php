<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_code' => $this->order_code,
            'status' => $this->status->value,
            'table_number' => $this->diningTable->number,
            'customer_name' => $this->customer_name,
            'notes' => $this->notes,
            'total_amount' => $this->total_amount,
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'menu_name' => $item->menu_name,
                'quantity' => $item->quantity,
                'spice_level' => $item->spice_level,
                'notes' => $item->notes,
            ])->values()->all(),
            'paid_at' => $this->payment?->paid_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
