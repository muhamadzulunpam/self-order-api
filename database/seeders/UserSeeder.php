<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            ['name' => 'Admin', 'email' => 'admin@example.com', 'role' => UserRole::Admin],
            ['name' => 'Kasir', 'email' => 'kasir@example.com', 'role' => UserRole::Cashier],
            ['name' => 'Dapur', 'email' => 'dapur@example.com', 'role' => UserRole::Kitchen],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [...$user, 'password' => 'password'] // dihash otomatis oleh cast
            );
        }
    }
}
