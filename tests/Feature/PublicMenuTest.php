<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_see_menu_with_valid_token(): void
    {
        $table = DiningTable::factory()->create(['number' => 5]);
        $category = Category::factory()->create(['name' => 'Mie']);
        Menu::factory()->create(['category_id' => $category->id, 'name' => 'Mie Suit']);

        $this->getJson("/api/public/tables/{$table->qr_token}/menu")
            ->assertOk()
            ->assertJsonPath('data.table.number', 5)
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonPath('data.categories.0.name', 'Mie')
            ->assertJsonPath('data.categories.0.menus.0.name', 'Mie Suit');
    }

    public function test_unavailable_menus_are_hidden(): void
    {
        $table = DiningTable::factory()->create();
        $category = Category::factory()->create();
        Menu::factory()->create(['category_id' => $category->id, 'name' => 'Ada']);
        Menu::factory()->create([
            'category_id' => $category->id,
            'name' => 'Habis',
            'is_available' => false,
        ]);

        $this->getJson("/api/public/tables/{$table->qr_token}/menu")
            ->assertOk()
            ->assertJsonCount(1, 'data.categories.0.menus')
            ->assertJsonMissing(['name' => 'Habis']);
    }

    public function test_category_without_available_menu_is_hidden(): void
    {
        $table = DiningTable::factory()->create();
        $category = Category::factory()->create();
        Menu::factory()->create([
            'category_id' => $category->id,
            'is_available' => false,
        ]);

        $this->getJson("/api/public/tables/{$table->qr_token}/menu")
            ->assertOk()
            ->assertJsonCount(0, 'data.categories');
    }

    public function test_categories_are_sorted_by_sort_order(): void
    {
        $table = DiningTable::factory()->create();
        $second = Category::factory()->create(['name' => 'Minuman', 'sort_order' => 2]);
        $first = Category::factory()->create(['name' => 'Mie', 'sort_order' => 1]);
        Menu::factory()->create(['category_id' => $second->id]);
        Menu::factory()->create(['category_id' => $first->id]);

        $this->getJson("/api/public/tables/{$table->qr_token}/menu")
            ->assertOk()
            ->assertJsonPath('data.categories.0.name', 'Mie')
            ->assertJsonPath('data.categories.1.name', 'Minuman');
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->getJson('/api/public/tables/token-ngawur/menu')
            ->assertNotFound()
            ->assertJsonPath('message', 'Meja tidak ditemukan atau tidak aktif.');
    }

    public function test_inactive_table_returns_404(): void
    {
        $table = DiningTable::factory()->create(['is_active' => false]);

        $this->getJson("/api/public/tables/{$table->qr_token}/menu")
            ->assertNotFound();
    }

    public function test_response_does_not_leak_internal_fields(): void
    {
        $table = DiningTable::factory()->create();
        Menu::factory()->create();

        $response = $this->getJson("/api/public/tables/{$table->qr_token}/menu")->assertOk();

        $response->assertJsonMissing(['qr_token' => $table->qr_token]);
        $this->assertStringNotContainsString('is_available', $response->getContent());
        $this->assertStringNotContainsString('category_id', $response->getContent());
    }
}
