<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Modules\Tenant\Domain\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Note: tenant resolution is intentionally NOT scoped here — login
        // is the one place we query users without a resolved tenant context.
        // The user's tenant_id becomes the context for subsequent requests.
        $user = User::query()
            ->withoutTenantScope()
            ->where('email', $credentials['email'])
            ->where('status', 'active')
            ->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Sanctum SPA mode: establish a SESSION (not just a Bearer token).
        // Subsequent requests from the browser carry the session cookie,
        // and auth:sanctum middleware authenticates against it. Without
        // this, the API call succeeds, the browser redirects to /dashboard,
        // but the dashboard's auth middleware sees no session and bounces
        // back to /login.
        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        // Token still issued for non-browser API consumers (mobile, CLI).
        $token = $user->createToken('api', expiresAt: now()->addDays(30));

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'name' => $user->fullName(),
                'email' => $user->email,
                'role' => $user->role->value,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Clear the Sanctum token if it's an actual PersonalAccessToken
        // (mobile/CLI flow). Session-authenticated SPA requests return a
        // TransientToken which has no delete() method — guard the call.
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'name' => $user->fullName(),
            'email' => $user->email,
            'role' => $user->role->value,
            'permissions' => [], // populated by spatie/laravel-permission integration
        ]);
    }
}
