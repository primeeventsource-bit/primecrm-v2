<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('imported_by_id'); // user who triggered the import
            $table->string('source'); // csv, api, manual_paste
            $table->string('original_filename')->nullable();
            $table->string('s3_path')->nullable(); // raw file in S3 for audit
            $table->string('status')->default('pending')->index();
            // pending, processing, completed, completed_with_errors, failed
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('imported_count')->default(0);
            $table->integer('duplicate_count')->default(0);
            $table->integer('error_count')->default(0);
            $table->json('column_mapping')->nullable(); // CSV column → field mapping
            $table->json('errors')->nullable(); // sample of errors, capped
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('imported_by_id')->references('id')->on('users');

            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_imports');
    }
};
