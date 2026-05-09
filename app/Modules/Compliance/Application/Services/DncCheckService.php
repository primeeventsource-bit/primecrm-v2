<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Services;

use App\Core\Shared\TenantContext;
use App\Modules\Compliance\Domain\Enums\DncSource;
use App\Modules\Compliance\Domain\Enums\GuardrailRejectionCode;
use App\Modules\Compliance\Domain\Models\DncEntry;
use App\Modules\Compliance\Domain\ValueObjects\GuardrailDecision;

/**
 * First gate. Checks the phone hash against:
 *
 *   1. Tenant-specific DNC entries (internal DNC, customer requests)
 *   2. Global lists (federal, state, wireless, litigator) — tenant_id NULL
 *
 * The query joins both with a single OR predicate so we hit the
 * (tenant_id, phone_hash) index once per check. With the federal DNC
 * list at ~300M rows nationwide, this index matters.
 *
 * The most severe match wins — a number on both the federal and a
 * customer request list is rejected as litigator/federal rather than
 * customer request, since federal violations carry stiffer penalties.
 */
final class DncCheckService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function check(string $phoneHash): GuardrailDecision
    {
        $tenantId = $this->tenantContext->id();

        $entries = DncEntry::query()
            ->active()
            ->where('phone_hash', $phoneHash)
            ->where(function ($q) use ($tenantId): void {
                $q->whereNull('tenant_id'); // global federal/state/wireless lists

                if ($tenantId !== null) {
                    $q->orWhere('tenant_id', $tenantId);
                }
            })
            ->get();

        if ($entries->isEmpty()) {
            return GuardrailDecision::allow();
        }

        // Pick the highest-severity entry to report back.
        /** @var DncEntry $highest */
        $highest = $entries->sortByDesc(
            fn (DncEntry $e) => $e->source instanceof DncSource ? $e->source->severity() : 0,
        )->first();

        $code = match ($highest->source) {
            DncSource::FederalDnc => GuardrailRejectionCode::DncFederal,
            DncSource::StateDnc => GuardrailRejectionCode::DncState,
            DncSource::WirelessDnc => GuardrailRejectionCode::DncWireless,
            DncSource::LitigatorDnc => GuardrailRejectionCode::DncLitigator,
            DncSource::InternalDnc => GuardrailRejectionCode::DncInternal,
            DncSource::CustomerRequest => GuardrailRejectionCode::DncCustomerRequest,
            default => GuardrailRejectionCode::DncInternal,
        };

        return GuardrailDecision::reject(
            code: $code,
            reason: "Phone is on a DNC list ({$highest->source->value}).",
            metadata: [
                'dnc_source' => $highest->source?->value,
                'dnc_added_at' => $highest->created_at?->toIso8601String(),
                'dnc_reason' => $highest->reason,
                'all_matched_sources' => $entries->pluck('source')->map(fn ($s) => $s instanceof DncSource ? $s->value : $s)->all(),
            ],
        );
    }
}
