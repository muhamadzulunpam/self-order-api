<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreOrderRequest;
use App\Http\Resources\PublicOrderResource;
use App\Models\DiningTable;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;


class OrderController extends Controller
{
    public function __construct(private OrderService $orders)
    {
    }

    public function store(StoreOrderRequest $request, string $qrToken): JsonResponse
    {
        $table = $this->resolveTable($qrToken);

        $order = $this->orders->create($table, $request->validated());

        return (new PublicOrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $qrToken, string $orderCode): PublicOrderResource
    {
        $table = $this->resolveTable($qrToken);

        $order = Order::with('items', 'diningTable', 'payment')
            ->where('dining_table_id', $table->id)
            ->where('order_code', $orderCode)
            ->first();

        abort_if(! $order, 404, 'Pesanan tidak ditemukan.');

        return new PublicOrderResource($order);
    }

    private function resolveTable(string $qrToken): DiningTable
    {
        $table = DiningTable::findActiveByToken($qrToken);

        abort_if(! $table, 404, 'Meja tidak ditemukan atau tidak aktif.');

        return $table;
    }
}