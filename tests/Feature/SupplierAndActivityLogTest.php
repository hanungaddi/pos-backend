<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockReceiving;
use App\Models\Supplier;
use App\Models\User;
use App\Models\ActivityLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SupplierAndActivityLogTest extends TestCase
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

        $this->seed(RolePermissionSeeder::class);

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

        $this->product = Product::create([
            'nama' => 'Beras Pandan Wangi 5kg',
            'merek' => 'Cianjur',
            'stok' => 10,
            'harga' => 75000,
        ]);
    }

    public function test_supplier_crud_workflow(): void
    {
        // 1. Create supplier
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/inventory/suppliers', [
                'nama' => 'PT Sumber Makmur',
                'email' => 'info@sumbermakmur.com',
                'nomor_telepon' => '0812345678',
                'alamat' => 'Jakarta Barat',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.nama', 'PT Sumber Makmur');

        $supplierId = $response->json('data.id');

        // Verify activity log is created
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'create_supplier',
            'user_id' => $this->managerUser->id,
        ]);

        // 2. Read suppliers list
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson('/api/v1/inventory/suppliers/all');

        $response->assertStatus(200)
            ->assertJsonFragment(['nama' => 'PT Sumber Makmur']);

        // 3. Update supplier
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/inventory/suppliers/{$supplierId}", [
                'nama' => 'PT Sumber Makmur Utama',
                'email' => 'info@sumbermakmur.com',
                'nomor_telepon' => '0812345678',
                'alamat' => 'Jakarta Barat',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.nama', 'PT Sumber Makmur Utama');

        // Verify activity log for update
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'update_supplier',
            'user_id' => $this->managerUser->id,
        ]);

        // 4. Delete supplier
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->deleteJson("/api/v1/inventory/suppliers/{$supplierId}");

        $response->assertStatus(200);

        // Verify deleted in DB
        $this->assertDatabaseMissing('suppliers', ['id' => $supplierId]);

        // Verify activity log for delete
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'delete_supplier',
            'user_id' => $this->managerUser->id,
        ]);
    }

    public function test_receiving_draft_finalize_and_delete_workflow(): void
    {
        $supplier = Supplier::create([
            'nama' => 'PT Pemasok Baru',
            'store_id' => 1,
        ]);

        // 1. Create a draft receiving
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/inventory/receiving', [
                'supplier_id' => $supplier->id,
                'supplier' => $supplier->nama,
                'nomor_faktur' => 'INV-DRAFT-001',
                'catatan' => 'Draft Penerimaan Pertama',
                'status' => 'draft',
                'nilai_faktur' => 500000,
                'status_pembayaran' => 'pending',
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 10,
                        'harga_beli' => 50000,
                    ]
                ]
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.nilai_faktur', 500000)
            ->assertJsonPath('data.status_pembayaran', 'pending');

        $recId = $response->json('data.id');

        // Verify stock is NOT updated
        $this->assertEquals(10, $this->product->fresh()->stok);

        // Verify log is recorded
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'create_receiving_draft',
            'user_id' => $this->managerUser->id,
        ]);

        // 2. Finalize receiving draft
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/v1/inventory/receiving/{$recId}", [
                'supplier_id' => $supplier->id,
                'supplier' => $supplier->nama,
                'nomor_faktur' => 'INV-DRAFT-001',
                'catatan' => 'Penerimaan Pertama (Final)',
                'status' => 'completed',
                'nilai_faktur' => 500000,
                'status_pembayaran' => 'pending',
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'kuantitas' => 10,
                        'harga_beli' => 50000,
                    ]
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        // Verify stock is increased
        $this->assertEquals(20, $this->product->fresh()->stok);

        // Verify log is recorded
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'finalize_receiving',
            'user_id' => $this->managerUser->id,
        ]);
    }

    public function test_receiving_delete_draft(): void
    {
        $supplier = Supplier::create([
            'nama' => 'PT Pemasok Lain',
            'store_id' => 1,
        ]);

        // Create a draft receiving
        $receiving = StockReceiving::create([
            'store_id' => 1,
            'nomor_penerimaan' => 'RCV-DRAFT-TEST',
            'supplier_id' => $supplier->id,
            'supplier' => $supplier->nama,
            'status' => 'draft',
            'user_id' => $this->managerUser->id,
        ]);

        $receiving->items()->create([
            'product_id' => $this->product->id,
            'kuantitas' => 5,
        ]);

        // Delete draft receiving
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->deleteJson("/api/v1/inventory/receiving/{$receiving->id}");

        $response->assertStatus(200);

        // Verify deleted from DB
        $this->assertDatabaseMissing('stock_receivings', ['id' => $receiving->id]);

        // Verify activity log is recorded
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'delete_receiving_draft',
            'user_id' => $this->managerUser->id,
        ]);
    }

    public function test_opname_delete_draft(): void
    {
        $opname = StockOpname::create([
            'store_id' => 1,
            'nomor_opname' => 'OPN-DRAFT-TEST',
            'status' => 'draft',
            'user_id' => $this->managerUser->id,
        ]);

        $opname->items()->create([
            'product_id' => $this->product->id,
            'stok_sistem' => 10,
            'stok_fisik' => 10,
            'selisih' => 0,
            'alasan' => 'test draft deletion',
        ]);

        // Delete draft opname
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->deleteJson("/api/v1/inventory/opname/{$opname->id}");

        $response->assertStatus(200);

        // Verify deleted
        $this->assertDatabaseMissing('stock_opnames', ['id' => $opname->id]);

        // Verify activity log
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'delete_opname_draft',
            'user_id' => $this->managerUser->id,
        ]);
    }

    public function test_supplier_permissions_for_supervisor_and_cashier(): void
    {
        $supplier = Supplier::create([
            'nama' => 'PT Test Perms',
            'store_id' => 1,
        ]);

        // 1. Supervisor (has view_suppliers) can view suppliers
        $this->actingAs($this->supervisorUser, 'sanctum')
            ->getJson('/api/v1/inventory/suppliers/all')
            ->assertStatus(200)
            ->assertJsonFragment(['nama' => 'PT Test Perms']);

        $this->actingAs($this->supervisorUser, 'sanctum')
            ->getJson("/api/v1/inventory/suppliers/{$supplier->id}")
            ->assertStatus(200);

        // 2. Supervisor cannot write/modify/delete suppliers
        $this->actingAs($this->supervisorUser, 'sanctum')
            ->postJson('/api/v1/inventory/suppliers', [
                'nama' => 'PT Illegal write',
            ])
            ->assertStatus(403);

        $this->actingAs($this->supervisorUser, 'sanctum')
            ->putJson("/api/v1/inventory/suppliers/{$supplier->id}", [
                'nama' => 'PT Illegal update',
            ])
            ->assertStatus(403);

        $this->actingAs($this->supervisorUser, 'sanctum')
            ->deleteJson("/api/v1/inventory/suppliers/{$supplier->id}")
            ->assertStatus(403);

        // 3. Cashier (no supplier permissions) cannot view or write suppliers
        $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson('/api/v1/inventory/suppliers/all')
            ->assertStatus(403);

        $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson("/api/v1/inventory/suppliers/{$supplier->id}")
            ->assertStatus(403);

        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/inventory/suppliers', [
                'nama' => 'PT Cashier write',
            ])
            ->assertStatus(403);
    }

    public function test_activity_logs_indexing_and_searching(): void
    {
        ActivityLog::create([
            'store_id' => 1,
            'user_id' => $this->adminUser->id,
            'action' => 'test_action_searchable',
            'description' => 'Special unique description string',
        ]);

        // Get logs as Admin (has view_audit_logs)
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/activity-logs?search=Special unique');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'test_action_searchable');

        // Manager can view logs (has view_audit_logs)
        $this->actingAs($this->managerUser, 'sanctum')
            ->getJson('/api/v1/activity-logs')
            ->assertStatus(200);

        // Supervisor user cannot view logs (403)
        $this->actingAs($this->supervisorUser, 'sanctum')
            ->getJson('/api/v1/activity-logs')
            ->assertStatus(403);

        // Cashier user cannot view logs (403)
        $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson('/api/v1/activity-logs')
            ->assertStatus(403);
    }
}
