<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Authenticate user and return token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('username', 'password');

        $user = User::where('username', $credentials['username'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Username atau password salah.'
            ], 401);
        }

        // Check if user is active
        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Akun Anda dinonaktifkan. Silakan hubungi admin.'
            ], 403);
        }

        // Generate Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

        \App\Models\ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'login',
            'model_type' => User::class,
            'model_id' => $user->id,
            'description' => "User '{$user->name}' logged in successfully.",
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Login berhasil.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
            'data' => [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => new UserResource($user),
            ],
            'status' => 'success'
        ]);
    }

    /**
     * Log the user out (revoke token).
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        
        \App\Models\ActivityLog::log('logout', "User '{$user->name}' logged out.", $user);

        $user->currentAccessToken()->delete();

        return response()->json([
            'data' => null,
            'message' => 'Logout berhasil.',
            'status' => 'success'
        ]);
    }

    /**
     * Get the authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
            'data' => [
                'user' => new UserResource($request->user())
            ],
            'message' => 'Profil berhasil dimuat.',
            'status' => 'success'
        ]);
    }
}
