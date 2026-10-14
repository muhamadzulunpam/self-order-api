<?php

// tests/Feature/EndToEndOrderFlowTest.php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Events\OrderPaid;
use App\Events\OrderStatusUpdated;
use App\Events\StaffOrderUpdated;
use App\Models\DiningTable;
use App\Models\Menu;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EndToEndOrderFlowTest extends TestCase
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

        Event::fake([OrderPaid::class, StaffOrderUpdated::class, OrderStatusUpdated::class]);

        Http::fake([
            'app.sandbox.midtrans.com/*' => Http::response([
                'token' => 'snap-token-e2e',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-e2e',
            ], 201),
        ]);
    }

    // ---------- Helper ----------

    /** Hapus token dan cache guard supaya request berikutnya benar-benar sebagai tamu. */
    private function asGuest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }

    /** Ganti identitas request berikutnya ke pemilik token tertentu. */
    private function useToken(string $token): void
    {
        $this->asGuest();
        $this->withToken($token);
    }

    /** Login lewat endpoint sungguhan, bukan actingAs. */
    private function tokenFor(UserRole $role): string
    {
        $user = User::factory()->create(['role' => $role]);

        $this->asGuest();

        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');
    }

    private function midtransPayload(
        string $orderCode,
        int $amount,
        string $status = 'settlement',
        string $statusCode = '200'
    ): array {
        $grossAmount = number_format($amount, 2, '.', '');

        return [
            'order_id' => $orderCode,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'transaction_status' => $status,
            'transaction_id' => 'trx-e2e',
            'payment_type' => 'qris',
            'fraud_status' => 'accept',
            'signature_key' => hash('sha512', $orderCode.$statusCode.$grossAmount.'SB-Mid-server-test'),
        ];
    }

    // ---------- Test ----------

    public function test_full_flow_from_menu_setup_to_completed_order(): void
    {
        $adminToken = $this->tokenFor(UserRole::Admin);
        $kitchenToken = $this->tokenFor(UserRole::Kitchen);
        $cashierToken = $this->tokenFor(UserRole::Cashier);

        // 1. Admin menyiapkan kategori, menu, dan meja.
        $this->useToken($adminToken);

        $mieCategoryId = $this->postJson('/api/admin/categories', ['name' => 'Mie'])
            ->assertCreated()->json('data.id');
        $drinkCategoryId = $this->postJson('/api/admin/categories', ['name' => 'Minuman'])
            ->assertCreated()->json('data.id');

        $mieId = $this->postJson('/api/admin/menus', [
            'category_id' => $mieCategoryId,
            'name' => 'Mie Pedas Original',
            'price' => 12000,
            'has_spice_level' => 1,
            'is_available' => 1,
        ])->assertCreated()->json('data.id');

        $tehId = $this->postJson('/api/admin/menus', [
            'category_id' => $drinkCategoryId,
            'name' => 'Es Teh Manis',
            'price' => 5000,
            'has_spice_level' => 0,
            'is_available' => 1,
        ])->assertCreated()->json('data.id');

        $tableResponse = $this->postJson('/api/admin/dining-tables', ['number' => 12])
            ->assertCreated();
        $tableId = $tableResponse->json('data.id');
        $token = basename($tableResponse->json('data.order_url'));

        $this->assertSame(DiningTable::findOrFail($tableId)->qr_token, $token);

        $this->get("/api/admin/dining-tables/{$tableId}/qr")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');

        // 2. Pembeli scan QR dan melihat menu (tanpa login).
        $this->asGuest();

        $this->getJson("/api/public/tables/{$token}/menu")
            ->assertOk()
            ->assertJsonPath('data.table.number', 12)
            ->assertJsonCount(2, 'data.categories');

        // 3. Pembeli membuat pesanan, total dihitung server.
        $orderCode = $this->postJson("/api/public/tables/{$token}/orders", [
            'customer_name' => 'Budi',
            'items' => [
                ['menu_id' => $mieId, 'quantity' => 2, 'spice_level' => 3],
                ['menu_id' => $tehId, 'quantity' => 1],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.total_amount', 29000)
            ->json('data.order_code');

        // 4. Pembeli meminta Snap token.
        $this->postJson("/api/public/tables/{$token}/orders/{$orderCode}/pay")
            ->assertOk()
            ->assertJsonPath('data.snap_token', 'snap-token-e2e');

        // 5. Sebelum dibayar, pesanan belum muncul di dapur.
        $this->useToken($kitchenToken);
        $this->getJson('/api/staff/orders')->assertOk()->assertJsonCount(0, 'data');

        // 6. Midtrans mengirim webhook pembayaran sukses.
        $this->asGuest();
        $this->postJson('/api/webhooks/midtrans', $this->midtransPayload($orderCode, 29000))
            ->assertOk();

        $this->getJson("/api/public/tables/{$token}/orders/{$orderCode}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_status', 'success');

        // 7. Dapur melihat pesanan dan memprosesnya.
        $this->useToken($kitchenToken);

        $list = $this->getJson('/api/staff/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_code', $orderCode)
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.table_number', 12)
            ->assertJsonPath('data.0.items.0.spice_level', 3);
        $orderId = $list->json('data.0.id');

        $this->patchJson("/api/staff/orders/{$orderId}/status", ['status' => 'completed'])
            ->assertForbidden(); // dapur tidak berwenang menyelesaikan pesanan

        $this->patchJson("/api/staff/orders/{$orderId}/status", ['status' => 'preparing'])
            ->assertOk()->assertJsonPath('data.status_label', 'Sedang dimasak');
        $this->patchJson("/api/staff/orders/{$orderId}/status", ['status' => 'ready'])
            ->assertOk()->assertJsonPath('data.status_label', 'Siap disajikan');

        // 8. Kasir menyelesaikan pesanan.
        $this->useToken($cashierToken);

        $this->patchJson("/api/staff/orders/{$orderId}/status", ['status' => 'completed'])
            ->assertOk()->assertJsonPath('data.status', 'completed');
        $this->getJson('/api/staff/orders')->assertOk()->assertJsonCount(0, 'data');

        // 9. Pembeli melihat status akhir.
        $this->asGuest();
        $this->getJson("/api/public/tables/{$token}/orders/{$orderCode}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.status_label', 'Selesai');

        // Keadaan akhir di database.
        $order = Order::where('order_code', $orderCode)->firstOrFail();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(PaymentStatus::Success, $order->payment->status);
        $this->assertSame(29000, $order->payment->gross_amount);

        // Event real-time: 1x pesanan masuk dapur, 4x perubahan status ke pembeli.
        Event::assertDispatchedTimes(OrderPaid::class, 1);
        Event::assertDispatchedTimes(StaffOrderUpdated::class, 3);
        Event::assertDispatchedTimes(OrderStatusUpdated::class, 4);
    }

    public function test_unpaid_order_never_reaches_the_kitchen(): void
    {
        $kitchenToken = $this->tokenFor(UserRole::Kitchen);
        $table = DiningTable::factory()->create();
        $menu = Menu::factory()->create(['price' => 10000]);

        $this->asGuest();

        $orderCode = $this->postJson("/api/public/tables/{$table->qr_token}/orders", [
            'items' => [['menu_id' => $menu->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.order_code');

        $this->postJson("/api/public/tables/{$table->qr_token}/orders/{$orderCode}/pay")->assertOk();

        // Pembayaran kedaluwarsa: pesanan dibatalkan.
        $this->postJson(
            '/api/webhooks/midtrans',
            $this->midtransPayload($orderCode, 10000, 'expire', '407')
        )->assertOk();

        $this->getJson("/api/public/tables/{$table->qr_token}/orders/{$orderCode}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment_status', 'expired');

        $this->useToken($kitchenToken);
        $this->getJson('/api/staff/orders')->assertOk()->assertJsonCount(0, 'data');

        $orderId = Order::where('order_code', $orderCode)->value('id');
        $this->patchJson("/api/staff/orders/{$orderId}/status", ['status' => 'preparing'])
            ->assertStatus(409);

        Event::assertNotDispatched(OrderPaid::class);
        Event::assertNotDispatched(StaffOrderUpdated::class);
    }
}
