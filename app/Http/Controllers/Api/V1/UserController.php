<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = User::query()->with(['roles', 'permissions']);

        // Search by name, username, or email
        if ($request->filled('q')) {
            $search = $request->input('q');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($request->filled('role')) {
            $role = $request->input('role');
            $query->role($role);
        }

        // Filter by status (active/inactive)
        if ($request->filled('status')) {
            $status = $request->input('status');
            $query->where('status', $status);
        }

        $users = $query->paginate($request->input('per_page', 15));

        return UserResource::collection($users);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(UserStoreRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'status' => $request->status ?? 'active',
            'store_id' => $request->store_id,
        ]);

        $user->syncRoles($request->roles);

        return response()->json([
            'message' => 'User berhasil ditambahkan.',
            'data' => new UserResource($user)
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user): JsonResponse
    {
        $user->load(['roles', 'permissions']);
        return response()->json([
            'data' => new UserResource($user)
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UserUpdateRequest $request, User $user): JsonResponse
    {
        $userData = [
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'status' => $request->status,
            'store_id' => $request->store_id,
        ];

        if ($request->filled('password')) {
            $userData['password'] = Hash::make($request->password);
        }

        $user->update($userData);
        $user->syncRoles($request->roles);

        return response()->json([
            'message' => 'User berhasil diperbarui.',
            'data' => new UserResource($user->fresh(['roles', 'permissions']))
        ]);
    }

    /**
     * Remove the specified resource from storage (Deactivate).
     */
    public function destroy(User $user): JsonResponse
    {
        // For POS audits, we deactivate rather than hard delete users
        $user->update(['status' => 'inactive']);

        return response()->json([
            'message' => 'User berhasil dinonaktifkan.'
        ]);
    }
}
