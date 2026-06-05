<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Admin User
        $admin = User::updateOrCreate([
            'username' => 'admin_pos',
        ], [
            'name' => 'Admin POS',
            'email' => 'admin@pos.com',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $admin->syncRoles(['admin']);

        // Create Cashier User
        $cashier = User::updateOrCreate([
            'username' => 'cashier_budi',
        ], [
            'name' => 'Cashier Budi',
            'email' => 'budi@pos.com',
            'password' => Hash::make('password'),
            'status' => 'active',
            'store_id' => 1, // Assume default store id is 1
        ]);
        $cashier->syncRoles(['kasir']);

        // Create Manager User
        $manager = User::updateOrCreate([
            'username' => 'manager_store',
        ], [
            'name' => 'Manager Store',
            'email' => 'manager@pos.com',
            'password' => Hash::make('password'),
            'status' => 'active',
            'store_id' => 1,
        ]);
        $manager->syncRoles(['manajer_toko']);

        // Create Supervisor User
        $supervisor = User::updateOrCreate([
            'username' => 'spv_pos',
        ], [
            'name' => 'Supervisor POS',
            'email' => 'supervisor@pos.com',
            'password' => Hash::make('password'),
            'status' => 'active',
            'store_id' => 1,
        ]);
        $supervisor->syncRoles(['supervisor']);
    }
}
