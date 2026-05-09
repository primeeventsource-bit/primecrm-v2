<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ChargePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            // Stripe payment method id (`pm_...`); never raw card data.
            'source_token' => ['required', 'string', 'starts_with:pm_'],
            'customer_token' => ['nullable', 'string', 'starts_with:cus_'],
            'booking_id' => ['nullable', 'uuid', 'exists:bookings,id'],
            'deal_id' => ['nullable', 'uuid', 'exists:deals,id'],
            'lead_id' => ['nullable', 'uuid', 'exists:leads,id'],
            'type' => ['nullable', 'in:charge,deposit'],
        ];
    }
}
