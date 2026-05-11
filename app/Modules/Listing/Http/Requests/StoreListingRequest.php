<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Requests;

use App\Modules\Listing\Domain\Enums\ListingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a "list this week" request from the operator.
 *
 * The property is picked from an existing, tenant-scoped row — we
 * don't create a property inline because property creation owns the
 * verification + rental-allowed flags that gate listing.
 *
 * Owner payout and commission split are kept independent: the
 * operator can set either explicitly, or just give an asking price
 * and a commission % and let the store() controller compute payout.
 */
final class StoreListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // tenant middleware already enforces tenant scope
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'property_id' => ['required', 'uuid'],
            'deal_id' => ['nullable', 'uuid'],
            'check_in_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'asking_price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'reserve_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'owner_payout' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'our_commission_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'marketing_description' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::in([
                ListingStatus::Draft->value,
                ListingStatus::PendingDistribution->value,
                ListingStatus::Live->value,
            ])],
            'go_live' => ['nullable', 'boolean'],
        ];
    }
}
