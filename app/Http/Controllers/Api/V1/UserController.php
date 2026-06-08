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
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with(['roles', 'permissions']);

        // Search by name, username, or email
        $search = $request->input('search') ?? $request->input('q');
        if (!empty($search)) {
            $keyword = (string) $search;
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('username', 'like', "%{$keyword}%")
                  ->orWhere('email', 'like', "%{$keyword}%");
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

        // Sorting
        $sortBy = $request->input('sort_by', 'name');
        $sortOrder = strtolower($request->input('sort_order', 'asc')) === 'desc' ? 'desc' : 'asc';
        
        $allowedSortColumns = ['name', 'username', 'email', 'created_at'];
        if (in_array($sortBy, $allowedSortColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('name', 'asc');
        }

        $users = $query->paginate($request->input('per_page', 15));
        
        // Transform the paginated collection items to UserResource
        $users->getCollection()->transform(fn($user) => new UserResource($user));

        return $this->responsePaginated($users);
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

        return $this->responseSuccess(
            new UserResource($user),
            'User berhasil ditambahkan.',
            201
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user): JsonResponse
    {
        $user->load(['roles', 'permissions']);
        return $this->responseSuccess(new UserResource($user), 'Detail user berhasil dimuat.');
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

        return $this->responseSuccess(
            new UserResource($user->fresh(['roles', 'permissions'])),
            'User berhasil diperbarui.'
        );
    }

    /**
     * Remove the specified resource from storage (Deactivate).
     */
    public function destroy(User $user): JsonResponse
    {
        // For POS audits, we deactivate rather than hard delete users
        $user->update(['status' => 'inactive']);

        return $this->responseSuccess(new UserResource($user), 'User berhasil dinonaktifkan.');
    }
}
