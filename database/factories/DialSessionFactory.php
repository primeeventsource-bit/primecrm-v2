<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\TenantContext;
use App\Modules\Dialer\Domain\Models\DialSession;
use App\Support\Enums\DialerMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DialSession>
 */
final class DialSessionFactory extends Factory
{
    protected $model = DialSession::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'agent_id' => UserFactory::new()->agent(),
            'campaign_id' => null,
            'mode' => DialerMode::Predictive->value,
            'status' => DialSession::STATUS_ACTIVE,
            'started_at' => now(),
            'leads_processed' => 0,
            'calls_initiated' => 0,
            'calls_connected' => 0,
            'calls_abandoned' => 0,
            'total_talk_seconds' => 0,
            'total_wrap_seconds' => 0,
            'settings' => [],
        ];
    }
}
