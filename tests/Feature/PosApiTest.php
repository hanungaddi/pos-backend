<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_can_be_created(): void
    {
        $response = $this->postJson('/api/products', [
            'nama' => 'Teh Botol',
            'merek' => 'Sosro',
            'stok' => 12,
            'harga' => 5000,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.nama', 'Teh Botol')
            ->assertJsonPath('data.stok', 12)
            ->assertJsonPath('data.harga', 5000);

        $this->assertDatabaseHas('products', [
            'nama' => 'Teh Botol',
            'merek' => 'Sosro',
        ]);
    }

    public function test_product_payload_is_required(): void
    {
        $this->postJson('/api/products')
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
        ]);

        $response = $this->postJson('/api/sales', [
            'cashier_name' => 'Kasir 1',
            'payment_method' => 'cash',
            'discount' => 1000,
            'tax' => 500,
            'paid' => 20000,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.subtotal', 16000)
            ->assertJsonPath('data.discount', 1000)
            ->assertJsonPath('data.tax', 500)
            ->assertJsonPath('data.total', 15500)
            ->assertJsonPath('data.paid', 20000)
            ->assertJsonPath('data.change_amount', 4500)
            ->assertJsonPath('data.items.0.product_name', 'Beras 1kg');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stok' => 8,
        ]);

        $this->assertDatabaseHas('sale_items', [
            'product_id' => $product->id,
            'quantity' => 2,
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
        ]);

        $response = $this->postJson('/api/sales', [
            'paid' => 30000,
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
        ]);

        $this->postJson('/api/sales', [
            'paid' => 10000,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ])->assertCreated();

        $this->getJson('/api/reports/summary')
            ->assertOk()
            ->assertJsonPath('sales_count', 1)
            ->assertJsonPath('items_sold', 2)
            ->assertJsonPath('gross_sales', 8000)
            ->assertJsonPath('net_sales', 8000)
            ->assertJsonPath('top_products.0.product_name', 'Sabun Mandi');
    }
}
