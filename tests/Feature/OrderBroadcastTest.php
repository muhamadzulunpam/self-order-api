<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Events\OrderStatusUpdated;
use App\Models\DiningTable;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OrderBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.midtrans.server_key' => 'SB-Mid-server-test']);
        Event::fake([OrderPaid::class, OrderStatusUpdated::class]);
    }

    private function makePendingOrder(): Order
    {
        $table = DiningTable::factory()->create(['number' => 4]);
        $menu = Menu::factory()->create(['price' => 12000]);

        $order = Order::create([
            'order_code' => 'ORD-TEST-0001',
            'dining_table_id' => $table->id,
            'status' => OrderStatus::PendingPayment,
            'total_amount' => 24000,
        ]);

        $order->items()->create([
            'menu_id' => $menu->id,
            'menu_name' => 'Mie Suit',
            'price' => 12000,
            'quantity' => 2,
            'spice_level' => 3,
            'subtotal' => 24000,
        ]);

        Payment::create([
            'order_id' => $order->id,
            'gross_amount' => 24000,
            'status' => PaymentStatus::Pending,
            'snap_token' => 'snap-token',
        ]);

        return $order;
    }

    private function notify(Order $order, array $overrides = [], bool $validSignature = true)
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

        $payload['signature_key'] = $validSignature
            ? hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'SB-Mid-server-test')
            : 'salah';

        return $this->postJson('/api/webhooks/midtrans', $payload);
    }

    public function test_settlement_dispatches_order_paid_and_status_updated(): void
    {
        $order = $this->makePendingOrder();

        $this->notify($order)->assertOk();

        Event::assertDispatched(OrderPaid::class, fn ($event) => $event->order->is($order));
        Event::assertDispatched(
            OrderStatusUpdated::class,
            fn ($event) => $event->orderCode === 'ORD-TEST-0001' && $event->status === 'paid'
        );
    }

    public function test_duplicate_notification_does_not_dispatch_events_twice(): void
    {
        $order = $this->makePendingOrder();

        $this->notify($order)->assertOk();
        $this->notify($order)->assertOk();

        Event::assertDispatchedTimes(OrderPaid::class, 1);
        Event::assertDispatchedTimes(OrderStatusUpdated::class, 1);
    }

    public function test_expired_payment_dispatches_cancelled_status_but_not_order_paid(): void
    {
        $order = $this->makePendingOrder();

        $this->notify($order, ['transaction_status' => 'expire', 'status_code' => '407'])->assertOk();

        Event::assertNotDispatched(OrderPaid::class);
        Event::assertDispatched(
            OrderStatusUpdated::class,
            fn ($event) => $event->status === 'cancelled'
        );
    }

    public function test_pending_notification_dispatches_nothing(): void
    {
        $order = $this->makePendingOrder();

        $this->notify($order, ['transaction_status' => 'pending', 'status_code' => '201'])->assertOk();

        Event::assertNotDispatched(OrderPaid::class);
        Event::assertNotDispatched(OrderStatusUpdated::class);
    }

    public function test_invalid_signature_dispatches_nothing(): void
    {
        $order = $this->makePendingOrder();

        $this->notify($order, validSignature: false)->assertForbidden();

        Event::assertNotDispatched(OrderPaid::class);
        Event::assertNotDispatched(OrderStatusUpdated::class);
    }

    public function test_order_paid_targets_private_staff_channel_with_full_order(): void
    {
        $order = $this->makePendingOrder();
        $event = new OrderPaid($order);

        $this->assertSame('private-staff.orders', $event->broadcastOn()[0]->name);
        $this->assertSame('order.paid', $event->broadcastAs());

        $payload = $event->broadcastWith()['order'];
        $this->assertSame('ORD-TEST-0001', $payload['order_code']);
        $this->assertSame(4, $payload['table_number']);
        $this->assertSame('Mie Suit', $payload['items'][0]['menu_name']);
        $this->assertSame(2, $payload['items'][0]['quantity']);
        $this->assertSame(3, $payload['items'][0]['spice_level']);
    }

    public function test_status_updated_targets_public_order_channel_with_minimal_payload(): void
    {
        $event = new OrderStatusUpdated('ORD-TEST-0001', 'paid');

        $this->assertSame('orders.ORD-TEST-0001', $event->broadcastOn()[0]->name);
        $this->assertSame('order.status-updated', $event->broadcastAs());
        $this->assertSame(
            ['order_code' => 'ORD-TEST-0001', 'status' => 'paid'],
            $event->broadcastWith()
        );
    }

    public function test_channel_auth_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-staff.orders',
            'socket_id' => '1234.5678',
        ])->assertUnauthorized();
    }
}
