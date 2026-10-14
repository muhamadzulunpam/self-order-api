<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\DiningTable;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MidtransPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.midtrans.server_key' => 'SB-Mid-server-test',
            'services.midtrans.client_key' => 'SB-Mid-client-test',
            'services.midtrans.is_production' => false,
        ]);
    }

    private function makeOrder(array $attributes = []): Order
    {
        $table = DiningTable::factory()->create();
        $menu = Menu::factory()->create(['price' => 12000, 'name' => 'Mie Suit']);

        $order = Order::create(array_merge([
            'order_code' => 'ORD-TEST-0001',
            'dining_table_id' => $table->id,
            'status' => OrderStatus::PendingPayment,
            'total_amount' => 24000,
        ], $attributes));

        $order->items()->create([
            'menu_id' => $menu->id,
            'menu_name' => 'Mie Suit',
            'price' => 12000,
            'quantity' => 2,
            'subtotal' => 24000,
        ]);

        return $order;
    }

    private function makePayment(Order $order, PaymentStatus $status = PaymentStatus::Pending): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'gross_amount' => $order->total_amount,
            'status' => $status,
            'snap_token' => 'snap-token-lama',
        ]);
    }

    private function payUrl(Order $order): string
    {
        return "/api/public/tables/{$order->diningTable->qr_token}/orders/{$order->order_code}/pay";
    }

    private function fakeSnap(): void
    {
        Http::fake([
            'app.sandbox.midtrans.com/*' => Http::response([
                'token' => 'snap-token-123',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-123',
            ], 201),
        ]);
    }

    private function notification(Order $order, array $overrides = []): array
    {
        $payload = array_merge([
            'order_id' => $order->order_code,
            'status_code' => '200',
            'gross_amount' => '24000.00',
            'transaction_status' => 'settlement',
            'transaction_id' => 'trx-123',
            'payment_type' => 'qris',
            'fraud_status' => 'accept',
        ], $overrides);

        $payload['signature_key'] = hash('sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'SB-Mid-server-test'
        );

        return $payload;
    }

    // ---------- Snap token ----------

    public function test_pay_creates_snap_token_and_pending_payment(): void
    {
        $this->fakeSnap();
        $order = $this->makeOrder();

        $this->postJson($this->payUrl($order))
            ->assertOk()
            ->assertJsonPath('data.snap_token', 'snap-token-123')
            ->assertJsonPath('data.client_key', 'SB-Mid-client-test')
            ->assertJsonPath('data.is_production', false)
            ->assertJsonStructure(['data' => ['redirect_url']]);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => 'pending',
            'gross_amount' => 24000,
            'snap_token' => 'snap-token-123',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/snap/v1/transactions')
            && $request['transaction_details']['order_id'] === 'ORD-TEST-0001'
            && $request['transaction_details']['gross_amount'] === 24000
            && $request['item_details'][0]['quantity'] === 2);
    }

    public function test_pay_twice_reuses_existing_snap_token(): void
    {
        $this->fakeSnap();
        $order = $this->makeOrder();

        $this->postJson($this->payUrl($order))->assertOk();
        $this->postJson($this->payUrl($order))
            ->assertOk()
            ->assertJsonPath('data.snap_token', 'snap-token-123');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_cannot_pay_order_from_another_table(): void
    {
        $this->fakeSnap();
        $order = $this->makeOrder();
        $other = DiningTable::factory()->create();

        $this->postJson("/api/public/tables/{$other->qr_token}/orders/{$order->order_code}/pay")
            ->assertNotFound();
    }

    public function test_cannot_pay_order_that_is_not_pending(): void
    {
        $this->fakeSnap();
        $order = $this->makeOrder(['status' => OrderStatus::Paid]);

        $this->postJson($this->payUrl($order))->assertStatus(409);

        Http::assertNothingSent();
    }

    public function test_gateway_failure_returns_502_and_creates_no_payment(): void
    {
        Http::fake(['*' => Http::response(['error_messages' => ['gagal']], 500)]);
        $order = $this->makeOrder();

        $this->postJson($this->payUrl($order))->assertStatus(502);

        $this->assertDatabaseCount('payments', 0);
    }

    // ---------- Webhook ----------

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        $order = $this->makeOrder();
        $payment = $this->makePayment($order);

        $payload = $this->notification($order);
        $payload['signature_key'] = 'salah';

        $this->postJson('/api/webhooks/midtrans', $payload)->assertForbidden();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_settlement_marks_payment_success_and_order_paid(): void
    {
        $order = $this->makeOrder();
        $payment = $this->makePayment($order);

        $this->postJson('/api/webhooks/midtrans', $this->notification($order))->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Success, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('trx-123', $payment->transaction_id);
        $this->assertSame('qris', $payment->payment_type);
        $this->assertArrayNotHasKey('signature_key', $payment->raw_response['notification']);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_duplicate_notification_is_idempotent(): void
    {
        $order = $this->makeOrder();
        $payment = $this->makePayment($order);

        $this->postJson('/api/webhooks/midtrans', $this->notification($order))->assertOk();
        $this->postJson('/api/webhooks/midtrans', $this->notification($order))->assertOk();

        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_late_expire_notification_does_not_override_success(): void
    {
        $order = $this->makeOrder();
        $payment = $this->makePayment($order);

        $this->postJson('/api/webhooks/midtrans', $this->notification($order))->assertOk();
        $this->postJson('/api/webhooks/midtrans', $this->notification($order, [
            'transaction_status' => 'expire',
            'status_code' => '407',
        ]))->assertOk();

        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_expire_cancels_order(): void
    {
        $order = $this->makeOrder();
        $payment = $this->makePayment($order);

        $this->postJson('/api/webhooks/midtrans', $this->notification($order, [
            'transaction_status' => 'expire',
            'status_code' => '407',
        ]))->assertOk();

        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_deny_marks_payment_failed_and_cancels_order(): void
    {
        $order = $this->makeOrder();
        $payment = $this->makePayment($order);

        $this->postJson('/api/webhooks/midtrans', $this->notification($order, [
            'transaction_status' => 'deny',
            'status_code' => '202',
        ]))->assertOk();

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_pending_notification_keeps_order_pending_and_stores_payment_type(): void
    {
        $order = $this->makeOrder();
        $payment = $this->makePayment($order);

        $this->postJson('/api/webhooks/midtrans', $this->notification($order, [
            'transaction_status' => 'pending',
            'status_code' => '201',
            'payment_type' => 'bank_transfer',
        ]))->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('bank_transfer', $payment->payment_type);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_card_capture_with_accept_is_success(): void
    {
        $order = $this->makeOrder();
        $this->makePayment($order);

        $this->postJson('/api/webhooks/midtrans', $this->notification($order, [
            'transaction_status' => 'capture',
            'payment_type' => 'credit_card',
            'fraud_status' => 'accept',
        ]))->assertOk();

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_card_capture_with_challenge_stays_pending(): void
    {
        $order = $this->makeOrder();
        $payment = $this->makePayment($order);

        $this->postJson('/api/webhooks/midtrans', $this->notification($order, [
            'transaction_status' => 'capture',
            'payment_type' => 'credit_card',
            'fraud_status' => 'challenge',
        ]))->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_amount_mismatch_is_rejected(): void
    {
        $order = $this->makeOrder();
        $payment = $this->makePayment($order);

        $this->postJson('/api/webhooks/midtrans', $this->notification($order, [
            'gross_amount' => '1000.00',
        ]))->assertStatus(400);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_unknown_order_is_ignored_with_200(): void
    {
        $order = $this->makeOrder(); // belum punya payment

        $this->postJson('/api/webhooks/midtrans', $this->notification($order))->assertOk();

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_order_status_endpoint_reflects_payment_after_webhook(): void
    {
        $order = $this->makeOrder();
        $this->makePayment($order);

        $this->postJson('/api/webhooks/midtrans', $this->notification($order))->assertOk();

        $this->getJson("/api/public/tables/{$order->diningTable->qr_token}/orders/{$order->order_code}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_status', 'success');
    }
}
