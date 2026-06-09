<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionController extends Controller
{
    /**
     * List all roles with their assigned permissions.
     */
    public function indexRoles(): JsonResponse
    {
        $roles = Role::with('permissions')->get();
        return $this->responseSuccess($roles, 'Daftar role dan permission berhasil dimuat.');
    }

    /**
     * List all available permissions.
     */
    public function indexPermissions(): JsonResponse
    {
        $permissions = Permission::all();
        return $this->responseSuccess($permissions, 'Daftar permission berhasil dimuat.');
    }

    /**
     * Assign permission(s) to a role.
     */
    public function assignPermission(Request $request, string $roleIdOrName): JsonResponse
    {
        // Resolve role
        $role = is_numeric($roleIdOrName)
            ? Role::find($roleIdOrName)
            : Role::where('name', $roleIdOrName)->first();

        if (!$role) {
            return response()->json([
                'status' => 'error',
                'message' => 'Role tidak ditemukan.'
            ], 404);
        }

        // Validate permission input
        $request->validate([
            'permission' => 'required_without:permissions|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string'
        ]);

        $permissionNames = [];
        if ($request->has('permissions')) {
            $permissionNames = $request->input('permissions');
        } else {
            $permissionNames[] = $request->input('permission');
        }

        $resolvedPermissions = [];
        foreach ($permissionNames as $name) {
            $permission = Permission::where('name', $name)->first();
            if (!$permission) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Permission '{$name}' tidak ditemukan."
                ], 404);
            }
            $resolvedPermissions[] = $permission;
        }

        // Assign/give permissions
        foreach ($resolvedPermissions as $perm) {
            if (!$role->hasPermissionTo($perm)) {
                $role->givePermissionTo($perm);
                
                ActivityLog::log(
                    'assign_permission',
                    "Permission '{$perm->name}' was assigned to role '{$role->name}'.",
                    $role,
                    ['role' => $role->name, 'permission' => $perm->name]
                );
            }
        }

        // Clear permission cache
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $this->responseSuccess(
            $role->load('permissions'),
            'Permission berhasil ditambahkan ke role.'
        );
    }

    /**
     * Revoke/remove a permission from a role.
     */
    public function revokePermission(Request $request, string $roleIdOrName, string $permissionIdOrName): JsonResponse
    {
        // Resolve role
        $role = is_numeric($roleIdOrName)
            ? Role::find($roleIdOrName)
            : Role::where('name', $roleIdOrName)->first();

        if (!$role) {
            return response()->json([
                'status' => 'error',
                'message' => 'Role tidak ditemukan.'
            ], 404);
        }

        // Resolve permission
        $permission = is_numeric($permissionIdOrName)
            ? Permission::find($permissionIdOrName)
            : Permission::where('name', $permissionIdOrName)->first();

        if (!$permission) {
            return response()->json([
                'status' => 'error',
                'message' => 'Permission tidak ditemukan.'
            ], 404);
        }

        // Check if role has the permission
        if (!$role->hasPermissionTo($permission)) {
            return response()->json([
                'status' => 'error',
                'message' => "Role '{$role->name}' tidak memiliki permission '{$permission->name}'."
            ], 400);
        }

        // Revoke permission
        $role->revokePermissionTo($permission);

        ActivityLog::log(
            'revoke_permission',
            "Permission '{$permission->name}' was revoked from role '{$role->name}'.",
            $role,
            ['role' => $role->name, 'permission' => $permission->name]
        );

        // Clear permission cache
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $this->responseSuccess(
            $role->load('permissions'),
            'Permission berhasil dihapus dari role.'
        );
    }
}
