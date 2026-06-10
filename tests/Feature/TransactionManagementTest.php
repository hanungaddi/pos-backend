<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TransactionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $supervisorUser;
    protected User $cashierUser;
    protected User $otherCashierUser;
    protected Product $product1;
    protected Product $product2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'username' => 'admin_user',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->adminUser->assignRole('admin');

        $this->supervisorUser = User::create([
            'name' => 'Supervisor User',
            'username' => 'spv_user',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->supervisorUser->assignRole('supervisor');

        $this->cashierUser = User::create([
            'name' => 'Cashier User',
            'username' => 'cashier_user',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->cashierUser->assignRole('kasir');

        $this->otherCashierUser = User::create([
            'name' => 'Other Cashier',
            'username' => 'other_cashier',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->otherCashierUser->assignRole('kasir');

        $this->product1 = Product::create([
            'nama' => 'Aqua 600ml',
            'merek' => 'Danone',
            'barcode' => '8990001002',
            'stok' => 50,
            'harga' => 3000,
            'status' => 'active',
        ]);

        $this->product2 = Product::create([
            'nama' => 'Pringles 110g',
            'merek' => 'Kellogg',
            'barcode' => '8990001003',
            'stok' => 10,
            'harga' => 22000,
            'status' => 'active',
        ]);
    }

    public function test_cashier_can_checkout_bulk_cash(): void
    {
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'metode_pembayaran' => 'cash',
                'cash_received' => 50000,
                'diskon' => 1000,
                'pajak' => 500,
                'items' => [
                    ['product_id' => $this->product1->id, 'quantity' => 5], // 15000
                    ['barcode' => '8990001003', 'quantity' => 1], // 22000
                ], // total = 37000 - 1000 + 500 = 36500
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.metode_pembayaran', 'cash')
            ->assertJsonPath('data.subtotal', 37000)
            ->assertJsonPath('data.total', 36500)
            ->assertJsonPath('data.nominal_bayar', 50000)
            ->assertJsonPath('data.kembalian', 13500);

        $this->assertEquals(45, $this->product1->fresh()->stok);
        $this->assertEquals(9, $this->product2->fresh()->stok);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product1->id,
            'tipe' => 'sale',
            'kuantitas' => -5,
            'stok_sebelum' => 50,
            'stok_sesudah' => 45,
            'referensi_tipe' => 'transaction',
        ]);
    }

    public function test_cashier_cannot_checkout_cash_insufficient_payment(): void
    {
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'metode_pembayaran' => 'cash',
                'cash_received' => 10000,
                'items' => [
                    ['product_id' => $this->product1->id, 'quantity' => 5], // 15000
                ],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['cash_received']);

        $this->assertEquals(50, $this->product1->fresh()->stok);
    }

    public function test_cashier_can_checkout_bulk_card(): void
    {
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'metode_pembayaran' => 'card',
                'jenis_kartu' => 'credit',
                'nomor_kartu_akhir' => '4321',
                'referensi_edc' => 'EDC-998877',
                'items' => [
                    ['product_id' => $this->product2->id, 'quantity' => 2], // 44000
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.metode_pembayaran', 'card')
            ->assertJsonPath('data.total', 44000)
            ->assertJsonPath('data.jenis_kartu', 'kredit') // credit maps to kredit
            ->assertJsonPath('data.nomor_kartu_akhir', '4321')
            ->assertJsonPath('data.referensi_edc', 'EDC-998877');

        $this->assertEquals(8, $this->product2->fresh()->stok);
    }

    public function test_cashier_can_checkout_bulk_split(): void
    {
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'metode_pembayaran' => 'split',
                'cash_amount' => 10000,
                'card_amount' => 15000,
                'nominal_bayar' => 20000, // cash received for cash portion
                'jenis_kartu' => 'debit',
                'nomor_kartu_akhir' => '5678',
                'referensi_edc' => 'EDC-665544',
                'items' => [
                    ['product_id' => $this->product2->id, 'quantity' => 1], // 22000
                    ['product_id' => $this->product1->id, 'quantity' => 1], // 3000
                ], // total = 25000
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.metode_pembayaran', 'split')
            ->assertJsonPath('data.total', 25000)
            ->assertJsonPath('data.nominal_bayar', 35000) // nominal_bayar + card_amount = 20000 + 15000
            ->assertJsonPath('data.kembalian', 10000) // nominal_bayar - cash_amount = 20000 - 10000
            ->assertJsonPath('data.jenis_kartu', 'debit')
            ->assertJsonPath('data.nomor_kartu_akhir', '5678')
            ->assertJsonPath('data.referensi_edc', 'EDC-665544');

        $this->assertEquals(9, $this->product2->fresh()->stok);
        $this->assertEquals(49, $this->product1->fresh()->stok);
    }

    public function test_checkout_rejects_insufficient_stock(): void
    {
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'metode_pembayaran' => 'cash',
                'cash_received' => 300000,
                'items' => [
                    ['product_id' => $this->product2->id, 'quantity' => 11], // only 10 in stock
                ],
            ]);

        $response->assertUnprocessable();
        $this->assertEquals(10, $this->product2->fresh()->stok);
    }

    public function test_get_sale_detail(): void
    {
        // 1. Create a completed sale
        $trx = Transaction::create([
            'store_id' => 1,
            'user_id' => $this->cashierUser->id,
            'nomor_transaksi' => 'TRX-TEST-DETAIL',
            'status' => 'completed',
            'subtotal' => 3000,
            'total' => 3000,
            'metode_pembayaran' => 'cash',
        ]);
        $trx->items()->create([
            'product_id' => $this->product1->id,
            'nama_produk' => $this->product1->nama,
            'harga_satuan' => 3000,
            'kuantitas' => 1,
            'subtotal' => 3000,
        ]);

        // 2. Fetch detail
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson("/api/v1/transactions/{$trx->id}");

        $response->assertOk()
            ->assertJsonPath('data.nomor_transaksi', 'TRX-TEST-DETAIL')
            ->assertJsonPath('data.items.0.nama_produk', 'Aqua 600ml');
    }

    public function test_kasir_can_only_see_their_own_transactions_in_history(): void
    {
        // Cashier 1 transaction
        Transaction::create([
            'store_id' => 1,
            'user_id' => $this->cashierUser->id,
            'nomor_transaksi' => 'TRX-CASHIER-1',
            'status' => 'completed',
            'subtotal' => 3000,
            'total' => 3000,
            'metode_pembayaran' => 'cash',
        ]);

        // Cashier 2 transaction
        Transaction::create([
            'store_id' => 1,
            'user_id' => $this->otherCashierUser->id,
            'nomor_transaksi' => 'TRX-CASHIER-2',
            'status' => 'completed',
            'subtotal' => 3000,
            'total' => 3000,
            'metode_pembayaran' => 'cash',
        ]);

        // Cashier 1 checks history (should only see their own)
        $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nomor_transaksi', 'TRX-CASHIER-1');

        // Supervisor checks history (should see both)
        $this->actingAs($this->supervisorUser, 'sanctum')
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_reports_api_endpoints(): void
    {
        // 1. Complete one cash sale (Aqua * 3 = 9000) via unified checkout
        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'metode_pembayaran' => 'cash',
                'cash_received' => 10000,
                'items' => [
                    ['product_id' => $this->product1->id, 'quantity' => 3],
                ],
            ])->assertCreated();

        // 2. Complete one card sale (Pringles * 1 = 22000) via unified checkout
        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'metode_pembayaran' => 'card',
                'items' => [
                    ['product_id' => $this->product2->id, 'quantity' => 1],
                ],
            ])->assertCreated();

        // 3. Call /reports/summary
        $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.sales_count', 2)
            ->assertJsonPath('data.items_sold', 4)
            ->assertJsonPath('data.net_sales', 31000);
    }

    public function test_cashier_can_checkout_bulk_nested_cash(): void
    {
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'metode_pembayaran' => 'cash',
                'cash_details' => [
                    'cash_received' => 50000,
                ],
                'items' => [
                    ['product_id' => $this->product1->id, 'quantity' => 5],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.metode_pembayaran', 'cash')
            ->assertJsonPath('data.nominal_bayar', 50000)
            ->assertJsonPath('data.kembalian', 35000);
    }

    public function test_cashier_can_checkout_bulk_nested_card(): void
    {
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'metode_pembayaran' => 'card',
                'card_details' => [
                    'jenis_kartu' => 'credit',
                    'nomor_kartu_akhir' => '4321',
                    'referensi_edc' => 'EDC-998877',
                ],
                'items' => [
                    ['product_id' => $this->product2->id, 'quantity' => 2],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.metode_pembayaran', 'card')
            ->assertJsonPath('data.jenis_kartu', 'kredit')
            ->assertJsonPath('data.nomor_kartu_akhir', '4321')
            ->assertJsonPath('data.referensi_edc', 'EDC-998877');
    }

    public function test_cashier_can_checkout_bulk_nested_split(): void
    {
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'metode_pembayaran' => 'split',
                'split_details' => [
                    'cash_amount' => 10000,
                    'card_amount' => 15000,
                    'nominal_bayar' => 20000,
                    'jenis_kartu' => 'debit',
                    'nomor_kartu_akhir' => '5678',
                    'referensi_edc' => 'EDC-665544',
                ],
                'items' => [
                    ['product_id' => $this->product2->id, 'quantity' => 1],
                    ['product_id' => $this->product1->id, 'quantity' => 1],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.metode_pembayaran', 'split')
            ->assertJsonPath('data.total', 25000)
            ->assertJsonPath('data.nominal_bayar', 35000)
            ->assertJsonPath('data.kembalian', 10000);
    }
}
