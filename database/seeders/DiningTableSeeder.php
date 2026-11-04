<?php

namespace Database\Seeders;

use App\Models\DiningTable;
use Illuminate\Database\Seeder;

class DiningTableSeeder extends Seeder
{
    /** Jumlah meja yang disiapkan. Ubah angka ini jika restoran punya lebih banyak meja. */
    public const TABLE_COUNT = 20;

    public function run(): void
    {
        foreach (range(1, self::TABLE_COUNT) as $number) {
            DiningTable::firstOrCreate(
                ['number' => $number],
                ['qr_token' => DiningTable::stableToken($number)],
            );
        }
    }
}
