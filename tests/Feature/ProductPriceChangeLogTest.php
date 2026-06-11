<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPriceLog;
use App\Models\StockReceiving;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductPriceChangeLogTest extends TestCase
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
            'harga_jual' => 12000,
            'margin' => 20.00,
        ]);
    }

    public function test_manual_price_change_logs_correctly(): void
    {
        $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/products/{$this->product->id}", [
                'harga_beli' => 11000,
                'harga_jual' => 13200,
            ]);

        $this->assertDatabaseHas('product_price_logs', [
            'product_id' => $this->product->id,
            'harga_beli_lama' => 10000,
            'harga_beli_baru' => 11000,
            'harga_jual_lama' => 12000,
            'harga_jual_baru' => 13200,
            'sumber' => 'manual',
            'user_id' => $this->managerUser->id,
        ]);
    }

    public function test_receiving_finalization_price_change_logs_correctly(): void
    {
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/receiving', [
                'supplier' => 'PT Test Supplier',
            ]);

        $response->assertStatus(201);
        $receivingId = $response->json('data.id');

        $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/receiving/{$receivingId}/items", [
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 10,
                        'harga_beli' => 12000,
                        'update_harga_jual' => true,
                        'harga_jual_baru' => 15000,
                    ]
                ]
            ])->assertStatus(200);

        $this->actingAs($this->managerUser, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receivingId}/complete")
            ->assertStatus(200);

        $this->assertDatabaseHas('product_price_logs', [
            'product_id' => $this->product->id,
            'harga_beli_lama' => 10000,
            'harga_beli_baru' => 12000,
            'harga_jual_lama' => 12000,
            'harga_jual_baru' => 15000,
            'sumber' => 'receiving',
            'referensi_id' => $receivingId,
            'user_id' => $this->managerUser->id,
        ]);
    }

    public function test_price_logs_retrieval_endpoints(): void
    {
        // Generate manual price log
        $this->product->update([
            'harga_beli' => 11000,
            'harga_jual' => 14000,
        ]);

        $response1 = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson('/api/v1/products/price-logs');

        $response1->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'product_id',
                        'harga_beli_lama',
                        'harga_beli_baru',
                        'harga_jual_lama',
                        'harga_jual_baru',
                        'sumber',
                    ]
                ]
            ]);

        $response2 = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson("/api/v1/products/{$this->product->id}/price-logs");

        $response2->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}
