<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ActivityLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RolePermissionTest extends TestCase
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

    public function test_cashier_cannot_access_role_permission_endpoints(): void
    {
        // 1. Cannot list roles
        $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson('/api/v1/roles')
            ->assertStatus(403);

        // 2. Cannot list permissions
        $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson('/api/v1/permissions')
            ->assertStatus(403);

        // 3. Cannot assign permission
        $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/roles/kasir/permissions', [
                'permission' => 'manage_users',
            ])
            ->assertStatus(403);

        // 4. Cannot revoke permission
        $this->actingAs($this->cashierUser, 'sanctum')
            ->deleteJson('/api/v1/roles/kasir/permissions/create_sales')
            ->assertStatus(403);
    }

    public function test_admin_can_list_roles_and_permissions(): void
    {
        // List Roles
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/roles');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'guard_name',
                        'permissions',
                    ]
                ]
            ]);

        // List Permissions
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/permissions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'guard_name',
                    ]
                ]
            ]);
    }

    public function test_admin_can_assign_permission_to_role(): void
    {
        $role = Role::where('name', 'kasir')->first();
        $this->assertFalse($role->hasPermissionTo('manage_products'));

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/roles/{$role->id}/permissions", [
                'permission' => 'manage_products',
            ]);

        $response->assertStatus(200);
        $this->assertTrue($role->fresh()->hasPermissionTo('manage_products'));

        // Verify Activity Log is recorded
        $this->assertTrue(
            ActivityLog::where('action', 'assign_permission')
                ->where('description', "Permission 'manage_products' was assigned to role 'kasir'.")
                ->exists()
        );
    }

    public function test_admin_can_assign_multiple_permissions_to_role(): void
    {
        $role = Role::where('name', 'kasir')->first();
        $this->assertFalse($role->hasPermissionTo('manage_products'));
        $this->assertFalse($role->hasPermissionTo('manage_users'));

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/roles/{$role->name}/permissions", [
                'permissions' => ['manage_products', 'manage_users'],
            ]);

        $response->assertStatus(200);
        $this->assertTrue($role->fresh()->hasPermissionTo('manage_products'));
        $this->assertTrue($role->fresh()->hasPermissionTo('manage_users'));
    }

    public function test_admin_can_revoke_permission_from_role(): void
    {
        $role = Role::where('name', 'kasir')->first();
        $this->assertTrue($role->hasPermissionTo('create_sales'));

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/roles/{$role->id}/permissions/create_sales");

        $response->assertStatus(200);
        $this->assertFalse($role->fresh()->hasPermissionTo('create_sales'));

        // Verify Activity Log is recorded
        $this->assertTrue(
            ActivityLog::where('action', 'revoke_permission')
                ->where('description', "Permission 'create_sales' was revoked from role 'kasir'.")
                ->exists()
        );
    }

    public function test_role_permission_not_found_handling(): void
    {
        // 1. Role not found
        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/roles/non_existent_role/permissions', [
                'permission' => 'manage_users',
            ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Role tidak ditemukan.');

        // 2. Permission not found
        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/roles/kasir/permissions', [
                'permission' => 'non_existent_permission',
            ])
            ->assertStatus(404)
            ->assertJsonPath('message', "Permission 'non_existent_permission' tidak ditemukan.");
    }

    public function test_revoke_unassigned_permission_returns_bad_request(): void
    {
        $role = Role::where('name', 'kasir')->first();
        $this->assertFalse($role->hasPermissionTo('manage_users'));

        $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/roles/{$role->id}/permissions/manage_users")
            ->assertStatus(400)
            ->assertJsonPath('message', "Role 'kasir' tidak memiliki permission 'manage_users'.");
    }
}
