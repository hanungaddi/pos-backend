<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockReceiving;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $managerUser;
    protected User $supervisorUser;
    protected User $cashierUser;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles and permissions
        $this->seed(RolePermissionSeeder::class);

        // Create Users
        $this->adminUser = User::create([
            'name' => 'Admin POS',
            'username' => 'admin_pos',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->adminUser->assignRole('admin');

        $this->managerUser = User::create([
            'name' => 'Manager Store',
            'username' => 'manager_store',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->managerUser->assignRole('manajer_toko');

        $this->supervisorUser = User::create([
            'name' => 'Supervisor POS',
            'username' => 'spv_pos',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->supervisorUser->assignRole('supervisor');

        $this->cashierUser = User::create([
            'name' => 'Cashier POS',
            'username' => 'cashier_pos',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->cashierUser->assignRole('kasir');

        // Create a test product
        $this->product = Product::create([
            'nama' => 'Beras Pandan Wangi 5kg',
            'merek' => 'Cianjur',
            'stok' => 10,
            'harga' => 75000,
        ]);
    }

    public function test_supervisor_and_above_can_view_stock_movements(): void
    {
        // Add a dummy movement
        StockMovement::create([
            'product_id' => $this->product->id,
            'tipe' => 'adjustment',
            'kuantitas' => 5,
            'stok_sebelum' => 10,
            'stok_sesudah' => 15,
            'user_id' => $this->adminUser->id,
        ]);

        // Supervisor can view movements
        $response = $this->actingAs($this->supervisorUser, 'sanctum')
            ->getJson('/api/v1/inventory/movements');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'product_id', 'tipe', 'kuantitas', 'stok_sebelum', 'stok_sesudah']
                ]
            ]);

        // Cashier CANNOT view movements
        $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson('/api/v1/inventory/movements')
            ->assertStatus(403);
    }

    public function test_manager_and_above_can_create_stock_receiving(): void
    {
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/purchase/receiving', [
                'supplier' => 'PT Distribusi Sembako',
                'nomor_faktur' => 'FAK-12345',
                'catatan' => 'Penerimaan rutin bulanan',
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 20,
                        'harga_beli' => 50000,
                    ]
                ]
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.supplier', 'PT Distribusi Sembako')
            ->assertJsonStructure(['message', 'data' => ['nomor_penerimaan', 'items']]);

        // Verify stock is increased
        $this->assertEquals(30, $this->product->fresh()->stok);

        // Verify stock movement log is created
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'tipe' => 'receive',
            'kuantitas' => 20,
            'stok_sebelum' => 10,
            'stok_sesudah' => 30,
            'user_id' => $this->managerUser->id,
        ]);

        // Supervisor CANNOT create receiving (403)
        $this->actingAs($this->supervisorUser, 'sanctum')
            ->postJson('/api/v1/purchase/receiving', [
                'items' => [
                    ['product_id' => $this->product->id, 'kuantitas' => 5, 'harga_beli' => 50000]
                ]
            ])
            ->assertStatus(403);
    }

    public function test_supervisor_can_view_receiving_and_opname_but_cannot_modify(): void
    {
        // 1. Create a receiving draft as manager
        $receiving = StockReceiving::create([
            'nomor_penerimaan' => 'RCV-001',
            'supplier' => 'PT Test Supplier',
            'nomor_faktur' => 'INV-001',
            'status' => 'draft',
            'user_id' => $this->managerUser->id,
        ]);

        // 2. Create an opname draft as manager
        $opname = StockOpname::create([
            'nomor_opname' => 'OPN-001',
            'status' => 'draft',
            'user_id' => $this->managerUser->id,
        ]);

        // 3. Supervisor can view list of receiving
        $response = $this->actingAs($this->supervisorUser, 'sanctum')
            ->getJson('/api/v1/purchase/receiving');
        $response->assertStatus(200)
            ->assertJsonFragment(['nomor_faktur' => 'INV-001']);

        // 4. Supervisor can view detail of receiving
        $response = $this->actingAs($this->supervisorUser, 'sanctum')
            ->getJson("/api/v1/purchase/receiving/{$receiving->id}");
        $response->assertStatus(200);

        // 5. Supervisor can view list of opname
        $response = $this->actingAs($this->supervisorUser, 'sanctum')
            ->getJson('/api/v1/inventory/opname');
        $response->assertStatus(200)
            ->assertJsonFragment(['nomor_opname' => 'OPN-001']);

        // 6. Supervisor can view detail of opname
        $response = $this->actingAs($this->supervisorUser, 'sanctum')
            ->getJson("/api/v1/inventory/opname/{$opname->id}");
        $response->assertStatus(200);

        // 7. Supervisor CANNOT update receiving
        $this->actingAs($this->supervisorUser, 'sanctum')
            ->putJson("/api/v1/purchase/receiving/{$receiving->id}", [
                'supplier' => 'PT Changed'
            ])
            ->assertStatus(403);

        // 8. Supervisor CANNOT update opname
        $this->actingAs($this->supervisorUser, 'sanctum')
            ->putJson("/api/v1/inventory/opname/{$opname->id}", [
                'status' => 'completed'
            ])
            ->assertStatus(403);
    }

    public function test_manager_and_above_can_create_stock_adjustment(): void
    {
        // Negative adjustment (losses / damage)
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/inventory/adjustment', [
                'product_id' => $this->product->id,
                'kuantitas' => -3,
                'alasan' => 'Beras tumpah dan basah',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.kuantitas', -3)
            ->assertJsonPath('data.stok_sebelum', 10)
            ->assertJsonPath('data.stok_sesudah', 7);

        $this->assertEquals(7, $this->product->fresh()->stok);

        // Verify stock movement log is created
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'tipe' => 'adjustment',
            'kuantitas' => -3,
            'stok_sebelum' => 10,
            'stok_sesudah' => 7,
            'alasan' => 'Beras tumpah dan basah',
            'user_id' => $this->managerUser->id,
        ]);
    }

    public function test_stock_opname_workflow_draft_and_completion(): void
    {
        // 1. Create a draft stock opname
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/inventory/opname', [
                'catatan' => 'Opname akhir bulan',
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'stok_fisik' => 12, // Physical has 12 items (discrepancy of +2)
                        'alasan' => 'Selisih temuan di rak',
                    ]
                ]
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft');

        $opnameId = $response->json('data.id');

        // Confirm stock is NOT modified in draft state
        $this->assertEquals(10, $this->product->fresh()->stok);
        $this->assertDatabaseMissing('stock_movements', ['tipe' => 'opname']);

        // 2. Finalize / Complete the stock opname
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/inventory/opname/{$opnameId}", [
                'status' => 'completed',
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'stok_fisik' => 12,
                        'alasan' => 'Selisih temuan di rak (terverifikasi)',
                    ]
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        // Confirm stock IS updated to physical stock (12)
        $this->assertEquals(12, $this->product->fresh()->stok);

        // Verify stock movement log is created for the discrepancy (+2)
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'tipe' => 'opname',
            'kuantitas' => 2,
            'stok_sebelum' => 10,
            'stok_sesudah' => 12,
            'referensi_id' => $opnameId,
            'referensi_tipe' => 'opname',
            'user_id' => $this->managerUser->id,
        ]);
    }

    public function test_sales_checkout_records_sale_stock_movement(): void
    {
        // Create a transaction as cashier
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'items' => [
                    ['product_id' => $this->product->id, 'quantity' => 2],
                ],
            ]);

        $response->assertCreated();
        $trxId = $response->json('data.id');

        // Pay the transaction
        $payResponse = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$trxId}/pay/cash", [
                'nominal_bayar' => 200000,
            ]);

        $payResponse->assertOk();

        // Stock decreased from 10 to 8
        $this->assertEquals(8, $this->product->fresh()->stok);

        // Verify stock movement of type 'sale' with kuantitas = -2
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'tipe' => 'sale',
            'kuantitas' => -2,
            'stok_sebelum' => 10,
            'stok_sesudah' => 8,
            'referensi_id' => $trxId,
            'referensi_tipe' => 'transaction',
        ]);
    }
}
