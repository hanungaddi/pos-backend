<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockReceiving;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductPricingTest extends TestCase
{
    use RefreshDatabase;

    protected User $managerUser;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->managerUser = User::create([
            'name' => 'Manager Store',
            'username' => 'manager_store',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->managerUser->assignRole('manajer_toko');

        $this->product = Product::create([
            'nama' => 'Beras Pandan Wangi 5kg',
            'merek' => 'Cianjur',
            'harga_beli' => 10000,
            'harga_jual' => 12000, // 20% margin
        ]);
    }

    public function test_product_creation_ignores_stock_and_defaults_to_zero(): void
    {
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/products', [
                'nama' => 'Teh Manis',
                'merek' => 'Sosro',
                'stok' => 999, // Should be ignored
                'harga_jual' => 5000,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.nama', 'Teh Manis')
            ->assertJsonPath('data.stok', 0); // Defaults to 0
    }

    public function test_product_auto_calculates_margin_and_selling_price(): void
    {
        // Case 1: Provided harga_beli and harga_jual -> calculates margin
        $p1 = Product::create([
            'nama' => 'Product A',
            'harga_beli' => 10000,
            'harga_jual' => 12500,
        ]);
        $this->assertEquals(25.00, $p1->margin);

        // Case 2: Provided harga_beli and margin -> calculates harga_jual
        $p2 = Product::create([
            'nama' => 'Product B',
            'harga_beli' => 10000,
            'margin' => 15.00,
        ]);
        $this->assertEquals(11500, $p2->harga_jual);

        // Case 3: Update margin -> updates harga_jual accordingly
        $p1->update(['margin' => 50.00]);
        $this->assertEquals(15000, $p1->fresh()->harga_jual);
    }

    public function test_compare_prices_endpoint(): void
    {
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/inventory/receiving/compare-prices', [
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'harga_beli' => 11000, // Cost increased from 10k to 11k
                    ]
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.0.nama', 'Beras Pandan Wangi 5kg')
            ->assertJsonPath('data.0.harga_beli_lama', 10000)
            ->assertJsonPath('data.0.harga_beli_baru', 11000)
            ->assertJsonPath('data.0.harga_jual_lama', 12000)
            ->assertJsonPath('data.0.margin_lama', 20)
            ->assertJsonPath('data.0.harga_jual_saran', 13200) // 11000 * 1.20
            ->assertJsonPath('data.0.selisih_harga_beli', 1000)
            ->assertJsonPath('data.0.perlu_alert', true);
    }

    public function test_receiving_completion_updates_product_prices(): void
    {
        // Case 1: Update selling price is FALSE (margin compresses)
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/inventory/receiving', [
                'supplier' => 'PT Test Supplier',
                'status' => 'completed',
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 10,
                        'harga_beli' => 11000,
                        'update_harga_jual' => false,
                    ]
                ]
            ]);

        $response->assertStatus(201);
        $productFresh = $this->product->fresh();
        $this->assertEquals(11000, $productFresh->harga_beli);
        $this->assertEquals(12000, $productFresh->harga_jual); // Unchanged
        $this->assertEquals(9.09, $productFresh->margin); // Compressed margin: ((12000-11000)/11000)*100

        // Case 2: Update selling price is TRUE with no custom price (uses existing margin)
        // Set product back to 20% margin first
        $this->product->refresh();
        $this->product->update(['margin' => 20.00, 'harga_beli' => 10000, 'harga_jual' => 12000]);

        $response2 = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/inventory/receiving', [
                'supplier' => 'PT Test Supplier',
                'status' => 'completed',
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 10,
                        'harga_beli' => 11000,
                        'update_harga_jual' => true,
                    ]
                ]
            ]);

        $response2->assertStatus(201);
        $productFresh2 = $this->product->fresh();
        $this->assertEquals(11000, $productFresh2->harga_beli);
        $this->assertEquals(13200, $productFresh2->harga_jual); // 11000 * 1.20
        $this->assertEquals(20.00, $productFresh2->margin); // Preserved margin

        // Case 3: Update selling price is TRUE with a custom new selling price
        $response3 = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/inventory/receiving', [
                'supplier' => 'PT Test Supplier',
                'status' => 'completed',
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 10,
                        'harga_beli' => 11000,
                        'update_harga_jual' => true,
                        'harga_jual_baru' => 13500,
                    ]
                ]
            ]);

        $response3->assertStatus(201);
        $productFresh3 = $this->product->fresh();
        $this->assertEquals(11000, $productFresh3->harga_beli);
        $this->assertEquals(13500, $productFresh3->harga_jual); // Custom selling price
        $this->assertEquals(22.73, $productFresh3->margin); // ((13500-11000)/11000)*100
    }
}
