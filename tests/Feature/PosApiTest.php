<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->adminUser = User::create([
            'name' => 'Admin POS',
            'username' => 'admin_pos',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->adminUser->assignRole('admin');
    }

    public function test_product_can_be_created(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/products', [
                'nama' => 'Teh Botol',
                'merek' => 'Sosro',
                'harga' => 5000,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.nama', 'Teh Botol')
            ->assertJsonPath('data.stok', 0)
            ->assertJsonPath('data.harga', 5000);

        $this->assertDatabaseHas('products', [
            'nama' => 'Teh Botol',
            'merek' => 'Sosro',
        ]);
    }

    public function test_product_payload_is_required(): void
    {
        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/products')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nama']);
    }

    public function test_checkout_creates_sale_and_decreases_stock(): void
    {
        $product = Product::create([
            'nama' => 'Beras 1kg',
            'merek' => 'Raja Lele',
            'stok' => 10,
            'harga' => 8000,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'metode_pembayaran' => 'cash',
                'cash_received' => 20000,
                'diskon' => 1000,
                'pajak' => 500,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.subtotal', 16000)
            ->assertJsonPath('data.diskon', 1000)
            ->assertJsonPath('data.pajak', 500)
            ->assertJsonPath('data.total', 15500)
            ->assertJsonPath('data.nominal_bayar', 20000)
            ->assertJsonPath('data.kembalian', 4500)
            ->assertJsonPath('data.items.0.nama_produk', 'Beras 1kg');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stok' => 8,
        ]);

        $this->assertDatabaseHas('transaction_items', [
            'product_id' => $product->id,
            'kuantitas' => 2,
            'subtotal' => 16000,
        ]);
    }

    public function test_checkout_rejects_insufficient_stock(): void
    {
        $product = Product::create([
            'nama' => 'Gula 1kg',
            'merek' => 'Gulaku',
            'stok' => 1,
            'harga' => 15000,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'metode_pembayaran' => 'cash',
                'cash_received' => 30000,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                ],
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stok' => 1,
        ]);
    }

    public function test_sales_summary_returns_basic_metrics(): void
    {
        $product = Product::create([
            'nama' => 'Sabun Mandi',
            'merek' => 'Lifebuoy',
            'stok' => 20,
            'harga' => 4000,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'metode_pembayaran' => 'cash',
                'cash_received' => 10000,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                ],
            ]);

        $response->assertCreated();

        $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.sales_count', 1)
            ->assertJsonPath('data.items_sold', 2)
            ->assertJsonPath('data.gross_sales', 8000)
            ->assertJsonPath('data.net_sales', 8000)
            ->assertJsonPath('data.top_products.0.product_name', 'Sabun Mandi');
    }
}
