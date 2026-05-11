<?php

declare(strict_types=1);

namespace App\Modules\Listing\Domain\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An external marketing channel (Airbnb, Vrbo, RedWeek, etc).
 *
 * Tenant-scoped because each operator runs their own partner accounts;
 * we don't share API keys across tenants. The `config` JSON column
 * holds credentials that should be encrypted at rest — encryption is
 * applied at the model layer via cast (TODO when integrations land).
 */
final class PartnerSite extends Model
{
    use HasUuid;
    use SoftDeletes;
    use TenantScoped;

    protected $table = 'partner_sites';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'api_endpoint',
        'is_active',
        'our_cost_per_listing',
        'config',
        'webhook_secret',
        'webhook_last_received_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'our_cost_per_listing' => 'decimal:2',
            'config' => 'encrypted:array',
            'webhook_last_received_at' => 'datetime',
        ];
    }

    /** Hidden from default array/json serialisation — secrets never
     *  belong on the wire by accident. The controllers expose it
     *  explicitly the one time it's shown to the user (create + rotate).
     */
    protected $hidden = ['webhook_secret'];

    /**
     * Generate (or regenerate) a webhook signing secret.
     *
     * 60 chars from a URL-safe alphabet → ~360 bits of entropy, well
     * past what an HMAC needs. We hand the secret to the partner once
     * and never again — they store it on their end and use it to sign
     * outbound webhooks; we store it here and use it to verify.
     */
    public function rotateWebhookSecret(): string
    {
        $secret = Str::random(60);
        $this->webhook_secret = $secret;
        $this->save();

        return $secret;
    }

    /* ----------------------------------------------------------------------
     | Relationships
     | ---------------------------------------------------------------------- */

    public function siteListings(): HasMany
    {
        return $this->hasMany(PartnerSiteListing::class, 'partner_site_id');
    }

    /* ----------------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------------- */

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
