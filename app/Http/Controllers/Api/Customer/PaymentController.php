<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function store(string $qrToken, string $orderCode, PaymentService $payments): JsonResponse
    {
        $table = DiningTable::findActiveByToken($qrToken);
        abort_if(! $table, 404, 'Meja tidak ditemukan atau tidak aktif.');

        $order = $table->findOrderByCode($orderCode);
        abort_if(! $order, 404, 'Pesanan tidak ditemukan.');

        try {
            $payment = $payments->createOrGetPayment($order);
        } catch (PaymentGatewayException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'data' => [
                'order_code' => $order->order_code,
                'snap_token' => $payment->snap_token,
                'redirect_url' => $payment->raw_response['snap']['redirect_url'] ?? null,
                'client_key' => config('services.midtrans.client_key'),
                'is_production' => (bool) config('services.midtrans.is_production'),
            ],
        ]);
    }
}
