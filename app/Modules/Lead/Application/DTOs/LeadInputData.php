<?php

declare(strict_types=1);

namespace App\Modules\Lead\Application\DTOs;

/**
 * Normalized input for lead creation. Constructed by callers (HTTP requests,
 * CSV import rows, API push) — the value object that flows through the
 * dedup engine into CreateLeadAction.
 *
 * Phone/phone_hash are populated by PhoneNormalizer before reaching this DTO;
 * the DTO assumes E.164 phone is already valid.
 */
final class LeadInputData
{
    /** @param array<string, mixed>|null $sourceMetadata */
    public function __construct(
        public readonly string $phone,
        public readonly string $phoneHash,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $email = null,
        public readonly ?string $alternatePhone = null,
        public readonly ?string $alternatePhoneHash = null,
        public readonly ?string $country = null,
        public readonly ?string $state = null,
        public readonly ?string $city = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $timezone = null,
        public readonly string $source = 'unknown',
        public readonly ?string $sourceCampaign = null,
        public readonly ?string $sourceMedium = null,
        public readonly ?array $sourceMetadata = null,
        public readonly ?string $importedViaId = null,
        public readonly ?string $resortInterest = null,
        public readonly ?string $propertyType = null,
        public readonly ?float $estimatedValue = null,
        public readonly string $priority = 'normal',
    ) {}

    /**
     * Convert to attributes suitable for Lead::fill() — strips computed fields
     * the model derives itself (status, score), keeps everything assignable.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return array_filter([
            'phone' => $this->phone,
            'phone_hash' => $this->phoneHash,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email !== null ? mb_strtolower($this->email) : null,
            'alternate_phone' => $this->alternatePhone,
            'alternate_phone_hash' => $this->alternatePhoneHash,
            'country' => $this->country,
            'state' => $this->state,
            'city' => $this->city,
            'postal_code' => $this->postalCode,
            'timezone' => $this->timezone,
            'source' => $this->source,
            'source_campaign' => $this->sourceCampaign,
            'source_medium' => $this->sourceMedium,
            'source_metadata' => $this->sourceMetadata,
            'imported_via_id' => $this->importedViaId,
            'resort_interest' => $this->resortInterest,
            'property_type' => $this->propertyType,
            'estimated_value' => $this->estimatedValue,
            'priority' => $this->priority,
        ], static fn ($v) => $v !== null);
    }
}
