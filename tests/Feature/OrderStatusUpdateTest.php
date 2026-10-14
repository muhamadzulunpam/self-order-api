<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Events\OrderStatusUpdated;
use App\Events\StaffOrderUpdated;
use App\Models\DiningTable;
use App\Models\Menu;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderStatusUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([StaffOrderUpdated::class, OrderStatusUpdated::class]);
    }

    private function makeOrder(OrderStatus $status = OrderStatus::Paid, ?int $tableNumber = null): Order
    {
        $table = DiningTable::factory()->create(
            $tableNumber ? ['number' => $tableNumber] : []
        );
        $menu = Menu::factory()->create(['price' => 10000]);

        $order = Order::create([
            'order_code' => 'ORD-'.Str::upper(Str::random(8)),
            'dining_table_id' => $table->id,
            'status' => $status,
            'total_amount' => 10000,
        ]);

        $order->items()->create([
            'menu_id' => $menu->id,
            'menu_name' => 'Mie Suit',
            'price' => 10000,
            'quantity' => 1,
            'subtotal' => 10000,
        ]);

        return $order;
    }

    private function actAs(UserRole $role): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
    }

    private function setStatus(Order $order, string $status)
    {
        return $this->patchJson("/api/staff/orders/{$order->id}/status", ['status' => $status]);
    }

    public function test_guest_cannot_update_status(): void
    {
        $order = $this->makeOrder();

        $this->setStatus($order, 'preparing')->assertUnauthorized();
    }

    public function test_kitchen_can_start_preparing_and_response_has_labels(): void
    {
        $this->actAs(UserRole::Kitchen);
        $order = $this->makeOrder();

        $this->setStatus($order, 'preparing')
            ->assertOk()
            ->assertJsonPath('data.status', 'preparing')
            ->assertJsonPath('data.status_label', 'Sedang dimasak')
            ->assertJsonPath('data.next_status', 'ready');

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);
    }

    public function test_full_flow_with_correct_roles(): void
    {
        $order = $this->makeOrder();

        $this->actAs(UserRole::Kitchen);
        $this->setStatus($order, 'preparing')->assertOk();
        $this->setStatus($order, 'ready')->assertOk();

        $this->actAs(UserRole::Cashier);
        $this->setStatus($order, 'completed')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.next_status', null);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_admin_can_perform_every_step(): void
    {
        $this->actAs(UserRole::Admin);
        $order = $this->makeOrder();

        foreach (['preparing', 'ready', 'completed'] as $status) {
            $this->setStatus($order, $status)->assertOk();
        }

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_kitchen_cannot_complete_order(): void
    {
        $this->actAs(UserRole::Kitchen);
        $order = $this->makeOrder(OrderStatus::Ready);

        $this->setStatus($order, 'completed')->assertForbidden();

        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
    }

    public function test_cashier_cannot_start_preparing_or_mark_ready(): void
    {
        $this->actAs(UserRole::Cashier);
        $paid = $this->makeOrder(OrderStatus::Paid);
        $preparing = $this->makeOrder(OrderStatus::Preparing);

        $this->setStatus($paid, 'preparing')->assertForbidden();
        $this->setStatus($preparing, 'ready')->assertForbidden();

        $this->assertSame(OrderStatus::Paid, $paid->fresh()->status);
        $this->assertSame(OrderStatus::Preparing, $preparing->fresh()->status);
    }

    public function test_cannot_skip_a_step_even_as_admin(): void
    {
        $this->actAs(UserRole::Admin);
        $order = $this->makeOrder(OrderStatus::Paid);

        $this->setStatus($order, 'ready')->assertStatus(409);
        $this->setStatus($order, 'completed')->assertStatus(409);

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_cannot_move_backwards(): void
    {
        $this->actAs(UserRole::Admin);
        $order = $this->makeOrder(OrderStatus::Ready);

        $this->setStatus($order, 'preparing')->assertStatus(409);

        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
    }

    public function test_unpaid_finished_and_cancelled_orders_cannot_be_changed(): void
    {
        $this->actAs(UserRole::Admin);

        foreach ([OrderStatus::PendingPayment, OrderStatus::Completed, OrderStatus::Cancelled] as $status) {
            $order = $this->makeOrder($status);

            $this->setStatus($order, 'preparing')->assertStatus(409);

            $this->assertSame($status, $order->fresh()->status);
        }
    }

    public function test_second_identical_request_is_rejected(): void
    {
        $this->actAs(UserRole::Kitchen);
        $order = $this->makeOrder();

        $this->setStatus($order, 'preparing')->assertOk();
        $this->setStatus($order, 'preparing')->assertStatus(409);
    }

    public function test_status_must_be_one_staff_can_set(): void
    {
        $this->actAs(UserRole::Admin);
        $order = $this->makeOrder();

        foreach (['cancelled', 'paid', 'pending_payment', 'ngawur'] as $status) {
            $this->setStatus($order, $status)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['status']);
        }
    }

    public function test_unknown_order_returns_404(): void
    {
        $this->actAs(UserRole::Kitchen);

        $this->patchJson('/api/staff/orders/99999/status', ['status' => 'preparing'])
            ->assertNotFound();
    }

    public function test_successful_update_dispatches_staff_and_customer_events(): void
    {
        $this->actAs(UserRole::Kitchen);
        $order = $this->makeOrder();

        $this->setStatus($order, 'preparing')->assertOk();

        Event::assertDispatched(StaffOrderUpdated::class, fn ($event) => $event->order->is($order));
        Event::assertDispatched(
            OrderStatusUpdated::class,
            fn ($event) => $event->orderCode === $order->order_code && $event->status === 'preparing'
        );
    }

    public function test_rejected_update_dispatches_no_events(): void
    {
        $this->actAs(UserRole::Kitchen);
        $order = $this->makeOrder(OrderStatus::Paid);

        $this->setStatus($order, 'ready')->assertStatus(409);
        $this->setStatus($order, 'completed')->assertForbidden();

        Event::assertNotDispatched(StaffOrderUpdated::class);
        Event::assertNotDispatched(OrderStatusUpdated::class);
    }

    public function test_staff_event_targets_private_channel_with_full_order(): void
    {
        $order = $this->makeOrder(
            OrderStatus::Preparing,
            tableNumber: 6
        );
        $event = new StaffOrderUpdated($order);

        $this->assertSame('private-staff.orders', $event->broadcastOn()[0]->name);
        $this->assertSame('order.updated', $event->broadcastAs());

        $payload = $event->broadcastWith()['order'];
        $this->assertSame($order->order_code, $payload['order_code']);
        $this->assertSame('preparing', $payload['status']);
        $this->assertSame('ready', $payload['next_status']);
        $this->assertSame(6, $payload['table_number']);
        $this->assertSame('Mie Suit', $payload['items'][0]['menu_name']);
    }

    public function test_customer_sees_new_status_on_public_order_endpoint(): void
    {
        $this->actAs(UserRole::Kitchen);
        $order = $this->makeOrder();

        $this->setStatus($order, 'preparing')->assertOk();

        $this->getJson("/api/public/tables/{$order->diningTable->qr_token}/orders/{$order->order_code}")
            ->assertOk()
            ->assertJsonPath('data.status', 'preparing')
            ->assertJsonPath('data.status_label', 'Sedang dimasak');
    }
}