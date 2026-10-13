<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryMenuTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_guest_cannot_access_admin_endpoints(): void
    {
        $this->getJson('/api/admin/categories')->assertUnauthorized();
    }

    public function test_cashier_cannot_access_admin_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/categories')->assertForbidden();
    }

    public function test_admin_can_create_category(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/admin/categories', ['name' => 'Mie Pedas'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'mie-pedas');
    }

    public function test_admin_can_create_menu_with_image(): void
    {
        Storage::fake('public');
        $this->actAsAdmin();
        $category = Category::factory()->create();

        $this->postJson('/api/admin/menus', [
            'category_id' => $category->id,
            'name' => 'Mie Suit',
            'price' => 12000,
            'has_spice_level' => 1,
            'is_available' => 1,
            'image' => UploadedFile::fake()->image('mie.jpg'),
        ])->assertCreated();

        Storage::disk('public')->assertExists(Menu::first()->image);
    }

    public function test_menu_price_cannot_be_negative(): void
    {
        $this->actAsAdmin();
        $category = Category::factory()->create();

        $this->postJson('/api/admin/menus', [
            'category_id' => $category->id,
            'name' => 'Menu Aneh',
            'price' => -1,
        ])->assertUnprocessable();
    }

    public function test_updating_image_deletes_the_old_one(): void
    {
        Storage::fake('public');
        $this->actAsAdmin();
        $menu = Menu::factory()->create([
            'image' => UploadedFile::fake()->image('lama.jpg')->store('menus', 'public'),
        ]);
        $oldImage = $menu->image;

        $this->postJson("/api/admin/menus/{$menu->id}", [
            '_method' => 'PUT',
            'category_id' => $menu->category_id,
            'name' => $menu->name,
            'price' => $menu->price,
            'image' => UploadedFile::fake()->image('baru.jpg'),
        ])->assertOk();

        Storage::disk('public')->assertMissing($oldImage);
        Storage::disk('public')->assertExists($menu->fresh()->image);
    }

    public function test_category_with_menus_cannot_be_deleted(): void
    {
        $this->actAsAdmin();
        $menu = Menu::factory()->create();

        $this->deleteJson("/api/admin/categories/{$menu->category_id}")
            ->assertStatus(409);
    }

    public function test_ordered_menu_cannot_be_deleted(): void
    {
        $this->actAsAdmin();
        $menu = Menu::factory()->create();
        $table = DiningTable::create(['number' => 1]);
        $order = Order::create([
            'order_code' => 'ORD-001',
            'dining_table_id' => $table->id,
            'total_amount' => $menu->price,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'menu_id' => $menu->id,
            'menu_name' => $menu->name,
            'price' => $menu->price,
            'quantity' => 1,
            'subtotal' => $menu->price,
        ]);

        $this->deleteJson("/api/admin/menus/{$menu->id}")->assertStatus(409);
    }
}