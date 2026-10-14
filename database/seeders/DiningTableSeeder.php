<?php

namespace Database\Seeders;

use App\Models\DiningTable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DiningTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (range(1, 10) as $number) {
            DiningTable::firstOrCreate(
                ['number' => $number],
                ['qr_token' => (string) Str::uuid()]
            );
        }
    }
}