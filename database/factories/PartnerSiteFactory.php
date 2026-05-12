<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\TenantContext;
use App\Modules\Listing\Domain\Models\PartnerSite;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PartnerSite>
 */
final class PartnerSiteFactory extends Factory
{
    protected $model = PartnerSite::class;

    public function definition(): array
    {
        // tenant + slug uniqueness is enforced by `(tenant_id, slug)`
        // unique index — use a random suffix so parallel factory calls
        // inside one tenant don't collide.
        $base = $this->faker->randomElement(['airbnb', 'vrbo', 'redweek', 'tug']);
        $slug = $base.'-'.Str::lower(Str::random(6));

        return [
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'name' => Str::headline($base),
            'slug' => $slug,
            'api_endpoint' => null,
            'is_active' => true,
            'our_cost_per_listing' => null,
            'config' => null, // encrypted:array cast — keep null to avoid encryption-key dependency in tests
            'webhook_secret' => null,
            'webhook_last_received_at' => null,
        ];
    }

    /**
     * Site that has been issued a signing secret. Use this when the
     * test exercises the inbound webhook handler.
     */
    public function withWebhookSecret(?string $secret = null): self
    {
        return $this->state(fn () => [
            'webhook_secret' => $secret ?? Str::random(60),
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
