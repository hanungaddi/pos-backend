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

    public function test_cashier_can_create_draft_transaction_and_manage_items(): void
    {
        // 1. Create a draft transaction
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions');

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.subtotal', 0)
            ->assertJsonPath('data.total', 0);

        $trxId = $response->json('data.id');

        // 2. Add an item via product ID
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$trxId}/items", [
                'product_id' => $this->product1->id,
                'quantity' => 2,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.subtotal', 6000)
            ->assertJsonPath('data.total', 6000);

        $itemId = $response->json('data.items.0.id');

        // 3. Add same item again (should update quantity instead of inserting new record)
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$trxId}/items", [
                'product_id' => $this->product1->id,
                'quantity' => 1,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.items.0.kuantitas', 3)
            ->assertJsonPath('data.subtotal', 9000);

        // 4. Add item via barcode lookup
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$trxId}/items", [
                'barcode' => '8990001003',
                'quantity' => 2,
            ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.subtotal', 53000); // 3 * 3000 + 2 * 22000 = 9000 + 44000 = 53000

        // 5. Update item quantity
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->putJson("/api/v1/transactions/{$trxId}/items/{$itemId}", [
                'quantity' => 5,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.items.0.kuantitas', 5)
            ->assertJsonPath('data.subtotal', 59000); // 5 * 3000 + 2 * 22000 = 15000 + 44000 = 59000

        // 6. Remove item
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->deleteJson("/api/v1/transactions/{$trxId}/items/{$itemId}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.subtotal', 44000); // 2 * 22000 = 44000
    }

    public function test_hold_and_recall_workflow(): void
    {
        // Create draft with items
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'items' => [
                    ['product_id' => $this->product1->id, 'quantity' => 3],
                ],
            ]);

        $response->assertCreated();
        $trxId = $response->json('data.id');

        // Hold transaction
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$trxId}/hold");

        $response->assertOk()
            ->assertJsonPath('data.status', 'hold');

        // Get list on-hold (should show this transaction)
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson('/api/v1/transactions/on-hold');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $trxId);

        // Recall transaction
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$trxId}/recall");

        $response->assertOk()
            ->assertJsonPath('data.status', 'draft');

        // Get list on-hold (should be empty now)
        $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson('/api/v1/transactions/on-hold')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_payment_methods_deduct_stock_and_record_movements(): void
    {
        // 1. Cash Payment
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'items' => [
                    ['product_id' => $this->product1->id, 'quantity' => 5],
                ],
            ]);
        $trxId1 = $response->json('data.id');

        $payResponse = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$trxId1}/pay/cash", [
                'nominal_bayar' => 20000,
            ]);

        $payResponse->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.metode_pembayaran', 'cash')
            ->assertJsonPath('data.nominal_bayar', 20000)
            ->assertJsonPath('data.kembalian', 5000); // 20000 - 15000 = 5000

        // Stock decreased (50 - 5 = 45)
        $this->assertEquals(45, $this->product1->fresh()->stok);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product1->id,
            'tipe' => 'sale',
            'kuantitas' => -5,
            'stok_sebelum' => 50,
            'stok_sesudah' => 45,
            'referensi_id' => $trxId1,
            'referensi_tipe' => 'transaction',
        ]);

        // 2. Card Payment
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'items' => [
                    ['product_id' => $this->product2->id, 'quantity' => 2],
                ],
            ]);
        $trxId2 = $response->json('data.id');

        $payResponse = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$trxId2}/pay/card", [
                'jenis_kartu' => 'debit',
                'nomor_kartu_akhir' => '1234',
                'referensi_edc' => 'EDC-998877',
            ]);

        $payResponse->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.metode_pembayaran', 'card')
            ->assertJsonPath('data.jenis_kartu', 'debit')
            ->assertJsonPath('data.nomor_kartu_akhir', '1234')
            ->assertJsonPath('data.referensi_edc', 'EDC-998877');

        // Stock decreased (10 - 2 = 8)
        $this->assertEquals(8, $this->product2->fresh()->stok);

        // 3. Split Payment
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'items' => [
                    ['product_id' => $this->product1->id, 'quantity' => 2], // 6000
                ],
            ]);
        $trxId3 = $response->json('data.id');

        $payResponse = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$trxId3}/pay/split", [
                'cash_amount' => 2000,
                'card_amount' => 4000,
                'nominal_bayar' => 5000, // cash paid (change should be 3000)
                'jenis_kartu' => 'kredit',
                'nomor_kartu_akhir' => '5678',
                'referensi_edc' => 'EDC-665544',
            ]);

        $payResponse->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.metode_pembayaran', 'split')
            ->assertJsonPath('data.kembalian', 3000); // 5000 - 2000 = 3000

        // Stock decreased (45 - 2 = 43)
        $this->assertEquals(43, $this->product1->fresh()->stok);
    }

    public function test_void_workflow(): void
    {
        // 1. Create and complete a transaction
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'items' => [
                    ['product_id' => $this->product2->id, 'quantity' => 3], // 66000
                ],
            ]);
        $trxId = $response->json('data.id');

        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$trxId}/pay/cash", [
                'nominal_bayar' => 70000,
            ])->assertOk();

        // Product stock decreased (10 - 3 = 7)
        $this->assertEquals(7, $this->product2->fresh()->stok);

        // 2. Cashier tries to void (should fail 403)
        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$trxId}/void", [
                'catatan_void' => 'Salah input jumlah barang',
            ])->assertStatus(403);

        // 3. Supervisor voids (should succeed 200)
        $response = $this->actingAs($this->supervisorUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$trxId}/void", [
                'catatan_void' => 'Supervisor void request',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'void')
            ->assertJsonPath('data.catatan_void', 'Supervisor void request')
            ->assertJsonPath('data.void_by.id', $this->supervisorUser->id);

        // Product stock restored back to 10
        $this->assertEquals(10, $this->product2->fresh()->stok);

        // Verify stock movement for void is recorded
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product2->id,
            'tipe' => 'void',
            'kuantitas' => 3,
            'stok_sebelum' => 7,
            'stok_sesudah' => 10,
            'referensi_id' => $trxId,
            'referensi_tipe' => 'transaction',
        ]);
    }

    public function test_kasir_can_only_see_their_own_transactions_in_history(): void
    {
        // 1. Cashier 1 creates a transaction
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions');
        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$response->json('data.id')}/hold");

        // 2. Cashier 2 creates a transaction
        $response2 = $this->actingAs($this->otherCashierUser, 'sanctum')
            ->postJson('/api/v1/transactions');
        $this->actingAs($this->otherCashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$response2->json('data.id')}/hold");

        // 3. Cashier 1 requests history (should only see 1 transaction)
        $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.user_id', $this->cashierUser->id);

        // 4. Supervisor requests history (should see both)
        $this->actingAs($this->supervisorUser, 'sanctum')
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_reports_api_endpoints(): void
    {
        // 1. Complete one cash sale (Aqua * 3 = 9000)
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'items' => [
                    ['product_id' => $this->product1->id, 'quantity' => 3],
                ],
            ]);
        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$response->json('data.id')}/pay/cash", [
                'nominal_bayar' => 10000,
            ])->assertOk();

        // 2. Complete one card sale (Pringles * 1 = 22000)
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'items' => [
                    ['product_id' => $this->product2->id, 'quantity' => 1],
                ],
            ]);
        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$response->json('data.id')}/pay/card", [
                'jenis_kartu' => 'debit',
                'nomor_kartu_akhir' => '9999',
                'referensi_edc' => 'EDC-123',
            ])->assertOk();

        // 3. Create one draft sale (should be ignored in reports)
        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'items' => [
                    ['product_id' => $this->product1->id, 'quantity' => 10],
                ],
            ])->assertCreated();

        // 4. Call /reports/summary
        $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/reports/summary')
            ->assertOk()
            ->assertJsonPath('sales_count', 2)
            ->assertJsonPath('items_sold', 4)
            ->assertJsonPath('net_sales', 31000)
            ->assertJsonPath('top_products.0.product_name', 'Aqua 600ml')
            ->assertJsonPath('top_products.0.quantity', 3)
            ->assertJsonPath('top_products.1.product_name', 'Pringles 110g')
            ->assertJsonPath('top_products.1.quantity', 1);

        // 5. Call /reports/sales/daily
        $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/reports/sales/daily')
            ->assertOk()
            ->assertJsonPath('total_sales', 31000)
            ->assertJsonPath('transactions_count', 2)
            ->assertJsonPath('average_transaction_value', 15500)
            ->assertJsonPath('payment_methods.cash.total', 9000)
            ->assertJsonPath('payment_methods.card.total', 22000)
            ->assertJsonPath('void_count', 0);
    }
}
