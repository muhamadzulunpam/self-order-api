<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Enums\UserRole;

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
