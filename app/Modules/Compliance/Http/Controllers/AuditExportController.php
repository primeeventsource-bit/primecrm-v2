<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Core\Shared\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AG-audit-ready compliance evidence export.
 *
 *   GET /api/compliance/audit-export/owner/{ownerId}
 *   GET /api/compliance/audit-export/agreement/{dealId}
 *
 * Returns a complete, structured JSON dossier suitable for handing
 * to compliance counsel or regulators. The package is designed to
 * answer the question: "Show me the evidence trail for this owner /
 * this listing fee."
 *
 * Sections in the export:
 *   - manifest       Identity of who exported, when, scope
 *   - owner          Lead row + contact history posture (DNC, consent)
 *   - agreements     Every listing-fee deal + payment + recording markers
 *   - recordings     Compliance recordings with disclosure marker matrix
 *   - distribution   Every partner-site push, status, external URL
 *   - inquiries      Every rental inquiry + response history (proof of work)
 *   - bookings       Every booking that came through this owner's listings
 *                    (proof of service delivery)
 *   - financial      Payments, refunds, chargebacks — the money trail
 *   - cases          Open + closed refund cases + chargeback cases
 *   - communications System Notes flagged 'system' + AI red-zone alerts
 *
 * The export is read-only and bypasses no security: every section
 * filters by tenant_id and the requested owner/agreement scope.
 * Supervisor role required (gated at the route layer).
 *
 * Format: JSON (one big payload). A future enhancement may zip
 * recordings into the export; today recording_url is included so
 * the regulator can pull each one out-of-band.
 */
final class AuditExportController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function forOwner(Request $request, string $ownerId): JsonResponse
    {
        $tenantId = $this->tenantContext->id();

        $owner = DB::table('leads')
            ->where('tenant_id', $tenantId)
            ->where('id', $ownerId)
            ->first();

        if ($owner === null) {
            return response()->json(['message' => 'Owner not found'], 404);
        }

        // Every agreement the owner has signed (or attempted to sign).
        $agreements = DB::table('deals')
            ->where('tenant_id', $tenantId)
            ->where('lead_id', $ownerId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();

        $agreementIds = $agreements->pluck('id')->all();
        $payload = $this->assembleForAgreements($tenantId, $agreementIds, $owner, $request->user());

        return response()->json($payload, 200, [
            'Content-Disposition' => sprintf(
                'attachment; filename="audit-export-owner-%s.json"',
                $ownerId,
            ),
        ]);
    }

    public function forAgreement(Request $request, string $dealId): JsonResponse
    {
        $tenantId = $this->tenantContext->id();

        $deal = DB::table('deals')
            ->where('tenant_id', $tenantId)
            ->where('id', $dealId)
            ->first();

        if ($deal === null) {
            return response()->json(['message' => 'Agreement not found'], 404);
        }

        $owner = DB::table('leads')
            ->where('tenant_id', $tenantId)
            ->where('id', $deal->lead_id)
            ->first();

        $payload = $this->assembleForAgreements($tenantId, [$deal->id], $owner, $request->user());

        return response()->json($payload, 200, [
            'Content-Disposition' => sprintf(
                'attachment; filename="audit-export-agreement-%s.json"',
                $dealId,
            ),
        ]);
    }

    /**
     * @param  list<string>  $agreementIds
     */
    private function assembleForAgreements(
        string $tenantId,
        array $agreementIds,
        object $owner,
        ?object $actor,
    ): array {
        // --- Manifest ---
        $now = Carbon::now()->toIso8601String();
        $manifest = [
            'export_format_version' => '1.0',
            'generated_at' => $now,
            'generated_by' => [
                'user_id' => $actor->id ?? null,
                'name' => trim(($actor->first_name ?? '').' '.($actor->last_name ?? '')) ?: null,
                'role' => $actor->role ?? null,
            ],
            'scope' => [
                'tenant_id' => $tenantId,
                'owner_id' => $owner->id,
                'agreement_ids' => $agreementIds,
                'agreement_count' => count($agreementIds),
            ],
        ];

        // --- Owner ---
        $consentRows = DB::table('consent_records')
            ->where('tenant_id', $tenantId)
            ->where('phone_hash', $owner->phone_hash)
            ->orderByDesc('consented_at')
            ->get([
                'id', 'consent_type', 'source', 'source_url',
                'source_ip', 'recording_url', 'consent_text_snapshot',
                'consented_at', 'revoked_at', 'revocation_reason',
            ]);

        $dncRows = DB::table('dnc_entries')
            ->where(function ($q) use ($tenantId): void {
                $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })
            ->where('phone_hash', $owner->phone_hash)
            ->orderByDesc('effective_date')
            ->get([
                'source', 'reason', 'added_by', 'effective_date', 'expires_at',
            ]);

        $ownerSection = [
            'id' => $owner->id,
            'name' => trim(($owner->first_name ?? '').' '.($owner->last_name ?? ''))
                ?: '(unnamed owner)',
            'phone' => $owner->phone,
            'phone_hash' => $owner->phone_hash,
            'email' => $owner->email,
            'state' => $owner->state,
            'city' => $owner->city,
            'has_express_consent' => (bool) $owner->has_express_consent,
            'consent_at' => $owner->consent_at,
            'is_on_dnc' => (bool) $owner->is_on_dnc,
            'consent_records' => $consentRows->values(),
            'dnc_entries' => $dncRows->values(),
        ];

        // --- Agreements + nested recordings/payments per agreement ---
        $agreementRows = empty($agreementIds) ? collect()
            : DB::table('deals')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $agreementIds)
                ->orderByDesc('created_at')
                ->get();

        $recordings = empty($agreementIds) ? collect()
            : DB::table('compliance_recordings as cr')
                ->leftJoin('calls as c', 'c.id', '=', 'cr.call_id')
                ->leftJoin('users as u', 'u.id', '=', 'cr.user_id')
                ->where('cr.tenant_id', $tenantId)
                ->whereIn('cr.deal_id', $agreementIds)
                ->orderByDesc('cr.created_at')
                ->get([
                    'cr.id', 'cr.deal_id', 'cr.call_id', 'cr.compliance_status',
                    'cr.tcpa_consent_captured', 'cr.recording_disclosure_made',
                    'cr.no_guarantee_disclosure_made', 'cr.refund_policy_disclosure_made',
                    'cr.total_fee_stated_clearly',
                    'cr.disclosure_timestamps',
                    'cr.review_notes', 'cr.reviewed_at', 'cr.created_at',
                    'c.recording_url', 'c.recording_s3_path',
                    'c.duration_seconds', 'c.transcription_text',
                    'u.first_name as agent_first', 'u.last_name as agent_last',
                ]);

        $payments = empty($agreementIds) ? collect()
            : DB::table('payments')
                ->where('tenant_id', $tenantId)
                ->whereIn('deal_id', $agreementIds)
                ->orderByDesc('created_at')
                ->get([
                    'id', 'deal_id', 'amount', 'currency', 'type', 'status',
                    'provider', 'provider_payment_id', 'card_brand',
                    'card_last_four', 'authorized_at', 'captured_at',
                    'cleared_at', 'refunded_at', 'failure_reason', 'created_at',
                ]);

        // Per-agreement view assembling the disclosure matrix.
        $agreementSection = $agreementRows->map(fn ($d) => [
            'id' => $d->id,
            'stage' => $d->stage,
            'agreement_status' => $d->agreement_status ?? null,
            'payment_status' => $d->payment_status ?? null,
            'listing_fee' => $d->listing_fee !== null ? (float) $d->listing_fee : null,
            'listing_fee_collected' => $d->listing_fee_collected !== null
                ? (float) $d->listing_fee_collected : null,
            'tcpa_disclosure_completed' => (bool) ($d->tcpa_disclosure_completed ?? false),
            'tcpa_disclosure_completed_at' => $d->tcpa_disclosure_completed_at ?? null,
            'verification_call_completed' => (bool) ($d->verification_call_completed ?? false),
            'verification_call_completed_at' => $d->verification_call_completed_at ?? null,
            'agreement_signed_at' => $d->agreement_signed_at ?? null,
            'refund_window_expires_at' => $d->refund_window_expires_at ?? null,
            'term_expires_at' => $d->term_expires_at ?? null,
            'closed_at' => $d->closed_at,
            'created_at' => $d->created_at,
        ])->values();

        // --- Distribution (proof we marketed) ---
        $listingIds = empty($agreementIds) ? []
            : DB::table('listings')
                ->where('tenant_id', $tenantId)
                ->whereIn('deal_id', $agreementIds)
                ->pluck('id')
                ->all();

        $distribution = empty($listingIds) ? collect()
            : DB::table('partner_site_listings as psl')
                ->join('partner_sites as ps', 'ps.id', '=', 'psl.partner_site_id')
                ->where('psl.tenant_id', $tenantId)
                ->whereIn('psl.listing_id', $listingIds)
                ->orderBy('psl.pushed_at')
                ->get([
                    'psl.id', 'psl.listing_id', 'ps.name as partner_name',
                    'psl.status', 'psl.external_listing_id', 'psl.external_url',
                    'psl.view_count', 'psl.inquiry_count',
                    'psl.pushed_at', 'psl.went_live_at', 'psl.last_synced_at',
                    'psl.rejection_reason',
                ]);

        // --- Inquiries + bookings (proof of work + service delivery) ---
        $inquiries = empty($listingIds) ? collect()
            : DB::table('rental_inquiries')
                ->where('tenant_id', $tenantId)
                ->whereIn('listing_id', $listingIds)
                ->orderByDesc('created_at')
                ->get([
                    'id', 'listing_id', 'renter_name', 'renter_email',
                    'requested_check_in', 'requested_check_out',
                    'offered_amount', 'status', 'responded_at', 'created_at',
                ]);

        $bookings = empty($listingIds) ? collect()
            : DB::table('bookings')
                ->where('tenant_id', $tenantId)
                ->whereIn('listing_id', $listingIds)
                ->whereNull('deleted_at')
                ->orderByDesc('confirmed_at')
                ->get([
                    'id', 'listing_id', 'confirmation_number',
                    'renter_name', 'renter_email',
                    'check_in_date', 'check_out_date',
                    'total_price', 'owner_payout', 'our_commission',
                    'status', 'payment_status',
                    'confirmed_at', 'owner_notified_at',
                ]);

        // --- Cases ---
        $refundCases = empty($agreementIds) ? collect()
            : DB::table('refund_cases')
                ->where('tenant_id', $tenantId)
                ->whereIn('deal_id', $agreementIds)
                ->orderByDesc('opened_at')
                ->get([
                    'id', 'deal_id', 'refund_amount', 'reason',
                    'owner_statement', 'status',
                    'opened_at', 'resolved_at', 'created_at',
                ]);

        $chargebackCases = empty($agreementIds) ? collect()
            : DB::table('chargeback_cases')
                ->where('tenant_id', $tenantId)
                ->whereIn('deal_id', $agreementIds)
                ->orderByDesc('created_at')
                ->get([
                    'id', 'deal_id', 'processor_case_id', 'disputed_amount',
                    'reason_code', 'respond_by_date', 'status',
                    'evidence_attached', 'created_at',
                ]);

        // --- Communications (system + AI red-zone Notes on the owner) ---
        $communications = DB::table('notes')
            ->where('tenant_id', $tenantId)
            ->where('notable_type', \App\Modules\Lead\Domain\Models\Lead::class)
            ->where('notable_id', $owner->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get(['id', 'kind', 'body', 'metadata', 'created_at', 'user_id']);

        return [
            'manifest' => $manifest,
            'owner' => $ownerSection,
            'agreements' => $agreementSection,
            'recordings' => $recordings->map(fn ($r) => [
                'id' => $r->id,
                'deal_id' => $r->deal_id,
                'call_id' => $r->call_id,
                'agent' => trim(($r->agent_first ?? '').' '.($r->agent_last ?? '')) ?: null,
                'compliance_status' => $r->compliance_status,
                'disclosure_matrix' => [
                    'tcpa_consent_captured' => (bool) $r->tcpa_consent_captured,
                    'recording_disclosure_made' => (bool) $r->recording_disclosure_made,
                    'no_guarantee_disclosure_made' => (bool) $r->no_guarantee_disclosure_made,
                    'refund_policy_disclosure_made' => (bool) $r->refund_policy_disclosure_made,
                    'total_fee_stated_clearly' => (bool) $r->total_fee_stated_clearly,
                ],
                'all_captured' => (bool) $r->tcpa_consent_captured
                    && (bool) $r->recording_disclosure_made
                    && (bool) $r->no_guarantee_disclosure_made
                    && (bool) $r->refund_policy_disclosure_made
                    && (bool) $r->total_fee_stated_clearly,
                'recording_url' => $r->recording_url,
                'recording_s3_path' => $r->recording_s3_path,
                'duration_seconds' => $r->duration_seconds !== null ? (int) $r->duration_seconds : null,
                'transcription_text' => $r->transcription_text,
                'review_notes' => $r->review_notes,
                'reviewed_at' => $r->reviewed_at,
                'created_at' => $r->created_at,
            ])->values(),
            'financial' => [
                'payments' => $payments->values(),
                'totals' => [
                    'charges_succeeded' => (float) $payments
                        ->where('type', 'charge')
                        ->where('status', 'succeeded')
                        ->sum('amount'),
                    'refunds' => (float) $payments->where('type', 'refund')->sum('amount'),
                    'chargebacks' => (float) $payments->where('type', 'chargeback')->sum('amount'),
                ],
            ],
            'distribution' => $distribution->values(),
            'inquiries' => $inquiries->values(),
            'bookings' => $bookings->values(),
            'cases' => [
                'refunds' => $refundCases->values(),
                'chargebacks' => $chargebackCases->values(),
            ],
            'communications' => $communications->values(),
        ];
    }
}
