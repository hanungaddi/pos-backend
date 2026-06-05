<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            UserSeeder::class,
        ]);

        collect([
            ['nama' => 'Kopi Sachet', 'merek' => 'Kapal Api', 'barcode' => '8991234560012', 'stok' => 40, 'harga' => 1500],
            ['nama' => 'Mi Instan Goreng', 'merek' => 'Indomie', 'barcode' => '8991234560029', 'stok' => 30, 'harga' => 3500],
            ['nama' => 'Air Mineral 600ml', 'merek' => 'Aqua', 'barcode' => '8991234560036', 'stok' => 24, 'harga' => 4000],
        ])->each(function (array $product): void {
            Product::query()->updateOrCreate([
                'nama' => $product['nama'],
                'merek' => $product['merek'],
            ], [
                'barcode' => $product['barcode'],
                'stok' => $product['stok'],
                'harga' => $product['harga'],
            ]);
        });
    }
}
