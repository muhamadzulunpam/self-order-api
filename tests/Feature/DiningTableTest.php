<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DiningTableTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_cashier_cannot_manage_tables(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/dining-tables')->assertForbidden();
    }

    public function test_admin_can_create_table_and_token_is_generated(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/admin/dining-tables', ['number' => 5])
            ->assertCreated()
            ->assertJsonPath('data.number', 5)
            ->assertJsonPath('data.is_active', true);

        $this->assertSame(40, strlen(DiningTable::first()->qr_token));
    }

    public function test_table_number_must_be_unique(): void
    {
        $this->actAsAdmin();
        DiningTable::factory()->create(['number' => 3]);

        $this->postJson('/api/admin/dining-tables', ['number' => 3])
            ->assertUnprocessable();
    }

    public function test_admin_can_update_table_without_changing_token(): void
    {
        $this->actAsAdmin();
        $table = DiningTable::factory()->create(['number' => 1]);
        $oldToken = $table->qr_token;

        $this->putJson("/api/admin/dining-tables/{$table->id}", [
            'number' => 1,
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertSame($oldToken, $table->fresh()->qr_token);
    }

    public function test_regenerate_token_changes_token(): void
    {
        $this->actAsAdmin();
        $table = DiningTable::factory()->create();
        $oldToken = $table->qr_token;

        $this->postJson("/api/admin/dining-tables/{$table->id}/regenerate-token")
            ->assertOk();

        $this->assertNotSame($oldToken, $table->fresh()->qr_token);
    }

    public function test_qr_endpoint_returns_svg(): void
    {
        $this->actAsAdmin();
        $table = DiningTable::factory()->create();

        $response = $this->get("/api/admin/dining-tables/{$table->id}/qr");

        $response->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $response->getContent());
    }

    public function test_table_with_orders_cannot_be_deleted(): void
    {
        $this->actAsAdmin();
        $table = DiningTable::factory()->create();
        Order::create([
            'order_code' => 'ORD-001',
            'dining_table_id' => $table->id,
            'total_amount' => 10000,
        ]);

        $this->deleteJson("/api/admin/dining-tables/{$table->id}")
            ->assertStatus(409);
    }

    public function test_table_without_orders_can_be_deleted(): void
    {
        $this->actAsAdmin();
        $table = DiningTable::factory()->create();

        $this->deleteJson("/api/admin/dining-tables/{$table->id}")->assertOk();

        $this->assertModelMissing($table);
    }
}