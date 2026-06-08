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
            'operate_cash_drawer',
            'manage_cash_drawer',
            'view_cash_drawer',
        ];

        // Create permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Create roles and assign existing permissions
        
        // Admin: has all permissions
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions($permissions);

        // Manajer Toko: can manage products, sales, reports, inventory, and cash drawer
        $manajerRole = Role::firstOrCreate(['name' => 'manajer_toko', 'guard_name' => 'web']);
        $manajerRole->syncPermissions([
            'manage_products',
            'manage_sales',
            'view_reports',
            'create_sales',
            'manage_inventory',
            'view_inventory',
            'operate_cash_drawer',
            'manage_cash_drawer',
            'view_cash_drawer',
        ]);

        // Supervisor: can manage products, sales, inventory visibility, and cash drawer
        $supervisorRole = Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
        $supervisorRole->syncPermissions([
            'manage_products',
            'manage_sales',
            'create_sales',
            'view_inventory',
            'operate_cash_drawer',
            'manage_cash_drawer',
            'view_cash_drawer',
        ]);

        // Kasir: can create sales and operate their own cash drawer
        $kasirRole = Role::firstOrCreate(['name' => 'kasir', 'guard_name' => 'web']);
        $kasirRole->syncPermissions([
            'create_sales',
            'operate_cash_drawer',
        ]);

        // Clear cache again at the end of seeder
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
