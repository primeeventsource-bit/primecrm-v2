<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Actions;

use App\Core\Shared\Services\AuditLogService;
use App\Core\Shared\Services\PhoneNormalizer;
use App\Modules\Compliance\Domain\Enums\ConsentType;
use App\Modules\Compliance\Domain\Models\ConsentRecord;
use App\Modules\Lead\Domain\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Captures express written consent.
 *
 * The recording_url, source_url, source_ip, user_agent, and
 * consent_text_snapshot fields are critical: they're how we prove the
 * consent was real if a TCPA suit lands. The action requires whichever
 * of those is appropriate for the source — verbal recordings need a
 * recording_url; web forms need source_url + IP + UA + the disclosure
 * text shown to the user.
 *
 * Side effect: stamps lead.has_express_consent and lead.consent_at when
 * the consent type is autodialer (the most operationally important).
 */
final class RecordConsentAction
{
    public function __construct(
        private readonly PhoneNormalizer $phoneNormalizer,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array<string, mixed>|null  $consentTextSnapshot
     */
    public function execute(
        string $rawPhone,
        ConsentType $consentType,
        string $source,
        ?string $leadId = null,
        ?string $sourceUrl = null,
        ?string $sourceIp = null,
        ?string $userAgent = null,
        ?string $recordingUrl = null,
        ?array $consentTextSnapshot = null,
        ?\DateTimeImmutable $consentedAt = null,
    ): ?ConsentRecord {
        $normalized = $this->phoneNormalizer->normalizeAndHash($rawPhone);

        if ($normalized === null) {
            return null;
        }

        [$phone, $hash] = $normalized;

        $consent = DB::transaction(function () use (
            $phone, $hash, $consentType, $source, $leadId, $sourceUrl,
            $sourceIp, $userAgent, $recordingUrl, $consentTextSnapshot, $consentedAt
        ) {
            $record = ConsentRecord::query()->create([
                'lead_id' => $leadId,
                'phone' => $phone,
                'phone_hash' => $hash,
                'consent_type' => $consentType->value,
                'source' => $source,
                'source_url' => $sourceUrl,
                'source_ip' => $sourceIp,
                'user_agent' => $userAgent,
                'recording_url' => $recordingUrl,
                'consent_text_snapshot' => $consentTextSnapshot,
                'consented_at' => $consentedAt ?? now(),
            ]);

            if ($consentType === ConsentType::Autodialer) {
                Lead::query()
                    ->where('phone_hash', $hash)
                    ->update([
                        'has_express_consent' => true,
                        'consent_at' => $record->consented_at,
                    ]);
            }

            return $record;
        });

        $this->audit->record(
            action: 'compliance.consent_recorded',
            entityType: 'consent_record',
            entityId: $consent->id,
            context: [
                'consent_type' => $consentType->value,
                'source' => $source,
                'has_recording' => $recordingUrl !== null,
            ],
        );

        return $consent;
    }

    public function revoke(string $consentId, string $reason): ?ConsentRecord
    {
        $consent = ConsentRecord::query()->find($consentId);

        if ($consent === null || $consent->revoked_at !== null) {
            return $consent;
        }

        $consent->update([
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ]);

        // If this was the most recent autodialer consent for the number,
        // un-flag the lead. Other unrevoked consent records on the same
        // number keep the flag intact.
        $stillActive = ConsentRecord::query()
            ->forPhone($consent->phone_hash)
            ->ofType(\App\Modules\Compliance\Domain\Enums\ConsentType::Autodialer)
            ->active()
            ->exists();

        if (! $stillActive) {
            Lead::query()
                ->where('phone_hash', $consent->phone_hash)
                ->update(['has_express_consent' => false]);
        }

        $this->audit->record(
            action: 'compliance.consent_revoked',
            entityType: 'consent_record',
            entityId: $consent->id,
            context: ['reason' => $reason],
        );

        return $consent->fresh();
    }
}
