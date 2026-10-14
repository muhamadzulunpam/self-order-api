<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function __construct(private MidtransService $midtrans) {}

    public function createOrGetPayment(Order $order): Payment
    {
        return DB::transaction(function () use ($order) {
            $order = Order::with('items', 'diningTable', 'payment')
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $order->status === OrderStatus::PendingPayment,
                409,
                'Pesanan ini tidak bisa dibayar.'
            );

            if ($order->payment) {
                return $order->payment;
            }

            $snap = $this->midtrans->createSnapTransaction($order);

            return $order->payment()->create([
                'provider' => 'midtrans',
                'gross_amount' => $order->total_amount,
                'status' => PaymentStatus::Pending,
                'snap_token' => $snap['token'],
                'raw_response' => ['snap' => $snap],
            ]);
        });
    }

    /**
     * Mengembalikan null jika order/payment tidak dikenal.
     *
     * @throws \DomainException jika nominal tidak cocok
     */
    public function handleNotification(array $payload): ?Payment
    {
        return DB::transaction(function () use ($payload) {
            $order = Order::where('order_code', $payload['order_id'])
                ->lockForUpdate()
                ->first();
            $payment = $order?->payment;

            if (! $order || ! $payment) {
                return null;
            }

            if ((int) round((float) $payload['gross_amount']) !== (int) $payment->gross_amount) {
                Log::warning('Midtrans: nominal notifikasi tidak cocok', [
                    'order' => $order->order_code,
                    'expected' => $payment->gross_amount,
                    'received' => $payload['gross_amount'],
                ]);

                throw new \DomainException('Nominal tidak cocok.');
            }

            // Sudah sukses: abaikan notifikasi susulan (idempotent).
            if ($payment->status === PaymentStatus::Success) {
                return $payment;
            }

            $transactionStatus = $payload['transaction_status'] ?? '';
            $fraudStatus = $payload['fraud_status'] ?? null;

            $newStatus = match ($transactionStatus) {
                'settlement' => PaymentStatus::Success,
                'capture' => match ($fraudStatus) {
                    'challenge' => PaymentStatus::Pending,
                    'deny' => PaymentStatus::Failed,
                    default => PaymentStatus::Success,
                },
                'pending' => PaymentStatus::Pending,
                'deny', 'failure', 'cancel' => PaymentStatus::Failed,
                'expire' => PaymentStatus::Expired,
                default => null, // refund, chargeback, dll: di luar cakupan fitur ini
            };

            $payment->fill([
                'transaction_id' => $payload['transaction_id'] ?? $payment->transaction_id,
                'payment_type' => $payload['payment_type'] ?? $payment->payment_type,
                'raw_response' => array_merge(
                    $payment->raw_response ?? [],
                    ['notification' => Arr::except($payload, ['signature_key'])]
                ),
            ]);

            if ($newStatus) {
                $payment->status = $newStatus;
            }

            $orderStatusChanged = null;

            if ($newStatus === PaymentStatus::Success) {
                $payment->paid_at = now();

                if ($order->status === OrderStatus::PendingPayment) {
                    $order->update(['status' => OrderStatus::Paid]);
                    $orderStatusChanged = OrderStatus::Paid;
                } else {
                    Log::warning('Midtrans: pembayaran sukses untuk order yang tidak lagi pending', [
                        'order' => $order->order_code,
                        'order_status' => $order->status->value,
                    ]);
                }
            } elseif (
                in_array($newStatus, [PaymentStatus::Failed, PaymentStatus::Expired], true)
                && $order->status === OrderStatus::PendingPayment
            ) {
                $order->update(['status' => OrderStatus::Cancelled]);
                $orderStatusChanged = OrderStatus::Cancelled;
            }

            $payment->save();

            if ($orderStatusChanged === OrderStatus::Paid) {
                OrderPaid::dispatch($order);
            }

            if ($orderStatusChanged) {
                OrderStatusUpdated::dispatch($order->order_code, $orderStatusChanged->value);
            }

            return $payment;
        });
    }
}
