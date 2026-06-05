<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $cashierUser;
    protected Product $activeProduct;
    protected Product $inactiveProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::create([
            'name' => 'Admin POS',
            'username' => 'admin_pos',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->adminUser->assignRole('admin');

        $this->cashierUser = User::create([
            'name' => 'Cashier Budi',
            'username' => 'cashier_budi',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->cashierUser->assignRole('kasir');

        $this->activeProduct = Product::create([
            'nama' => 'Kopi Sachet',
            'merek' => 'Kapal Api',
            'barcode' => '8991234560012',
            'stok' => 40,
            'harga' => 1500,
            'status' => 'active',
        ]);

        $this->inactiveProduct = Product::create([
            'nama' => 'Susu Kaleng',
            'merek' => 'Bear Brand',
            'barcode' => '8991234560050',
            'stok' => 20,
            'harga' => 9500,
            'status' => 'inactive',
        ]);
    }

    public function test_authenticated_user_can_lookup_active_product_by_barcode(): void
    {
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson("/api/v1/products/barcode/{$this->activeProduct->barcode}");

        $response->assertStatus(200)
            ->assertJsonPath('nama', 'Kopi Sachet');
    }

    public function test_cashier_cannot_lookup_inactive_product_by_barcode(): void
    {
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson("/api/v1/products/barcode/{$this->inactiveProduct->barcode}");

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Produk tidak aktif.');
    }

    public function test_admin_can_lookup_inactive_product_by_barcode(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/v1/products/barcode/{$this->inactiveProduct->barcode}");

        $response->assertStatus(200)
            ->assertJsonPath('nama', 'Susu Kaleng');
    }

    public function test_lookup_missing_barcode_returns_404(): void
    {
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson("/api/v1/products/barcode/9999999999999");

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Produk dengan barcode tersebut tidak ditemukan.');
    }

    public function test_admin_can_change_product_status(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/products/{$this->activeProduct->id}/status", [
                'status' => 'inactive',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'inactive');

        $this->assertEquals('inactive', $this->activeProduct->fresh()->status);
    }

    public function test_cashier_cannot_change_product_status(): void
    {
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->patchJson("/api/v1/products/{$this->activeProduct->id}/status", [
                'status' => 'inactive',
            ]);

        $response->assertStatus(403);
    }
}
