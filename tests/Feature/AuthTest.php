<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed roles and permissions for tests
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::create([
            'name' => 'Budi Kasir',
            'username' => 'cashier_budi',
            'email' => 'budi@pos.com',
            'password' => Hash::make('password'),
            'status' => 'active',
            'store_id' => 1,
        ]);
        $user->assignRole('kasir');

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'cashier_budi',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'access_token',
                'token_type',
                'user' => [
                    'id',
                    'name',
                    'username',
                    'email',
                    'store_id',
                    'status',
                    'roles',
                    'permissions',
                ]
            ])
            ->assertJsonPath('user.username', 'cashier_budi')
            ->assertJsonPath('user.roles.0', 'kasir')
            ->assertJsonPath('user.permissions.0', 'create_sales');

        $this->assertNotEmpty($response->json('access_token'));
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        User::create([
            'name' => 'Budi Kasir',
            'username' => 'cashier_budi',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'cashier_budi',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Username atau password salah.');
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::create([
            'name' => 'Budi Kasir',
            'username' => 'cashier_budi',
            'password' => Hash::make('password'),
            'status' => 'inactive',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'cashier_budi',
            'password' => 'password',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Akun Anda dinonaktifkan. Silakan hubungi admin.');
    }

    public function test_authenticated_user_can_get_profile(): void
    {
        $user = User::create([
            'name' => 'Budi Kasir',
            'username' => 'cashier_budi',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $user->assignRole('kasir');

        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('user.username', 'cashier_budi');
    }

    public function test_unauthenticated_user_cannot_get_profile(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::create([
            'name' => 'Budi Kasir',
            'username' => 'cashier_budi',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Logout berhasil.');

        // Clear cached auth guards so the container does not reuse the authenticated user instance
        $this->app['auth']->forgetGuards();

        // Attempt to access me again should be unauthenticated
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }
}
