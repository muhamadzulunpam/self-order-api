<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MidtransService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MidtransWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        MidtransService $midtrans,
        PaymentService $payments
    ): JsonResponse {
        $request->validate([
            'order_id' => ['required', 'string'],
            'status_code' => ['required'],
            'gross_amount' => ['required'],
            'signature_key' => ['required', 'string'],
            'transaction_status' => ['required', 'string'],
        ]);

        $payload = $request->all();

        if (! $midtrans->isValidSignature($payload)) {
            Log::warning('Midtrans: signature tidak valid', ['order_id' => $payload['order_id']]);

            return response()->json(['message' => 'Signature tidak valid.'], 403);
        }

        try {
            $payment = $payments->handleNotification($payload);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        // Order tidak dikenal: balas 200 agar Midtrans tidak mengirim ulang terus-menerus.
        if (! $payment) {
            return response()->json(['message' => 'Order tidak dikenal, diabaikan.']);
        }

        return response()->json(['message' => 'OK']);
    }
}