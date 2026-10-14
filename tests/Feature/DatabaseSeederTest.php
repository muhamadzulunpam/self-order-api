<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    private function counts(): array
    {
        return [
            User::count(),
            Category::count(),
            Menu::count(),
            DiningTable::count(),
        ];
    }

    public function test_seeder_creates_demo_data(): void
    {
        $this->seed();

        foreach (UserRole::cases() as $role) {
            $this->assertSame(1, User::where('role', $role->value)->count());
        }

        $this->assertSame(3, Category::count());
        $this->assertSame(9, Menu::count());
        $this->assertSame(1, Menu::where('is_available', false)->count());
        $this->assertSame(10, DiningTable::count());
    }

    public function test_seeder_can_run_twice_without_duplicates(): void
    {
        $this->seed();
        $first = $this->counts();

        $this->seed();

        $this->assertSame($first, $this->counts());
    }

    public function test_seeded_data_works_with_public_menu_endpoint(): void
    {
        $this->seed();
        $table = DiningTable::where('number', 1)->firstOrFail();

        $response = $this->getJson("/api/public/tables/{$table->qr_token}/menu")
            ->assertOk()
            ->assertJsonCount(3, 'data.categories');

        $availableMenus = collect($response->json('data.categories'))
            ->sum(fn ($category) => count($category['menus']));

        $this->assertSame(8, $availableMenus);
    }
}
