<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Requests;

use App\Modules\Customer\Domain\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:200'],
            'phone' => ['required', 'string', 'min:7', 'max:32'],
            'alternate_phone' => ['nullable', 'string', 'min:7', 'max:32'],
            'country' => ['nullable', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'status' => [
                'nullable',
                Rule::in([
                    Customer::STATUS_ACTIVE,
                    Customer::STATUS_VIP,
                    Customer::STATUS_PROSPECT,
                    Customer::STATUS_CHURNED,
                    Customer::STATUS_BLACKLISTED,
                ]),
            ],
            'source' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
