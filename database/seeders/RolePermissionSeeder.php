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
            'view_users',
            'manage_products',
            'view_products',
            'manage_sales',
            'view_reports',
            'create_sales',
            'view_sales',
            'manage_inventory',
            'view_inventory',
            'manage_suppliers',
            'view_suppliers',
            'view_audit_logs',
            'operate_cash_drawer',
            'manage_cash_drawer',
            'view_cash_drawer',
            'view_purchase',
            'manage_purchase',
        ];

        // Create permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Create roles and assign existing permissions
        
        // Admin: has all permissions
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions($permissions);

        // Manajer Toko: can manage users, products, sales, reports, inventory, suppliers, and cash drawer
        $manajerRole = Role::firstOrCreate(['name' => 'manajer_toko', 'guard_name' => 'web']);
        $manajerRole->syncPermissions([
            'manage_users',
            'view_users',
            'view_audit_logs',
            'manage_products',
            'view_products',
            'manage_sales',
            'view_reports',
            'create_sales',
            'view_sales',
            'manage_inventory',
            'view_inventory',
            'manage_suppliers',
            'view_suppliers',
            'operate_cash_drawer',
            'manage_cash_drawer',
            'view_cash_drawer',
            'view_purchase',
            'manage_purchase',
        ]);

        // Supervisor: can only read (view) products, inventory, sales, suppliers, and cash drawer
        $supervisorRole = Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
        $supervisorRole->syncPermissions([
            'view_products',
            'view_inventory',
            'view_sales',
            'view_suppliers',
            'view_cash_drawer',
            'view_purchase',
        ]);

        // Kasir: can view products, create sales, and operate their own cash drawer
        $kasirRole = Role::firstOrCreate(['name' => 'kasir', 'guard_name' => 'web']);
        $kasirRole->syncPermissions([
            'view_products',
            'create_sales',
            'operate_cash_drawer',
        ]);

        // Clear cache again at the end of seeder
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
