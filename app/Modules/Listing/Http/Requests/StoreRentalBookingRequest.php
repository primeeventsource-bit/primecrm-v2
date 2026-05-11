<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Manually create a rental booking against an existing listing."
 *
 * Used when a renter books through a channel that didn't generate
 * an inquiry in our system (phone call, off-platform agreement,
 * back-fill of a previously-booked week). The listing must already
 * exist; the booking confirms it and notifies the owner.
 *
 * Dates default to the listing's window; total_price defaults to
 * the listing's asking_price. Commission % defaults to the listing's
 * our_commission_pct (or 15% fallback).
 */
final class StoreRentalBookingRequest extends FormRequest
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
            'listing_id' => ['required', 'uuid'],
            'renter_name' => ['required', 'string', 'max:200'],
            'renter_email' => ['nullable', 'email', 'max:200'],
            'renter_phone' => ['nullable', 'string', 'max:30'],
            'check_in_date' => ['nullable', 'date'],
            'check_out_date' => ['nullable', 'date', 'after_or_equal:check_in_date'],
            'total_price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'commission_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'owner_payout' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'payment_status' => ['nullable', Rule::in([
                'pending', 'deposit_paid', 'paid_in_full',
            ])],
            'notify_owner' => ['nullable', 'boolean'],
        ];
    }
}
