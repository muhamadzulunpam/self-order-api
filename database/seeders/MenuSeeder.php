<?php

// database/seeders/MenuSeeder.php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Menu;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        // [nama, deskripsi, harga, punya level pedas, tersedia]
        $catalog = [
            'Mie' => [
                ['Mie Pedas Original', 'Mie dengan bumbu pedas khas dan taburan bawang goreng.', 12000, true, true],
                ['Mie Pedas Keju', 'Mie pedas dengan lelehan keju gurih.', 14000, true, true],
                ['Mie Goreng Spesial', 'Mie goreng dengan telur dan sayuran.', 15000, true, true],
            ],
            'Dimsum' => [
                ['Dimsum Ayam', 'Dimsum ayam kukus isi 4 buah.', 12000, false, true],
                ['Dimsum Udang', 'Dimsum udang kukus isi 4 buah.', 14000, false, true],
                ['Pangsit Goreng', 'Pangsit goreng renyah isi 5 buah.', 10000, false, true],
            ],
            'Minuman' => [
                ['Es Teh Manis', 'Teh manis dingin.', 5000, false, true],
                ['Es Jeruk', 'Jeruk peras dengan es.', 7000, false, true],
                ['Es Lemon Tea', 'Sedang kosong, dipakai untuk demo menu tidak tersedia.', 8000, false, false],
            ],
        ];

        $sortOrder = 1;

        foreach ($catalog as $categoryName => $menus) {
            $category = Category::updateOrCreate(
                ['slug' => Str::slug($categoryName)],
                ['name' => $categoryName, 'sort_order' => $sortOrder++]
            );

            foreach ($menus as [$name, $description, $price, $hasSpiceLevel, $isAvailable]) {
                Menu::updateOrCreate(
                    ['category_id' => $category->id, 'name' => $name],
                    [
                        'description' => $description,
                        'price' => $price,
                        'has_spice_level' => $hasSpiceLevel,
                        'is_available' => $isAvailable,
                    ]
                );
            }
        }
    }
}
