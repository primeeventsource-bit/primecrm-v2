<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'guest_details' => ['nullable', 'array'],
            'guest_details.primary_name' => ['nullable', 'string', 'max:200'],
            'guest_details.primary_email' => ['nullable', 'email', 'max:200'],
            'guest_details.primary_phone' => ['nullable', 'string', 'max:32'],
            'guest_details.guests' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }
}
