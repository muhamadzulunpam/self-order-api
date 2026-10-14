<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Menu;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateOrderTest extends TestCase
{
    use RefreshDatabase;

    private function ordersUrl(DiningTable $table): string
    {
        return "/api/public/tables/{$table->qr_token}/orders";
    }

    public function test_total_is_calculated_on_server_and_client_price_is_ignored(): void
    {
        $table = DiningTable::factory()->create(['number' => 7]);
        $mie = Menu::factory()->create(['price' => 12000]);
        $teh = Menu::factory()->create(['price' => 5000]);

        $this->postJson($this->ordersUrl($table), [
            'total_amount' => 1,
            'items' => [
                ['menu_id' => $mie->id, 'quantity' => 2, 'price' => 1],
                ['menu_id' => $teh->id, 'quantity' => 3, 'price' => 1],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', 39000)
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.table_number', 7)
            ->assertJsonCount(2, 'data.items');

        $this->assertDatabaseHas('orders', ['dining_table_id' => $table->id, 'total_amount' => 39000]);
        $this->assertDatabaseHas('order_items', [
            'menu_id' => $mie->id,
            'price' => 12000,
            'quantity' => 2,
            'subtotal' => 24000,
        ]);
    }

    public function test_order_item_stores_menu_name_and_price_snapshot(): void
    {
        $table = DiningTable::factory()->create();
        $menu = Menu::factory()->create(['name' => 'Mie Suit', 'price' => 12000]);

        $this->postJson($this->ordersUrl($table), [
            'items' => [['menu_id' => $menu->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertDatabaseHas('order_items', ['menu_name' => 'Mie Suit', 'price' => 12000]);
    }

    public function test_changing_menu_price_does_not_change_existing_order(): void
    {
        $table = DiningTable::factory()->create();
        $menu = Menu::factory()->create(['price' => 12000]);

        $orderCode = $this->postJson($this->ordersUrl($table), [
            'items' => [['menu_id' => $menu->id, 'quantity' => 1]],
        ])->json('data.order_code');

        $menu->update(['price' => 99000]);

        $this->getJson("{$this->ordersUrl($table)}/{$orderCode}")
            ->assertOk()
            ->assertJsonPath('data.total_amount', 12000);
    }

    public function test_unavailable_menu_rejects_whole_order(): void
    {
        $table = DiningTable::factory()->create();
        $available = Menu::factory()->create();
        $unavailable = Menu::factory()->create(['is_available' => false]);

        $this->postJson($this->ordersUrl($table), [
            'items' => [
                ['menu_id' => $available->id, 'quantity' => 1],
                ['menu_id' => $unavailable->id, 'quantity' => 1],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.1.menu_id']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_nonexistent_menu_is_rejected(): void
    {
        $table = DiningTable::factory()->create();

        $this->postJson($this->ordersUrl($table), [
            'items' => [['menu_id' => 9999, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.menu_id']);
    }

    public function test_items_cannot_be_empty(): void
    {
        $table = DiningTable::factory()->create();

        $this->postJson($this->ordersUrl($table), ['items' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_quantity_must_be_at_least_one(): void
    {
        $table = DiningTable::factory()->create();
        $menu = Menu::factory()->create();

        $this->postJson($this->ordersUrl($table), [
            'items' => [['menu_id' => $menu->id, 'quantity' => 0]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_spice_level_is_required_for_menu_with_spice_level(): void
    {
        $table = DiningTable::factory()->create();
        $menu = Menu::factory()->create(['has_spice_level' => true]);

        $this->postJson($this->ordersUrl($table), [
            'items' => [['menu_id' => $menu->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.spice_level']);
    }

    public function test_spice_level_is_stored_for_menu_with_spice_level(): void
    {
        $table = DiningTable::factory()->create();
        $menu = Menu::factory()->create(['has_spice_level' => true]);

        $this->postJson($this->ordersUrl($table), [
            'items' => [['menu_id' => $menu->id, 'quantity' => 1, 'spice_level' => 3]],
        ])->assertCreated();

        $this->assertSame(3, OrderItem::first()->spice_level);
    }

    public function test_spice_level_is_ignored_for_menu_without_spice_level(): void
    {
        $table = DiningTable::factory()->create();
        $menu = Menu::factory()->create(['has_spice_level' => false]);

        $this->postJson($this->ordersUrl($table), [
            'items' => [['menu_id' => $menu->id, 'quantity' => 1, 'spice_level' => 3]],
        ])->assertCreated();

        $this->assertNull(OrderItem::first()->spice_level);
    }

    public function test_invalid_table_token_returns_404(): void
    {
        $menu = Menu::factory()->create();

        $this->postJson('/api/public/tables/token-ngawur/orders', [
            'items' => [['menu_id' => $menu->id, 'quantity' => 1]],
        ])->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_inactive_table_cannot_create_order(): void
    {
        $table = DiningTable::factory()->create(['is_active' => false]);
        $menu = Menu::factory()->create();

        $this->postJson($this->ordersUrl($table), [
            'items' => [['menu_id' => $menu->id, 'quantity' => 1]],
        ])->assertNotFound();
    }

    public function test_order_can_be_viewed_with_table_token_and_order_code(): void
    {
        $table = DiningTable::factory()->create();
        $menu = Menu::factory()->create();

        $orderCode = $this->postJson($this->ordersUrl($table), [
            'items' => [['menu_id' => $menu->id, 'quantity' => 1]],
        ])->json('data.order_code');

        $this->getJson("{$this->ordersUrl($table)}/{$orderCode}")
            ->assertOk()
            ->assertJsonPath('data.order_code', $orderCode);
    }

    public function test_order_cannot_be_viewed_from_another_table(): void
    {
        $table = DiningTable::factory()->create();
        $otherTable = DiningTable::factory()->create();
        $menu = Menu::factory()->create();

        $orderCode = $this->postJson($this->ordersUrl($table), [
            'items' => [['menu_id' => $menu->id, 'quantity' => 1]],
        ])->json('data.order_code');

        $this->getJson("{$this->ordersUrl($otherTable)}/{$orderCode}")
            ->assertNotFound();
    }
}