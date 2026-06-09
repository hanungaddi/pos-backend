<?php

namespace Tests\Feature;

use App\Models\CashDrawerSession;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CashDrawerTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashierUser;
    protected User $otherCashierUser;
    protected User $supervisorUser;
    protected User $managerUser;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->cashierUser = User::create([
            'name' => 'Cashier User',
            'username' => 'cashier_user',
            'password' => Hash::make('password'),
            'status' => 'active',
            'store_id' => 1,
        ]);
        $this->cashierUser->assignRole('kasir');

        $this->otherCashierUser = User::create([
            'name' => 'Other Cashier',
            'username' => 'other_cashier',
            'password' => Hash::make('password'),
            'status' => 'active',
            'store_id' => 1,
        ]);
        $this->otherCashierUser->assignRole('kasir');

        $this->supervisorUser = User::create([
            'name' => 'Supervisor User',
            'username' => 'supervisor_user',
            'password' => Hash::make('password'),
            'status' => 'active',
            'store_id' => 1,
        ]);
        $this->supervisorUser->assignRole('supervisor');

        $this->managerUser = User::create([
            'name' => 'Manager User',
            'username' => 'manager_user',
            'password' => Hash::make('password'),
            'status' => 'active',
            'store_id' => 1,
        ]);
        $this->managerUser->assignRole('manajer_toko');

        $this->product = Product::create([
            'nama' => 'Aqua 600ml',
            'merek' => 'Danone',
            'barcode' => '8990001002',
            'stok' => 50,
            'harga' => 10000,
            'status' => 'active',
        ]);
    }

    public function test_cashier_can_open_adjust_and_close_cash_drawer(): void
    {
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/cash-drawer/open', [
                'opening_balance' => 100000,
                'opening_note' => 'Modal awal shift pagi.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.opening_balance', 100000)
            ->assertJsonPath('data.expected_cash', 100000);

        $sessionId = $response->json('data.id');

        $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson('/api/v1/cash-drawer/current')
            ->assertOk()
            ->assertJsonPath('data.id', $sessionId);

        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/cash-drawer/sessions/{$sessionId}/cash-in", [
                'amount' => 20000,
                'note' => 'Tambahan uang kecil.',
            ])
            ->assertOk()
            ->assertJsonPath('data.expected_cash', 120000)
            ->assertJsonPath('data.cash_in_total', 20000);

        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/cash-drawer/sessions/{$sessionId}/cash-out", [
                'amount' => 15000,
                'note' => 'Pembelian lakban.',
            ])
            ->assertOk()
            ->assertJsonPath('data.expected_cash', 105000)
            ->assertJsonPath('data.cash_out_total', 15000);

        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/cash-drawer/sessions/{$sessionId}/close", [
                'actual_closing_balance' => 104000,
                'closing_note' => 'Selisih kurang seribu.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.actual_closing_balance', 104000)
            ->assertJsonPath('data.difference', -1000);

        $this->assertDatabaseHas('cash_drawer_movements', [
            'cash_drawer_session_id' => $sessionId,
            'type' => 'opening',
            'amount' => 100000,
        ]);

        $this->assertDatabaseHas('cash_drawer_movements', [
            'cash_drawer_session_id' => $sessionId,
            'type' => 'close',
            'balance_before' => 105000,
            'balance_after' => 104000,
        ]);
    }

    public function test_cashier_cannot_open_second_active_cash_drawer(): void
    {
        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/cash-drawer/open', [
                'opening_balance' => 50000,
            ])
            ->assertCreated();

        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/cash-drawer/open', [
                'opening_balance' => 25000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cash_drawer');
    }

    public function test_cash_payment_is_recorded_to_active_cash_drawer(): void
    {
        $openResponse = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/cash-drawer/open', [
                'opening_balance' => 50000,
            ]);

        $sessionId = $openResponse->json('data.id');

        $transactionResponse = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'items' => [
                    ['product_id' => $this->product->id, 'quantity' => 2],
                ],
            ]);

        $transactionId = $transactionResponse->json('data.id');

        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$transactionId}/pay/cash", [
                'nominal_bayar' => 50000,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.metode_pembayaran', 'cash')
            ->assertJsonPath('data.cash_drawer_session_id', $sessionId);

        $this->assertDatabaseHas('cash_drawer_sessions', [
            'id' => $sessionId,
            'expected_cash' => 70000,
            'cash_sales_total' => 20000,
        ]);

        $this->assertDatabaseHas('cash_drawer_movements', [
            'cash_drawer_session_id' => $sessionId,
            'type' => 'cash_sale',
            'amount' => 20000,
            'reference_id' => $transactionId,
            'reference_type' => 'transaction',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'cash_drawer_cash_sale',
            'model_type' => CashDrawerSession::class,
            'model_id' => $sessionId,
        ]);
    }

    public function test_void_records_cash_refund_when_original_drawer_is_open(): void
    {
        $openResponse = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/cash-drawer/open', [
                'opening_balance' => 50000,
            ]);

        $sessionId = $openResponse->json('data.id');

        $transactionResponse = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/transactions', [
                'items' => [
                    ['product_id' => $this->product->id, 'quantity' => 1],
                ],
            ]);

        $transactionId = $transactionResponse->json('data.id');

        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$transactionId}/pay/cash", [
                'nominal_bayar' => 10000,
            ])
            ->assertOk();

        $this->actingAs($this->managerUser, 'sanctum')
            ->postJson("/api/v1/transactions/{$transactionId}/void", [
                'catatan_void' => 'Pembeli batal.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'void');

        $this->assertDatabaseHas('cash_drawer_sessions', [
            'id' => $sessionId,
            'expected_cash' => 50000,
            'cash_sales_total' => 10000,
            'cash_refunds_total' => 10000,
        ]);

        $this->assertDatabaseHas('cash_drawer_movements', [
            'cash_drawer_session_id' => $sessionId,
            'type' => 'cash_refund',
            'amount' => 10000,
            'reference_id' => $transactionId,
            'reference_type' => 'transaction',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'cash_drawer_cash_refund',
            'model_type' => CashDrawerSession::class,
            'model_id' => $sessionId,
        ]);
    }

    public function test_cashier_cannot_operate_other_cashiers_drawer(): void
    {
        $session = CashDrawerSession::create([
            'store_id' => 1,
            'user_id' => $this->otherCashierUser->id,
            'opening_balance' => 30000,
            'expected_cash' => 30000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson("/api/v1/cash-drawer/sessions/{$session->id}/cash-in", [
                'amount' => 10000,
                'note' => 'Tidak boleh.',
            ])
            ->assertForbidden();

        $this->actingAs($this->managerUser, 'sanctum')
            ->postJson("/api/v1/cash-drawer/sessions/{$session->id}/cash-in", [
                'amount' => 10000,
                'note' => 'Supervisor menambah kas.',
            ])
            ->assertOk()
            ->assertJsonPath('data.expected_cash', 40000);
    }
}
