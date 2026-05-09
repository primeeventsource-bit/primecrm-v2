<?php

declare(strict_types=1);

namespace App\Core\Shared\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Inertia middleware: shares props into every page response.
 *
 * Authoritative source for `auth.user`, the CSRF token, and the
 * broadcasting client config the Vue app needs at boot.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'tenant_id' => $user->tenant_id,
                    'name' => $user->fullName(),
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'permissions' => [],
                ],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'csrf' => csrf_token(),
            // Echo client config — same shape consumed by resources/js/echo.ts
            'echo' => [
                'host' => env('PUSHER_HOST', ''),
                'key' => env('PUSHER_APP_KEY', ''),
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
            ],
        ]);
    }
}
