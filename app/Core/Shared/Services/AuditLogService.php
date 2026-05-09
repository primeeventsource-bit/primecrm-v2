<?php

declare(strict_types=1);

namespace App\Core\Shared\Services;

use App\Core\Shared\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Ramsey\Uuid\Uuid;

/**
 * Writes immutable audit log entries.
 *
 * Used by every controller / service that mutates sensitive data:
 * lead assignment, deal stage changes, payments, refunds, role changes,
 * DNC additions, etc. The entry is the legal record.
 *
 * Writes are best-effort within the request lifecycle but never block —
 * failure to write is logged but does not fail the action. This is a
 * conscious tradeoff: an audit log failure should not kill a sale.
 * Critical financial events (payment, refund, commission reversal)
 * should additionally publish a domain event for downstream subscribers.
 */
final class AuditLogService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function record(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $changes = null,
        ?array $context = null,
    ): void {
        $tenantId = $this->tenantContext->id();

        if ($tenantId === null) {
            // System-level actions outside any tenant context skip audit;
            // they should be logged elsewhere (system log, ops channel).
            logger()->warning('Audit log attempted with no tenant context', [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);

            return;
        }

        try {
            DB::table('audit_logs')->insert([
                'id' => Uuid::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'user_id' => $this->tenantContext->userId(),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'changes' => $changes !== null ? json_encode($changes) : null,
                'context' => $context !== null ? json_encode($context) : null,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'request_id' => request()?->header('X-Request-Id'),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Never let audit failure break the parent operation.
            logger()->error('Audit log write failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
