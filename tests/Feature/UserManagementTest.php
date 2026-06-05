<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $cashierUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles and permissions
        $this->seed(RolePermissionSeeder::class);

        // Create Admin User
        $this->adminUser = User::create([
            'name' => 'Admin POS',
            'username' => 'admin_pos',
            'email' => 'admin@pos.com',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->adminUser->assignRole('admin');

        // Create Cashier User
        $this->cashierUser = User::create([
            'name' => 'Cashier Budi',
            'username' => 'cashier_budi',
            'email' => 'budi@pos.com',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->cashierUser->assignRole('kasir');
    }

    public function test_admin_can_list_users(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'username',
                        'email',
                        'store_id',
                        'status',
                        'roles',
                        'permissions',
                    ]
                ],
                'links',
                'meta'
            ])
            ->assertJsonCount(2, 'data'); // Total 2 users seeded in setUp
    }

    public function test_admin_can_create_user_with_roles(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Siti Kasir',
                'username' => 'kasir_siti',
                'email' => 'siti@pos.com',
                'password' => 'password123',
                'roles' => ['kasir'],
                'status' => 'active',
                'store_id' => 1
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.username', 'kasir_siti')
            ->assertJsonPath('data.roles.0', 'kasir');

        $this->assertDatabaseHas('users', [
            'username' => 'kasir_siti',
            'email' => 'siti@pos.com',
            'status' => 'active',
        ]);

        $newUser = User::where('username', 'kasir_siti')->first();
        $this->assertTrue($newUser->hasRole('kasir'));
    }

    public function test_admin_can_update_user_profile_and_roles(): void
    {
        $targetUser = User::create([
            'name' => 'John Supervisor',
            'username' => 'spv_john',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $targetUser->assignRole('supervisor');

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson("/api/v1/users/{$targetUser->id}", [
                'name' => 'John Store Manager',
                'username' => 'mgr_john', // Changed username
                'email' => 'john@pos.com',
                'roles' => ['manajer_toko'], // Changed role
                'status' => 'active',
                'store_id' => 2
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.username', 'mgr_john')
            ->assertJsonPath('data.roles.0', 'manajer_toko');

        $this->assertDatabaseHas('users', [
            'id' => $targetUser->id,
            'username' => 'mgr_john',
            'name' => 'John Store Manager',
        ]);

        $this->assertDatabaseMissing('users', [
            'username' => 'spv_john'
        ]);

        $updatedUser = $targetUser->fresh();
        $this->assertTrue($updatedUser->hasRole('manajer_toko'));
        $this->assertFalse($updatedUser->hasRole('supervisor'));
    }

    public function test_admin_can_deactivate_user(): void
    {
        $targetUser = User::create([
            'name' => 'John Cashier',
            'username' => 'cashier_john',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $targetUser->assignRole('kasir');

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/users/{$targetUser->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'User berhasil dinonaktifkan.');

        $this->assertDatabaseHas('users', [
            'id' => $targetUser->id,
            'status' => 'inactive',
        ]);
    }

    public function test_cashier_cannot_access_user_crud_operations(): void
    {
        // 1. Cannot list
        $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertStatus(403);

        // 2. Cannot create
        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Hacker User',
                'username' => 'hacker',
                'password' => 'hacked123',
                'roles' => ['admin']
            ])
            ->assertStatus(403);

        // 3. Cannot delete/deactivate
        $this->actingAs($this->cashierUser, 'sanctum')
            ->deleteJson("/api/v1/users/{$this->adminUser->id}")
            ->assertStatus(403);
    }
}
