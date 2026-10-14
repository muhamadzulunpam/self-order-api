<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UpdateOrderStatusRequest;
use App\Http\Resources\StaffOrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(OrderStatus::class)],
        ]);

        $statuses = $request->input('status', ['paid', 'preparing', 'ready']);

        $orders = Order::with('items', 'diningTable', 'payment')
            ->whereIn('status', $statuses)
            ->orderBy(
                Payment::select('paid_at')
                    ->whereColumn('payments.order_id', 'orders.id')
                    ->limit(1)
            )
            ->orderBy('id')
            ->limit(100)
            ->get();

        return StaffOrderResource::collection($orders);
    }

    public function updateStatus(
        UpdateOrderStatusRequest $request,
        Order $order,
        OrderService $orders
    ): StaffOrderResource {
        $updated = $orders->updateStatus(
            $order,
            OrderStatus::from($request->validated('status')),
            $request->user()
        );

        return new StaffOrderResource($updated);
    }
}
