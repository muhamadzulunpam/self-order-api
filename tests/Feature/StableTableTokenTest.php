<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use Database\Seeders\DiningTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StableTableTokenTest extends TestCase
{
    use RefreshDatabase;

    private function tokens(): array
    {
        return DiningTable::orderBy('number')->pluck('qr_token', 'number')->all();
    }

    public function test_stable_token_is_deterministic_and_differs_per_table(): void
    {
        $token = DiningTable::stableToken(1);

        $this->assertSame($token, DiningTable::stableToken(1));
        $this->assertNotSame($token, DiningTable::stableToken(2));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $token);
    }

    public function test_stable_token_depends_on_app_key(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $first = DiningTable::stableToken(1);

        config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);

        $this->assertNotSame($first, DiningTable::stableToken(1));
    }

    public function test_seeder_creates_tables_with_unique_stable_tokens(): void
    {
        $this->seed(DiningTableSeeder::class);

        $tokens = $this->tokens();

        $this->assertCount(DiningTableSeeder::TABLE_COUNT, $tokens);
        $this->assertCount(DiningTableSeeder::TABLE_COUNT, array_unique($tokens));
        $this->assertSame(DiningTable::stableToken(7), $tokens[7]);
    }

    public function test_reseeding_after_a_fresh_database_gives_the_same_tokens(): void
    {
        $this->seed(DiningTableSeeder::class);
        $before = $this->tokens();

        DiningTable::query()->delete();
        $this->seed(DiningTableSeeder::class);

        $this->assertSame($before, $this->tokens());
    }

    public function test_seeder_does_not_override_existing_tokens(): void
    {
        $this->seed(DiningTableSeeder::class);

        $table = DiningTable::where('number', 3)->firstOrFail();
        $table->regenerateToken();
        $regenerated = $table->fresh()->qr_token;

        $this->seed(DiningTableSeeder::class);

        $this->assertSame($regenerated, $table->fresh()->qr_token);
        $this->assertNotSame(DiningTable::stableToken(3), $regenerated);
        $this->assertSame(DiningTableSeeder::TABLE_COUNT, DiningTable::count());
    }

    public function test_links_command_lists_every_table_url(): void
    {
        config(['app.frontend_url' => 'https://app.contoh.com']);
        $this->seed(DiningTableSeeder::class);

        Artisan::call('tables:links');
        $output = Artisan::output();

        $this->assertStringContainsString(
            'https://app.contoh.com/order/'.DiningTable::stableToken(1),
            $output,
        );
        $this->assertStringContainsString(
            'https://app.contoh.com/order/'.DiningTable::stableToken(20),
            $output,
        );
    }

    public function test_links_command_can_write_a_csv_file(): void
    {
        Storage::fake('local');
        config(['app.frontend_url' => 'https://app.contoh.com']);
        $this->seed(DiningTableSeeder::class);

        Artisan::call('tables:links', ['--csv' => 'links/meja.csv']);

        Storage::disk('local')->assertExists('links/meja.csv');

        $lines = explode("\n", trim(Storage::disk('local')->get('links/meja.csv')));

        $this->assertSame('meja,status,url', $lines[0]);
        $this->assertCount(DiningTableSeeder::TABLE_COUNT + 1, $lines);
        $this->assertSame(
            '1,aktif,https://app.contoh.com/order/'.DiningTable::stableToken(1),
            $lines[1],
        );
    }

    public function test_links_command_warns_when_there_are_no_tables(): void
    {
        Artisan::call('tables:links');

        $this->assertStringContainsString('Belum ada meja', Artisan::output());
    }
}
