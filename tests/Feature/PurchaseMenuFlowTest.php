<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\StockReceiving;
use App\Models\Transaction;
use App\Models\CashAccount;
use App\Models\User;
use App\Models\Supplier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseMenuFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $managerUser;
    protected Product $product;
    protected Supplier $supplier;
    protected CashAccount $cashAccount;

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
            'nama' => 'Minyak Goreng 2L',
            'merek' => 'Bimoli',
            'harga_beli' => 30000,
            'harga_jual' => 36000,
            'margin' => 20.00,
            'stok' => 50,
        ]);

        $this->supplier = Supplier::create([
            'nama' => 'PT Distributor Utama',
            'kontak' => 'Budi',
            'telepon' => '08123456789',
        ]);

        // Seed default cash account
        $this->cashAccount = CashAccount::create([
            'nama' => 'Kas Utama',
            'tipe' => 'cash',
            'saldo' => 1000000, // Rp 1.000.000
        ]);
    }

    public function test_purchase_order_lifecycle(): void
    {
        // 1. Create PO Draft
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/order', [
                'supplier_id' => $this->supplier->id,
                'tanggal_po' => '2026-06-10',
                'catatan' => 'Pesanan minyak goreng',
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 20,
                        'harga_estimasi' => 28000,
                    ]
                ]
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.nilai_estimasi', 560000);

        $poId = $response->json('data.id');

        // 2. Update PO Draft
        $responseUpdate = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/order/{$poId}", [
                'supplier_id' => $this->supplier->id,
                'tanggal_po' => '2026-06-10',
                'catatan' => 'Pesanan minyak goreng update',
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 30, // Increase quantity
                        'harga_estimasi' => 28000,
                    ]
                ]
            ]);

        $responseUpdate->assertStatus(200)
            ->assertJsonPath('data.nilai_estimasi', 840000);

        // 3. Finalize PO (status -> ordered)
        $responseFinalize = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson("/api/v1/purchase/order/{$poId}/finalize");

        $responseFinalize->assertStatus(200)
            ->assertJsonPath('data.status', 'ordered');

        // Test cancel on draft/ordered
        $responseCancel = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson("/api/v1/purchase/order/{$poId}/cancel");

        $responseCancel->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_receiving_linked_to_purchase_order(): void
    {
        // 1. Create & Finalize PO
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-TEST-1234',
            'supplier_id' => $this->supplier->id,
            'tanggal_po' => '2026-06-10',
            'status' => 'ordered',
            'nilai_estimasi' => 600000,
            'user_id' => $this->managerUser->id,
        ]);

        $po->items()->create([
            'product_id' => $this->product->id,
            'kuantitas' => 20,
            'harga_estimasi' => 30000,
        ]);

        // 2. Create Stock Receiving referencing PO
        $responseReceiving = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/receiving', [
                'purchase_order_id' => $po->id,
                'supplier_id' => $this->supplier->id,
                'nomor_faktur' => 'INV-999',
                'nilai_faktur' => 600000,
                'status' => 'completed',
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 20,
                        'harga_beli' => 30000,
                        'update_harga_jual' => false,
                    ]
                ]
            ]);

        $responseReceiving->assertStatus(201);

        // Verify PO status updated to 'received' and items received quantity updated
        $this->assertEquals('received', $po->fresh()->status);
        $this->assertEquals(20, $po->fresh()->items()->first()->kuantitas_diterima);

        // Verify Product stock increased: 50 + 20 = 70
        $this->assertEquals(70, $this->product->fresh()->stok);
    }

    public function test_receiving_invoice_payments_workflow(): void
    {
        // 1. Create completed Stock Receiving
        $receiving = StockReceiving::create([
            'nomor_penerimaan' => 'RCV-TEST-123',
            'supplier_id' => $this->supplier->id,
            'nomor_faktur' => 'INV-PAY-123',
            'nilai_faktur' => 500000, // Rp 500.000
            'status' => 'completed',
            'status_pembayaran' => 'unpaid',
            'user_id' => $this->managerUser->id,
        ]);

        // 2. Pay Partially (Rp 200.000)
        $response1 = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/payment', [
                'stock_receiving_id' => $receiving->id,
                'nominal' => 200000,
                'tanggal_bayar' => '2026-06-10',
                'cash_account_id' => $this->cashAccount->id,
                'metode_pembayaran' => 'cash',
                'catatan' => 'Dp Pembayaran',
            ]);

        $response1->assertStatus(201);
        $paymentId = $response1->json('data.id');

        // Assert payment status updated to 'partial'
        $this->assertEquals('partial', $receiving->fresh()->status_pembayaran);
        // Assert Kas Utama balance decreased: 1.000.000 - 200.000 = 800.000
        $this->assertEquals(800000, $this->cashAccount->fresh()->saldo);

        // 3. Update payment amount to Rp 300.000
        $responseUpdate = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/payment/{$paymentId}", [
                'nominal' => 300000,
                'tanggal_bayar' => '2026-06-10',
                'cash_account_id' => $this->cashAccount->id,
                'metode_pembayaran' => 'cash',
                'catatan' => 'Dp Pembayaran Update',
            ]);

        $responseUpdate->assertStatus(200);

        // Assert payment status is still 'partial'
        $this->assertEquals('partial', $receiving->fresh()->status_pembayaran);
        // Assert Kas Utama balance decreased further: 1.000.000 - 300.000 = 700.000
        $this->assertEquals(700000, $this->cashAccount->fresh()->saldo);

        // 4. Pay remaining (Rp 200.000)
        $response2 = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/payment', [
                'stock_receiving_id' => $receiving->id,
                'nominal' => 200000,
                'tanggal_bayar' => '2026-06-10',
                'cash_account_id' => $this->cashAccount->id,
                'metode_pembayaran' => 'cash',
            ]);

        $response2->assertStatus(201);

        // Assert payment status updated to 'paid' (total paid = 300k + 200k = 500k)
        $this->assertEquals('paid', $receiving->fresh()->status_pembayaran);
        // Assert Kas Utama balance: 700k - 200k = 500k
        $this->assertEquals(500000, $this->cashAccount->fresh()->saldo);

        // 5. Void the second payment (Rp 200.000)
        $responseVoid = $this->actingAs($this->managerUser, 'sanctum')
            ->deleteJson("/api/v1/purchase/payment/{$paymentId}"); // Voids the first payment of 300k

        $responseVoid->assertStatus(200);

        // Assert payment status rolls back to 'partial' (only 200k remaining paid)
        $this->assertEquals('partial', $receiving->fresh()->status_pembayaran);
        // Assert Kas Utama balance refunded 300k: 500k + 300k = 800k
        $this->assertEquals(800000, $this->cashAccount->fresh()->saldo);
    }

    public function test_purchase_returns_workflow(): void
    {
        // Setup initial receiving
        $receiving = StockReceiving::create([
            'nomor_penerimaan' => 'RCV-TEST-RET',
            'supplier_id' => $this->supplier->id,
            'nomor_faktur' => 'INV-RET-123',
            'nilai_faktur' => 150000,
            'status' => 'completed',
            'status_pembayaran' => 'unpaid',
            'user_id' => $this->managerUser->id,
        ]);

        // 1. Create Return Draft
        $responseReturn = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/return', [
                'stock_receiving_id' => $receiving->id,
                'supplier_id' => $this->supplier->id,
                'tanggal_retur' => '2026-06-10',
                'catatan' => 'Minyak bocor',
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 2,
                        'harga_beli' => 30000,
                    ]
                ]
            ]);

        $responseReturn->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_nominal', 60000);

        $returnId = $responseReturn->json('data.id');

        // 2. Finalize Return with Cash Refund
        $responseFinalize = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson("/api/v1/purchase/return/{$returnId}/finalize", [
                'impact_type' => 'refund',
                'cash_account_id' => $this->cashAccount->id,
            ]);

        $responseFinalize->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        // Verify stock decreased: 50 - 2 = 48
        $this->assertEquals(48, $this->product->fresh()->stok);

        // Verify Kas Utama balance increased by refund amount: 1.000.000 + 60.000 = 1.060.000
        $this->assertEquals(1060000, $this->cashAccount->fresh()->saldo);

        // Verify stock movement logged
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'tipe' => 'void',
            'kuantitas' => -2,
            'referensi_id' => $returnId,
            'referensi_tipe' => 'purchase_return',
        ]);
    }

    public function test_purchase_returns_workflow_credit(): void
    {
        // Setup initial receiving
        $receiving = StockReceiving::create([
            'nomor_penerimaan' => 'RCV-TEST-RET-CREDIT',
            'supplier_id' => $this->supplier->id,
            'nomor_faktur' => 'INV-RET-456',
            'nilai_faktur' => 100000,
            'status' => 'completed',
            'status_pembayaran' => 'unpaid',
            'user_id' => $this->managerUser->id,
        ]);

        // 1. Create Return Draft
        $return = PurchaseReturn::create([
            'nomor_retur' => 'PRT-CREDIT-TEST',
            'stock_receiving_id' => $receiving->id,
            'supplier_id' => $this->supplier->id,
            'tanggal_retur' => '2026-06-10',
            'total_nominal' => 100000, // Fully returns the invoice value
            'status' => 'draft',
            'user_id' => $this->managerUser->id,
        ]);

        $return->items()->create([
            'product_id' => $this->product->id,
            'kuantitas' => 5,
            'harga_beli' => 20000,
        ]);

        // 2. Finalize Return with Credit deduction on receiving invoice
        $responseFinalize = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson("/api/v1/purchase/return/{$return->id}/finalize", [
                'impact_type' => 'credit',
                'stock_receiving_id' => $receiving->id,
            ]);

        $responseFinalize->assertStatus(200);

        // Verify that the receiving invoice status_pembayaran updated to 'paid' (because of the credit deduction)
        $this->assertEquals('paid', $receiving->fresh()->status_pembayaran);

        // Verify Kas Utama balance remains unchanged: 1.000.000
        $this->assertEquals(1000000, $this->cashAccount->fresh()->saldo);
    }
}
