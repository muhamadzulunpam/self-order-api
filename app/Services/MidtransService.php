<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MidtransService
{
    public function createSnapTransaction(Order $order): array
    {
        $order->loadMissing('items', 'diningTable');

        try {
            $response = Http::withBasicAuth((string) config('services.midtrans.server_key'), '')
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->post($this->baseUrl().'/snap/v1/transactions', [
                    'transaction_details' => [
                        'order_id' => $order->order_code,
                        'gross_amount' => $order->total_amount,
                    ],
                    'item_details' => $order->items->map(fn ($item) => [
                        'id' => (string) $item->menu_id,
                        'price' => $item->price,
                        'quantity' => $item->quantity,
                        'name' => Str::limit($item->menu_name, 50, ''),
                    ])->values()->all(),
                    'customer_details' => [
                        'first_name' => $order->customer_name ?? 'Meja '.$order->diningTable->number,
                    ],
                    'expiry' => ['unit' => 'minute', 'duration' => 30],
                ]);
        } catch (ConnectionException $e) {
            Log::error('Midtrans tidak dapat dihubungi', ['order' => $order->order_code]);

            throw new PaymentGatewayException('Payment gateway tidak dapat dihubungi, coba lagi.');
        }

        if ($response->failed() || ! $response->json('token')) {
            Log::error('Midtrans Snap gagal', [
                'order' => $order->order_code,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new PaymentGatewayException('Gagal membuat transaksi pembayaran, coba lagi.');
        }

        return $response->json();
    }

    public function isValidSignature(array $payload): bool
    {
        $serverKey = (string) config('services.midtrans.server_key');

        if ($serverKey === '') {
            return false;
        }

        $expected = hash('sha512',
            ($payload['order_id'] ?? '').
            ($payload['status_code'] ?? '').
            ($payload['gross_amount'] ?? '').
            $serverKey
        );

        return hash_equals($expected, (string) ($payload['signature_key'] ?? ''));
    }

    private function baseUrl(): string
    {
        return config('services.midtrans.is_production')
            ? 'https://app.midtrans.com'
            : 'https://app.sandbox.midtrans.com';
    }
}
