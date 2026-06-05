<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Define permissions
        $permissions = [
            'manage_users',
            'manage_products',
            'manage_sales',
            'view_reports',
            'create_sales',
            'manage_inventory',
            'view_inventory',
        ];

        // Create permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Create roles and assign existing permissions
        
        // Admin: has all permissions
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions($permissions);

        // Manajer Toko: can manage products, sales, view reports, create sales, and manage/view inventory
        $manajerRole = Role::firstOrCreate(['name' => 'manajer_toko', 'guard_name' => 'web']);
        $manajerRole->syncPermissions([
            'manage_products',
            'manage_sales',
            'view_reports',
            'create_sales',
            'manage_inventory',
            'view_inventory',
        ]);

        // Supervisor: can manage products, sales, create sales, and view inventory
        $supervisorRole = Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
        $supervisorRole->syncPermissions([
            'manage_products',
            'manage_sales',
            'create_sales',
            'view_inventory',
        ]);

        // Kasir: can only create sales and view products
        $kasirRole = Role::firstOrCreate(['name' => 'kasir', 'guard_name' => 'web']);
        $kasirRole->syncPermissions([
            'create_sales'
        ]);

        // Clear cache again at the end of seeder
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
