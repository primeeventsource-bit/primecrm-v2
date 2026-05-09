<?php

declare(strict_types=1);

namespace App\Modules\Customer\Application\Actions;

use App\Core\Shared\Services\AuditLogService;
use App\Modules\Customer\Domain\Events\CustomerCreated;
use App\Modules\Customer\Domain\Models\Customer;
use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Sales\Domain\Models\Deal;
use Illuminate\Support\Facades\DB;

/**
 * Promote a Lead into a Customer.
 *
 * Idempotent: if a Customer already exists for the lead's phone_hash in
 * the same tenant, returns it unchanged (modulo metric bumps from the
 * triggering deal). The metric updates (total_deals, lifetime_value)
 * happen in DealClosedWon / PaymentCleared listeners — this action is
 * just the identity creation.
 */
final class CreateCustomerFromLead
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function execute(Lead $lead, ?Deal $closingDeal = null): Customer
    {
        return DB::transaction(function () use ($lead, $closingDeal): Customer {
            $existing = Customer::query()
                ->where('phone_hash', $lead->phone_hash)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->bumpFromDeal($existing, $closingDeal);
            }

            $customer = Customer::query()->create([
                'lead_id' => $lead->id,
                'user_id' => $closingDeal?->agent_id ?? $lead->assigned_agent_id,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'phone_hash' => $lead->phone_hash,
                'alternate_phone' => $lead->alternate_phone,
                'country' => $lead->country,
                'state' => $lead->state,
                'city' => $lead->city,
                'postal_code' => $lead->postal_code,
                'timezone' => $lead->timezone,
                'status' => Customer::STATUS_ACTIVE,
                'source' => $lead->source,
                'lifetime_value' => 0,
                'total_deals' => 0,
                'total_bookings' => 0,
            ]);

            $customer = $this->bumpFromDeal($customer, $closingDeal);

            $this->audit->record(
                action: 'customer.created',
                entityType: 'customer',
                entityId: $customer->id,
                context: [
                    'lead_id' => $lead->id,
                    'deal_id' => $closingDeal?->id,
                    'source' => 'lead_conversion',
                ],
            );

            CustomerCreated::dispatch($customer, 'lead_conversion');

            return $customer;
        });
    }

    private function bumpFromDeal(Customer $customer, ?Deal $deal): Customer
    {
        if ($deal === null) {
            return $customer;
        }

        $payable = (float) $deal->payable_amount;
        $now = now();

        $customer->update([
            'total_deals' => $customer->total_deals + 1,
            'lifetime_value' => (float) $customer->lifetime_value + $payable,
            'first_purchase_at' => $customer->first_purchase_at ?? $now,
            'last_purchase_at' => $now,
            'user_id' => $customer->user_id ?? $deal->agent_id,
        ]);

        return $customer->fresh();
    }
}
