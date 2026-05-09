<?php

declare(strict_types=1);

namespace App\Core\Shared\Http\Middleware;

use App\Core\Shared\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active tenant from the authenticated user and binds it
 * to the TenantContext for the duration of the request.
 *
 * Must run AFTER auth middleware. Subdomain or header-based resolution
 * could be added here for tenant-per-subdomain deployments.
 */
final class ResolveTenant
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || empty($user->tenant_id)) {
            return response()->json([
                'error' => 'Tenant context could not be resolved.',
            ], 403);
        }

        $this->tenantContext->set(
            tenantId: $user->tenant_id,
            userId: $user->id,
        );

        return $next($request);
    }
}
