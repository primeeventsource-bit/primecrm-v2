<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable(); // null = system action
            $table->string('action')->index();
            // lead.imported, lead.assigned, deal.stage_changed, payment.refunded,
            // user.role_changed, contract.signed, dnc.added, etc
            $table->string('entity_type')->nullable();
            $table->uuid('entity_id')->nullable();
            $table->jsonb('changes')->nullable(); // {field: {from, to}}
            $table->jsonb('context')->nullable(); // request metadata, reason
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id')->nullable(); // correlation across logs
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['tenant_id', 'action', 'created_at']);
        });

        // Sanctum personal access tokens.
        // uuidMorphs() (not morphs()) so tokenable_id is char(36) — our
        // User model has UUID PKs and Sanctum's default morphs is bigint.
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('audit_logs');
    }
};
