<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listing-marketing domain — the asset side.
 *
 *   properties              The owner's timeshare itself (what they own).
 *   listings                One marketed offering of one or more weeks
 *                           from a property. Linked to the deal that
 *                           paid for the listing fee.
 *   partner_sites           External marketing channels (Airbnb, Vrbo,
 *                           RedWeek, SellMyTimeshareNow, etc).
 *   partner_site_listings   Many-to-many: a single listing can be
 *                           pushed to several partner sites; each push
 *                           has its own external id, status, view/inquiry
 *                           counters.
 *
 * MySQL-safe: json (not jsonb), full unique indexes, all index names
 * <= 64 chars. Strings (not enum()) so future values don't require
 * ALTER TABLE — backed PHP enums in app/Modules/Listing/Domain/Enums/
 * provide the type discipline.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The timeshare itself — owned by a lead/owner. Multiple
        // properties per owner are the norm (collectors with several
        // weeks across resorts).
        Schema::create('properties', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('owner_id');                         // FK -> leads (the owner)

            // Resort identification
            $table->string('resort_name');
            $table->string('resort_brand')->nullable();       // Marriott, Hilton, Wyndham, Diamond
            $table->string('location_city');
            $table->string('location_state', 60);
            $table->string('location_country', 60)->default('USA');

            // Unit details
            $table->string('unit_number', 50)->nullable();
            $table->tinyInteger('bedrooms')->nullable();
            $table->tinyInteger('sleeps')->nullable();
            $table->string('view_type', 60)->nullable();      // ocean, garden, mountain

            // Ownership type — string + PHP enum, not MySQL enum().
            $table->string('ownership_type');
            // fixed_week, floating_week, points, biennial
            $table->integer('points_balance')->nullable();
            $table->tinyInteger('fixed_week_number')->nullable(); // 1..52
            $table->string('season')->nullable();
            // platinum, gold, silver, bronze, red, white, blue, none

            // Verification — gates listing publication. Owners must
            // prove they actually own the week and that the resort
            // permits rentals (some HOAs forbid it).
            $table->boolean('ownership_verified')->default(false);
            $table->timestamp('ownership_verified_at')->nullable();
            $table->uuid('ownership_verified_by')->nullable();
            $table->string('verification_document_path', 512)->nullable();
            $table->boolean('rental_allowed_by_resort')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('owner_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->foreign('ownership_verified_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'resort_brand', 'location_state'], 'properties_brand_state_idx');
            $table->index(['tenant_id', 'ownership_verified'], 'properties_verified_idx');
        });

        // The marketed offering. A property can produce multiple
        // listings over time (different weeks, different years).
        Schema::create('listings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('property_id');
            $table->uuid('deal_id');                          // the listing-fee agreement

            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->decimal('asking_price', 10, 2);
            $table->decimal('reserve_price', 10, 2)->nullable();
            $table->decimal('owner_payout', 10, 2);
            $table->decimal('our_commission_pct', 5, 2)->nullable();

            $table->string('status')->default('draft');
            // draft, pending_distribution, live, inquiry_received,
            // pending_booking, booked, rented_completed, unrented_expired, cancelled

            $table->timestamp('went_live_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('marketing_description')->nullable();
            $table->json('photos')->nullable();               // [url, ...]

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('property_id')->references('id')->on('properties')->cascadeOnDelete();
            $table->foreign('deal_id')->references('id')->on('deals')->cascadeOnDelete();

            $table->index(['tenant_id', 'status', 'check_in_date'], 'listings_status_checkin_idx');
            $table->index(['tenant_id', 'property_id'], 'listings_property_idx');
            $table->index(['tenant_id', 'deal_id'], 'listings_deal_idx');
            $table->index(['tenant_id', 'expires_at'], 'listings_expires_idx');
        });

        // Partner marketing sites we push to. Tenant-scoped so each
        // operator can configure their own credentials/relationships.
        Schema::create('partner_sites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            $table->string('name');                            // Airbnb, Vrbo, etc
            $table->string('slug', 50);                        // airbnb, vrbo
            $table->string('api_endpoint', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('our_cost_per_listing', 8, 2)->nullable();
            $table->json('config')->nullable();                // encrypted at the model layer

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'is_active'], 'partner_sites_active_idx');
        });

        // Per-listing-per-site distribution row. The unique constraint
        // prevents pushing the same listing to the same site twice.
        Schema::create('partner_site_listings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('listing_id');
            $table->uuid('partner_site_id');

            $table->string('external_listing_id')->nullable();
            $table->string('external_url', 500)->nullable();
            $table->string('status')->default('pending');
            // pending, live, paused, rejected, removed
            $table->text('rejection_reason')->nullable();

            $table->integer('view_count')->default(0);
            $table->integer('inquiry_count')->default(0);
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('went_live_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('listing_id')->references('id')->on('listings')->cascadeOnDelete();
            $table->foreign('partner_site_id')->references('id')->on('partner_sites')->cascadeOnDelete();

            $table->unique(['listing_id', 'partner_site_id'], 'psl_listing_site_unique');
            $table->index(['tenant_id', 'status'], 'psl_tenant_status_idx');
            $table->index(['partner_site_id', 'status'], 'psl_site_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_site_listings');
        Schema::dropIfExists('partner_sites');
        Schema::dropIfExists('listings');
        Schema::dropIfExists('properties');
    }
};
