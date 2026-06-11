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
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.nilai_estimasi', 0);

        $poId = $response->json('data.id');

        // Add items to PO Draft
        $responseAdd = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/order/{$poId}/items", [
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 20,
                        'harga_estimasi' => 28000,
                    ]
                ]
            ]);

        $responseAdd->assertStatus(200)
            ->assertJsonPath('data.nilai_estimasi', 560000);

        // 2. Update PO Draft
        $responseUpdate = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/order/{$poId}", [
                'supplier_id' => $this->supplier->id,
                'tanggal_po' => '2026-06-10',
                'catatan' => 'Pesanan minyak goreng update',
            ]);

        $responseUpdate->assertStatus(200);

        // Update items separately
        $responseUpdateItems = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/order/{$poId}/items", [
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 30, // Increase quantity
                        'harga_estimasi' => 28000,
                    ]
                ]
            ]);

        $responseUpdateItems->assertStatus(200)
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

        // 2. Create Stock Receiving referencing PO (as draft first)
        $responseReceiving = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/receiving', [
                'purchase_order_id' => $po->id,
                'supplier_id' => $this->supplier->id,
                'nomor_faktur' => 'INV-999',
                'nilai_faktur' => 600000,
            ]);

        $responseReceiving->assertStatus(201);
        $rcvId = $responseReceiving->json('data.id');

        // Add items to receiving note
        $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/receiving/{$rcvId}/items", [
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 20,
                        'harga_beli' => 30000,
                        'update_harga_jual' => false,
                    ]
                ]
            ])->assertStatus(200);

        // Complete the receiving note
        $this->actingAs($this->managerUser, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$rcvId}/complete")
            ->assertStatus(200);

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

        $receiving->items()->create([
            'product_id' => $this->product->id,
            'kuantitas' => 10,
            'harga_beli' => 30000,
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

    public function test_can_create_and_update_purchase_order_without_items(): void
    {
        // 1. Create PO Draft with items omitted
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/order', [
                'supplier_id' => $this->supplier->id,
                'tanggal_po' => '2026-06-11',
                'catatan' => 'Draft PO without items',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.nilai_estimasi', 0)
            ->assertJsonCount(0, 'data.items');

        $poId = $response->json('data.id');

        // 2. Update PO Draft header only (keep items omitted)
        $responseHeaderUpdate = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/order/{$poId}", [
                'supplier_id' => $this->supplier->id,
                'tanggal_po' => '2026-06-11',
                'catatan' => 'Draft PO without items updated',
            ]);

        $responseHeaderUpdate->assertStatus(200)
            ->assertJsonPath('data.catatan', 'Draft PO without items updated')
            ->assertJsonCount(0, 'data.items');

        // 3. Update PO Draft and add items
        $responseHeaderUpdate2 = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/order/{$poId}", [
                'supplier_id' => $this->supplier->id,
                'tanggal_po' => '2026-06-11',
                'catatan' => 'Draft PO with items now',
            ]);
        $responseHeaderUpdate2->assertStatus(200);

        $responseAddItems = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/order/{$poId}/items", [
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 10,
                        'harga_estimasi' => 29000,
                    ]
                ]
            ]);

        $responseAddItems->assertStatus(200)
            ->assertJsonPath('data.nilai_estimasi', 290000)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.product_id', $this->product->id);
    }

    public function test_can_create_and_update_stock_receiving_without_items(): void
    {
        // 1. Create Stock Receiving Draft with items omitted
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/receiving', [
                'supplier_id' => $this->supplier->id,
                'nomor_faktur' => 'INV-DRAFT-123',
                'status' => 'draft',
                'catatan' => 'Draft receiving without items',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(0, 'data.items');

        $rcvId = $response->json('data.id');

        // 2. Update Stock Receiving Draft header only (keep items omitted)
        $responseHeaderUpdate = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/receiving/{$rcvId}", [
                'supplier_id' => $this->supplier->id,
                'nomor_faktur' => 'INV-DRAFT-123-NEW',
                'status' => 'draft',
                'catatan' => 'Draft receiving updated header',
            ]);

        $responseHeaderUpdate->assertStatus(200)
            ->assertJsonPath('data.catatan', 'Draft receiving updated header')
            ->assertJsonCount(0, 'data.items');

        // 3. Update Stock Receiving Draft and add items
        $responseHeaderUpdate2 = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/receiving/{$rcvId}", [
                'supplier_id' => $this->supplier->id,
                'nomor_faktur' => 'INV-DRAFT-123-NEW',
                'status' => 'draft',
                'catatan' => 'Draft receiving with items now',
            ]);
        $responseHeaderUpdate2->assertStatus(200);

        $responseAddItems = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/receiving/{$rcvId}/items", [
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 15,
                        'harga_beli' => 29000,
                    ]
                ]
            ]);

        $responseAddItems->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.product_id', $this->product->id);
    }

    public function test_new_purchase_flow_enhancements(): void
    {
        // 1. Setup a PO
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-NEW-ENHANCE',
            'supplier_id' => $this->supplier->id,
            'tanggal_po' => '2026-06-11',
            'status' => 'ordered',
            'nilai_estimasi' => 300000,
            'user_id' => $this->managerUser->id,
        ]);
        $poItem = $po->items()->create([
            'product_id' => $this->product->id,
            'kuantitas' => 10,
            'harga_estimasi' => 30000,
        ]);

        // Add barcode to product
        $this->product->update(['barcode' => '8999999999999']);

        // Test scan product on receiving
        $scanResponse = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/receiving/scan', [
                'barcode' => '8999999999999',
                'purchase_order_id' => $po->id,
            ]);
        $scanResponse->assertStatus(200)
            ->assertJsonPath('product.id', $this->product->id)
            ->assertJsonPath('po_item.kuantitas_dipesan', 10);

        // 2. Create Stock Receiving Draft
        $receiving = StockReceiving::create([
            'nomor_penerimaan' => 'RCV-NEW-ENHANCE',
            'purchase_order_id' => $po->id,
            'supplier_id' => $this->supplier->id,
            'nomor_faktur' => 'INV-NEW-ENHANCE',
            'nilai_faktur' => 300000,
            'status' => 'completed',
            'status_pembayaran' => 'unpaid',
            'user_id' => $this->managerUser->id,
        ]);
        $rcvItem = $receiving->items()->create([
            'product_id' => $this->product->id,
            'kuantitas' => 10,
            'harga_beli' => 30000,
        ]);

        // 3. Test outstanding payment list
        $outstandingResponse = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson('/api/v1/purchase/payment/outstanding');
        $outstandingResponse->assertStatus(200)
            ->assertJsonFragment(['nomor_penerimaan' => 'RCV-NEW-ENHANCE']);

        // 4. Test payment summary before payment
        $summaryResponse1 = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson("/api/v1/purchase/receiving/{$receiving->id}/payment-summary");
        $summaryResponse1->assertStatus(200)
            ->assertJsonPath('total_faktur', 300000)
            ->assertJsonPath('total_dibayar', 0)
            ->assertJsonPath('sisa_hutang', 300000)
            ->assertJsonPath('status_pembayaran', 'pending');

        // 5. Test pay exceeding sisa_hutang should fail
        $payFailResponse = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/payment', [
                'stock_receiving_id' => $receiving->id,
                'nominal' => 350000, // exceeds 300k
                'tanggal_bayar' => '2026-06-11',
                'cash_account_id' => $this->cashAccount->id,
                'metode_pembayaran' => 'cash',
            ]);
        $payFailResponse->assertStatus(422);

        // 6. Pay partially
        $payResponse = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/payment', [
                'stock_receiving_id' => $receiving->id,
                'nominal' => 100000,
                'tanggal_bayar' => '2026-06-11',
                'cash_account_id' => $this->cashAccount->id,
                'metode_pembayaran' => 'cash',
            ]);
        $payResponse->assertStatus(201);

        // Test payment summary after partial payment
        $summaryResponse2 = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson("/api/v1/purchase/receiving/{$receiving->id}/payment-summary");
        $summaryResponse2->assertStatus(200)
            ->assertJsonPath('total_dibayar', 100000)
            ->assertJsonPath('sisa_hutang', 200000)
            ->assertJsonPath('status_pembayaran', 'partially_paid');

        // 7. Test returnable items endpoint
        $returnableResponse = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson("/api/v1/purchase/receiving/{$receiving->id}/returnable-items");
        $returnableResponse->assertStatus(200)
            ->assertJsonPath('data.0.product_id', $this->product->id)
            ->assertJsonPath('data.0.kuantitas_sisa', 10);

        // Test scan product on return
        $scanReturnResponse = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/return/scan', [
                'barcode' => '8999999999999',
                'stock_receiving_id' => $receiving->id,
            ]);
        $scanReturnResponse->assertStatus(200)
            ->assertJsonPath('product.id', $this->product->id)
            ->assertJsonPath('kuantitas_sisa', 10);

        // 8. Test finalize return with credit_note resolution
        $returnCredit = PurchaseReturn::create([
            'nomor_retur' => 'PRT-CREDIT-NOTE-ENH',
            'stock_receiving_id' => $receiving->id,
            'supplier_id' => $this->supplier->id,
            'tanggal_retur' => '2026-06-11',
            'total_nominal' => 60000, // 2 items * 30000
            'status' => 'draft',
            'user_id' => $this->managerUser->id,
        ]);
        $returnCredit->items()->create([
            'product_id' => $this->product->id,
            'kuantitas' => 2,
            'harga_beli' => 30000,
            'alasan' => 'damaged',
        ]);

        $finalizeCreditResponse = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson("/api/v1/purchase/return/{$returnCredit->id}/finalize", [
                'resolution_type' => 'credit_note',
                'catatan_penyelesaian' => 'Credit note added to supplier credits',
            ]);
        $finalizeCreditResponse->assertStatus(200);

        // Assert SupplierCredit record created
        $this->assertDatabaseHas('supplier_credits', [
            'supplier_id' => $this->supplier->id,
            'amount' => 60000,
        ]);

        // 9. Test finalize return with exchange resolution
        $returnExchange = PurchaseReturn::create([
            'nomor_retur' => 'PRT-EXCHANGE-ENH',
            'stock_receiving_id' => $receiving->id,
            'supplier_id' => $this->supplier->id,
            'tanggal_retur' => '2026-06-11',
            'total_nominal' => 90000, // 3 items * 30000
            'status' => 'draft',
            'user_id' => $this->managerUser->id,
        ]);
        $returnExchange->items()->create([
            'product_id' => $this->product->id,
            'kuantitas' => 3,
            'harga_beli' => 30000,
            'alasan' => 'expired',
        ]);

        $finalizeExchangeResponse = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson("/api/v1/purchase/return/{$returnExchange->id}/finalize", [
                'resolution_type' => 'exchange',
                'catatan_penyelesaian' => 'Exchange items draft created',
            ]);
        $finalizeExchangeResponse->assertStatus(200);

        // Assert StockReceiving draft created with exchange items
        $this->assertDatabaseHas('stock_receivings', [
            'supplier_id' => $this->supplier->id,
            'nomor_faktur' => 'EXCH-PRT-EXCHANGE-ENH',
            'status' => 'draft',
        ]);
    }

    public function test_receiving_note_po_quantity_details(): void
    {
        // 1. Create a PO with 2 items
        $product2 = Product::create([
            'nama' => 'Beras 5kg',
            'harga_beli' => 60000,
            'harga_jual' => 70000,
            'margin' => 16.67,
            'stok' => 10,
        ]);

        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-QTY-DETAILS',
            'supplier_id' => $this->supplier->id,
            'tanggal_po' => '2026-06-11',
            'status' => 'ordered',
            'nilai_estimasi' => 200000,
            'user_id' => $this->managerUser->id,
        ]);

        $po->items()->create([
            'product_id' => $this->product->id,
            'kuantitas' => 5,
            'harga_estimasi' => 28000,
            'kuantitas_diterima' => 2, // 2 already received previously
        ]);

        $po->items()->create([
            'product_id' => $product2->id,
            'kuantitas' => 3,
            'harga_estimasi' => 60000,
            'kuantitas_diterima' => 3, // fully received previously
        ]);

        // 2. Create Stock Receiving Note Draft referencing this PO
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/receiving', [
                'purchase_order_id' => $po->id,
                'supplier_id' => $this->supplier->id,
                'nomor_faktur' => 'INV-QTY-DETAILS',
                'nilai_faktur' => 200000,
            ]);

        $response->assertStatus(201);
        $rcvId = $response->json('data.id');

        // Assert items are automatically populated
        $response->assertJsonCount(2, 'data.items');

        // Check PO details on the first item (should have kuantitas = 3, kuantitas_po = 5, kuantitas_diterima_po = 2)
        $item1 = collect($response->json('data.items'))->where('product_id', $this->product->id)->first();
        $this->assertNotNull($item1);
        $this->assertEquals(3, $item1['kuantitas']); // sisa = 5 - 2 = 3
        $this->assertEquals(5, $item1['kuantitas_po']);
        $this->assertEquals(2, $item1['kuantitas_diterima_po']);
        $this->assertEquals(3, $item1['sisa_belum_diterima_po']);

        // Check PO details on the second item (should have kuantitas = 0, kuantitas_po = 3, kuantitas_diterima_po = 3)
        $item2 = collect($response->json('data.items'))->where('product_id', $product2->id)->first();
        $this->assertNotNull($item2);
        $this->assertEquals(0, $item2['kuantitas']); // fully received, so sisa = 0
        $this->assertEquals(3, $item2['kuantitas_po']);
        $this->assertEquals(3, $item2['kuantitas_diterima_po']);
        $this->assertEquals(0, $item2['sisa_belum_diterima_po']);

        // 3. Test update header (changing purchase_order_id from $po->id to null)
        $updateResponse = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/receiving/{$rcvId}", [
                'purchase_order_id' => null,
                'supplier_id' => $this->supplier->id,
                'nomor_faktur' => 'INV-QTY-DETAILS-UPDATED',
            ]);

        $updateResponse->assertStatus(200);
        // If purchase_order_id is null, it should have items reset or kept
        $updateResponse->assertJsonCount(0, 'data.items');

        // Update back to $po->id
        $updateResponse2 = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/receiving/{$rcvId}", [
                'purchase_order_id' => $po->id,
                'supplier_id' => $this->supplier->id,
                'nomor_faktur' => 'INV-QTY-DETAILS-UPDATED',
            ]);
        $updateResponse2->assertStatus(200);
        $updateResponse2->assertJsonCount(2, 'data.items');

        // 4. Test updateItems (set quantity of product 2 to 0 and product 1 to 0)
        $updateItemsResponse = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/receiving/{$rcvId}/items", [
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 0, // allowed now
                        'harga_beli' => 28000,
                    ],
                    [
                        'product_id' => $product2->id,
                        'kuantitas' => 0, // trying to receive 0 on a fully received PO item
                        'harga_beli' => 60000,
                    ]
                ]
            ]);
        $updateItemsResponse->assertStatus(200);

        // 5. Test complete on receiving note where all quantities are 0 (should fail with 422)
        $completeFailResponse = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$rcvId}/complete");
        $completeFailResponse->assertStatus(422)
            ->assertJsonFragment(['message' => 'Tidak dapat menyelesaikan penerimaan barang dengan total kuantitas 0.']);

        // Update items again to have some quantity (e.g. product 1 quantity = 2)
        $updateItemsResponse2 = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/receiving/{$rcvId}/items", [
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 2,
                        'harga_beli' => 28000,
                    ],
                    [
                        'product_id' => $product2->id,
                        'kuantitas' => 0,
                        'harga_beli' => 60000,
                    ]
                ]
            ]);
        $updateItemsResponse2->assertStatus(200);

        // Complete the receiving note (should succeed)
        $completeSuccessResponse = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$rcvId}/complete");
        $completeSuccessResponse->assertStatus(200);

        // Verify that only product 1 stock increased by 2
        $this->assertEquals(52, $this->product->fresh()->stok); // 50 + 2
        $this->assertEquals(10, $product2->fresh()->stok); // unchanged (10)

        // Verify PO item kuantitas_diterima updated for product 1 (2 + 2 = 4)
        $poItem1 = $po->fresh()->items()->where('product_id', $this->product->id)->first();
        $this->assertEquals(4, $poItem1->kuantitas_diterima);

        // Verify PO item kuantitas_diterima unchanged for product 2 (3)
        $poItem2 = $po->fresh()->items()->where('product_id', $product2->id)->first();
        $this->assertEquals(3, $poItem2->kuantitas_diterima);
    }

    public function test_receiving_note_non_po_items_flexible(): void
    {
        // 1. Create a PO with 1 item
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-FLEXIBLE-TEST',
            'supplier_id' => $this->supplier->id,
            'tanggal_po' => '2026-06-11',
            'status' => 'ordered',
            'nilai_estimasi' => 300000,
            'user_id' => $this->managerUser->id,
        ]);
        $po->items()->create([
            'product_id' => $this->product->id,
            'kuantitas' => 10,
            'harga_estimasi' => 30000,
        ]);

        // Create a second product not in the PO
        $product2 = Product::create([
            'nama' => 'Gula Pasir 1kg',
            'harga_beli' => 12000,
            'harga_jual' => 15000,
            'margin' => 25.00,
            'stok' => 20,
        ]);

        // 2. Create Stock Receiving Note Draft referencing this PO
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/receiving', [
                'purchase_order_id' => $po->id,
                'supplier_id' => $this->supplier->id,
                'nomor_faktur' => 'INV-FLEX-123',
            ]);
        $response->assertStatus(201);
        $rcvId = $response->json('data.id');

        // 3. Update items, adding the second product (which is not in the PO)
        $updateItemsResponse = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/purchase/receiving/{$rcvId}/items", [
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 10,
                        'harga_beli' => 30000,
                    ],
                    [
                        'product_id' => $product2->id,
                        'kuantitas' => 5, // 5 units of product not in PO
                        'harga_beli' => 12000,
                    ]
                ]
            ]);

        $updateItemsResponse->assertStatus(200);

        // Verify the second product has kuantitas_po = 0 in response
        $item2 = collect($updateItemsResponse->json('data.items'))->where('product_id', $product2->id)->first();
        $this->assertNotNull($item2);
        $this->assertEquals(0, $item2['kuantitas_po']);
        $this->assertEquals(0, $item2['kuantitas_diterima_po']);
        $this->assertEquals(0, $item2['sisa_belum_diterima_po']);

        // 4. Complete the receiving note
        $completeResponse = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$rcvId}/complete");
        $completeResponse->assertStatus(200);

        // 5. Verify the PO now has 2 items (the second product is dynamically added)
        $poFresh = $po->fresh();
        $this->assertEquals(2, $poFresh->items()->count());

        $newPoItem = $poFresh->items()->where('product_id', $product2->id)->first();
        $this->assertNotNull($newPoItem);
        $this->assertEquals(5, $newPoItem->kuantitas);
        $this->assertEquals(5, $newPoItem->kuantitas_diterima);

        // Verify PO's nilai_estimasi is updated: 300,000 + (5 * 12,000) = 360,000
        $this->assertEquals(360000, $poFresh->nilai_estimasi);
    }
}
