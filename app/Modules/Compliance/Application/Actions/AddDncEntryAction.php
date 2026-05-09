<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Actions;

use App\Core\Shared\Services\AuditLogService;
use App\Core\Shared\Services\PhoneNormalizer;
use App\Core\Shared\TenantContext;
use App\Modules\Compliance\Domain\Enums\DncSource;
use App\Modules\Compliance\Domain\Models\DncEntry;
use App\Modules\Lead\Domain\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Add a phone to the DNC list. Runs the same way regardless of source,
 * but the source determines tenant scope:
 *   - Federal/state/wireless are global → tenant_id NULL.
 *   - Internal/customer/litigator are tenant-scoped.
 *
 * Side effect: any matching Lead in the same tenant is flagged
 * is_on_dnc=true so the dialer's coarse pre-filter excludes it
 * without consulting dnc_entries on every dial.
 */
final class AddDncEntryAction
{
    public function __construct(
        private readonly PhoneNormalizer $phoneNormalizer,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $audit,
    ) {}

    public function execute(
        string $rawPhone,
        DncSource $source,
        ?string $reason = null,
        ?string $addedBy = null,
        ?\DateTimeImmutable $effectiveDate = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): ?DncEntry {
        $normalized = $this->phoneNormalizer->normalizeAndHash($rawPhone);

        if ($normalized === null) {
            return null;
        }

        [$phone, $hash] = $normalized;

        $tenantId = $source->isGlobal() ? null : $this->tenantContext->id();

        $entry = DB::transaction(function () use ($tenantId, $phone, $hash, $source, $reason, $addedBy, $effectiveDate, $expiresAt) {
            $entry = DncEntry::query()->create([
                'tenant_id' => $tenantId,
                'phone' => $phone,
                'phone_hash' => $hash,
                'source' => $source->value,
                'reason' => $reason,
                'added_by' => $addedBy ?? $this->tenantContext->userId() ?? 'system',
                'effective_date' => $effectiveDate?->format('Y-m-d'),
                'expires_at' => $expiresAt?->format('Y-m-d'),
            ]);

            if ($tenantId !== null) {
                Lead::query()
                    ->where('tenant_id', $tenantId)
                    ->where('phone_hash', $hash)
                    ->update(['is_on_dnc' => true]);
            } else {
                // Global list — flag matching leads across all tenants.
                Lead::query()
                    ->withoutTenantScope()
                    ->where('phone_hash', $hash)
                    ->update(['is_on_dnc' => true]);
            }

            return $entry;
        });

        $this->audit->record(
            action: 'compliance.dnc_added',
            entityType: 'dnc_entry',
            entityId: $entry->id,
            context: [
                'source' => $source->value,
                'reason' => $reason,
                'is_global' => $tenantId === null,
            ],
        );

        return $entry;
    }
}
