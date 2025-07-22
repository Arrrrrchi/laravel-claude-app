<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);

        $token = $user->createToken('auth-token', ['*'], now()->addDays(30))->plainTextToken;

        return response()->json([
            'message' => 'Registration successful',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at,
            ],
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => now()->addDays(30)->toISOString(),
        ], 201);
    }

    /**
     * Login user and create token
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $key = Str::transliterate(Str::lower($request->input('email')).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            
            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        $credentials = $request->only('email', 'password');

        if (!Auth::attempt($credentials)) {
            RateLimiter::hit($key, 300); // 5 minutes lockout

            throw ValidationException::withMessages([
                'email' => 'The provided credentials are incorrect.',
            ]);
        }

        RateLimiter::clear($key);

        /** @var User $user */
        $user = Auth::user();
        
        // Update last active timestamp
        $user->update(['last_active_at' => now()]);

        // Create token with abilities and expiration
        $abilities = ($user->is_admin ?? false) ? ['*'] : ['read', 'write'];
        $token = $user->createToken(
            name: 'auth-token',
            abilities: $abilities,
            expiresAt: now()->addDays(30)
        )->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar ?? null,
                'bio' => $user->bio ?? null,
                'website' => $user->website ?? null,
                'social_links' => $user->social_links ?? null,
                'is_admin' => (bool) ($user->is_admin ?? false),
                'last_active_at' => $user->last_active_at,
                'created_at' => $user->created_at,
            ],
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => now()->addDays(30)->toISOString(),
        ]);
    }

    /**
     * Get the authenticated user
     */
    public function user(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'avatar' => $user->avatar ?? null,
                'bio' => $user->bio ?? null,
                'website' => $user->website ?? null,
                'social_links' => $user->social_links ?? null,
                'is_admin' => (bool) ($user->is_admin ?? false),
                'last_active_at' => $user->last_active_at,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
        ]);
    }

    /**
     * Logout user (revoke current token)
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        
        // Revoke the current token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout successful',
        ]);
    }

    /**
     * Logout from all devices (revoke all tokens)
     */
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        
        // Revoke all user's tokens
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Logged out from all devices successfully',
        ]);
    }

    /**
     * Get all user's tokens
     */
    public function tokens(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tokens = $user->tokens()->get()->map(function ($token) {
            return [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
                'last_used_at' => $token->last_used_at,
                'is_current' => $token->id === request()->user()->currentAccessToken()->id,
            ];
        });

        return response()->json([
            'tokens' => $tokens,
            'total' => $tokens->count(),
        ]);
    }

    /**
     * Revoke specific token
     */
    public function revokeToken(Request $request): JsonResponse
    {
        $request->validate([
            'token_id' => ['required', 'integer', 'exists:personal_access_tokens,id'],
        ]);

        /** @var User $user */
        $user = $request->user();
        
        $token = $user->tokens()->where('id', $request->input('token_id'))->first();

        if (!$token) {
            return response()->json([
                'message' => 'Token not found or does not belong to you',
            ], 404);
        }

        // Prevent revoking current token via this endpoint
        if ($token->id === $request->user()->currentAccessToken()->id) {
            return response()->json([
                'message' => 'Cannot revoke current token. Use logout endpoint instead.',
            ], 422);
        }

        $token->delete();

        return response()->json([
            'message' => 'Token revoked successfully',
        ]);
    }

    /**
     * Refresh current token (create new token and revoke current)
     */
    public function refresh(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        
        $currentToken = $request->user()->currentAccessToken();
        
        // Create new token with same abilities
        $abilities = ($user->is_admin ?? false) ? ['*'] : ['read', 'write'];
        $newToken = $user->createToken(
            name: 'auth-token-refreshed',
            abilities: $abilities,
            expiresAt: now()->addDays(30)
        )->plainTextToken;

        // Revoke current token
        $currentToken->delete();

        return response()->json([
            'message' => 'Token refreshed successfully',
            'token' => $newToken,
            'token_type' => 'Bearer',
            'expires_at' => now()->addDays(30)->toISOString(),
        ]);
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'avatar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'social_links' => ['sometimes', 'nullable', 'array'],
            'social_links.twitter' => ['sometimes', 'nullable', 'string', 'max:255'],
            'social_links.linkedin' => ['sometimes', 'nullable', 'string', 'max:255'],
            'social_links.github' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();
        
        $user->update($request->only([
            'name', 'avatar', 'bio', 'website', 'social_links'
        ]));

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar ?? null,
                'bio' => $user->bio ?? null,
                'website' => $user->website ?? null,
                'social_links' => $user->social_links ?? null,
                'is_admin' => (bool) ($user->is_admin ?? false),
                'last_active_at' => $user->last_active_at,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (!Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $user->update([
            'password' => Hash::make($request->input('password')),
        ]);

        // Optionally revoke all other tokens for security
        $currentTokenId = $request->user()->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json([
            'message' => 'Password changed successfully. All other sessions have been terminated.',
        ]);
    }
}