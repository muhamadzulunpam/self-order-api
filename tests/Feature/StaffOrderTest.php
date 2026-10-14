<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\DiningTable;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffOrderTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(string $code, OrderStatus $status, ?Carbon $paidAt = null): Order
    {
        $table = DiningTable::factory()->create();
        $menu = Menu::factory()->create(['price' => 10000]);

        $order = Order::create([
            'order_code' => $code,
            'dining_table_id' => $table->id,
            'status' => $status,
            'total_amount' => 10000,
        ]);

        $order->items()->create([
            'menu_id' => $menu->id,
            'menu_name' => $menu->name,
            'price' => 10000,
            'quantity' => 1,
            'subtotal' => 10000,
        ]);

        if ($paidAt) {
            Payment::create([
                'order_id' => $order->id,
                'gross_amount' => 10000,
                'status' => PaymentStatus::Success,
                'paid_at' => $paidAt,
            ]);
        }

        return $order;
    }

    public function test_guest_cannot_list_orders(): void
    {
        $this->getJson('/api/staff/orders')->assertUnauthorized();
    }

    public function test_all_staff_roles_can_list_orders(): void
    {
        foreach ([UserRole::Admin, UserRole::Cashier, UserRole::Kitchen] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));

            $this->getJson('/api/staff/orders')->assertOk();
        }
    }

    public function test_default_list_only_contains_active_orders(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Kitchen]));

        $this->makeOrder('ORD-A', OrderStatus::Paid, now());
        $this->makeOrder('ORD-B', OrderStatus::Preparing, now());
        $this->makeOrder('ORD-C', OrderStatus::Ready, now());
        $this->makeOrder('ORD-D', OrderStatus::PendingPayment);
        $this->makeOrder('ORD-E', OrderStatus::Cancelled);
        $this->makeOrder('ORD-F', OrderStatus::Completed, now());

        $response = $this->getJson('/api/staff/orders')->assertOk();

        $response->assertJsonCount(3, 'data');
        $this->assertEqualsCanonicalizing(
            ['ORD-A', 'ORD-B', 'ORD-C'],
            collect($response->json('data'))->pluck('order_code')->all()
        );
    }

    public function test_status_filter_can_be_customized(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Cashier]));

        $this->makeOrder('ORD-A', OrderStatus::Paid, now());
        $this->makeOrder('ORD-F', OrderStatus::Completed, now());

        $this->getJson('/api/staff/orders?status[]=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_code', 'ORD-F');
    }

    public function test_invalid_status_filter_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Kitchen]));

        $this->getJson('/api/staff/orders?status[]=ngawur')->assertUnprocessable();
    }

    public function test_orders_are_sorted_by_payment_time(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Kitchen]));

        // Dibuat lebih dulu, tetapi dibayar belakangan.
        $this->makeOrder('ORD-LAMBAT-BAYAR', OrderStatus::Paid, now()->subMinutes(2));
        $this->makeOrder('ORD-CEPAT-BAYAR', OrderStatus::Paid, now()->subMinutes(10));

        $this->getJson('/api/staff/orders')
            ->assertOk()
            ->assertJsonPath('data.0.order_code', 'ORD-CEPAT-BAYAR')
            ->assertJsonPath('data.1.order_code', 'ORD-LAMBAT-BAYAR');
    }

    public function test_response_contains_items_and_table_number(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Kitchen]));
        $this->makeOrder('ORD-A', OrderStatus::Paid, now());

        $this->getJson('/api/staff/orders')
            ->assertOk()
            ->assertJsonStructure(['data' => [[
                'order_code', 'status', 'table_number', 'total_amount', 'paid_at',
                'items' => [['menu_name', 'quantity', 'spice_level', 'notes']],
            ]]]);
    }
}
