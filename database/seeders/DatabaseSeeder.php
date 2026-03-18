<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create or update the admin user
        User::updateOrCreate(
            ['email' => 'monteiro.brunoo@gmail.com'],
            [
                'name'              => 'Bruno Monteiro',
                'password'          => Hash::make('ClawYard2025!'),
                'role'              => 'admin',
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );

        // Ensure all existing users have a role
        User::whereNull('role')->update(['role' => 'user', 'is_active' => true]);
    }
}
