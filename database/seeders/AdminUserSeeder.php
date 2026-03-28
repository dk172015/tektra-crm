<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@tektra.local'],
            [
                'name' => 'Admin Tektra',
                'password' => Hash::make('12345678'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'sale1@tektra.local'],
            [
                'name' => 'Sale 1',
                'password' => Hash::make('12345678'),
                'role' => 'sale',
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'sale2@tektra.local'],
            [
                'name' => 'Sale 2',
                'password' => Hash::make('12345678'),
                'role' => 'sale',
                'is_active' => true,
            ]
        );
    }
}