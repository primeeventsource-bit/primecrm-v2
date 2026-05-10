<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Core\Shared\Services\AuditLogService;
use App\Core\Shared\Services\PhoneNormalizer;
use App\Modules\Customer\Domain\Events\CustomerCreated;
use App\Modules\Customer\Domain\Models\Customer;
use App\Modules\Customer\Http\Requests\StoreCustomerRequest;
use App\Modules\Customer\Http\Resources\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

final class CustomerController extends Controller
{
    public function __construct(
        private readonly PhoneNormalizer $phoneNormalizer,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:120'],
            'user_id' => ['nullable', 'uuid'],
            'lead_id' => ['nullable', 'uuid'],
            'sort' => ['nullable', 'in:lifetime_value,created_at,last_purchase_at'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $q = Customer::query();

        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->value());
        }
        if ($request->filled('user_id')) {
            $q->where('user_id', $request->string('user_id')->value());
        }
        if ($request->filled('lead_id')) {
            $q->where('lead_id', $request->string('lead_id')->value());
        }
        if ($request->filled('q')) {
            $needle = '%'.mb_strtolower((string) $request->string('q')).'%';
            $q->where(function ($qq) use ($needle): void {
                $qq->whereRaw('LOWER(first_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$needle])
                    ->orWhere('phone', 'LIKE', $needle);
            });
        }

        $sort = $request->string('sort', 'lifetime_value')->value();
        $direction = $request->string('direction', 'desc')->value();
        $page = $q->orderBy($sort, $direction)
            ->paginate(min(200, (int) $request->integer('per_page', 25)));

        return CustomerResource::collection($page)->response();
    }

    public function show(string $id): CustomerResource
    {
        return new CustomerResource(Customer::query()->findOrFail($id));
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $normalized = $this->phoneNormalizer->normalizeAndHash($validated['phone']);
        if ($normalized === null) {
            throw ValidationException::withMessages([
                'phone' => ['Phone number could not be parsed to a valid format.'],
            ]);
        }
        [$phone, $hash] = $normalized;

        // Dedup on (tenant, phone_hash)
        $existing = Customer::query()->where('phone_hash', $hash)->first();
        if ($existing !== null) {
            return (new CustomerResource($existing))
                ->additional(['meta' => ['was_duplicate' => true]])
                ->response()
                ->setStatusCode(200);
        }

        $altPhone = null;
        $altHash = null;
        if (! empty($validated['alternate_phone'])) {
            $altNormalized = $this->phoneNormalizer->normalizeAndHash($validated['alternate_phone']);
            if ($altNormalized !== null) {
                [$altPhone, $altHash] = $altNormalized;
            }
        }

        $customer = Customer::query()->create([
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'email' => isset($validated['email']) ? mb_strtolower($validated['email']) : null,
            'phone' => $phone,
            'phone_hash' => $hash,
            'alternate_phone' => $altPhone,
            'country' => $validated['country'] ?? null,
            'state' => $validated['state'] ?? null,
            'city' => $validated['city'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'timezone' => $validated['timezone'] ?? null,
            'status' => $validated['status'] ?? Customer::STATUS_ACTIVE,
            'source' => $validated['source'] ?? 'manual',
            'user_id' => $validated['user_id'] ?? $request->user()?->id,
            'notes' => $validated['notes'] ?? null,
            'lifetime_value' => 0,
            'total_deals' => 0,
            'total_bookings' => 0,
        ]);

        $this->audit->record(
            action: 'customer.created',
            entityType: 'customer',
            entityId: $customer->id,
            context: ['source' => 'manual'],
        );

        CustomerCreated::dispatch($customer, 'manual');

        return (new CustomerResource($customer))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id): CustomerResource
    {
        $customer = Customer::query()->findOrFail($id);

        $validated = $request->validate([
            'first_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:200'],
            'alternate_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'state' => ['sometimes', 'nullable', 'string', 'size:2'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'status' => ['sometimes', 'string'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $customer->update($validated);

        $this->audit->record(
            action: 'customer.updated',
            entityType: 'customer',
            entityId: $customer->id,
            changes: $validated,
        );

        return new CustomerResource($customer->fresh());
    }
}
