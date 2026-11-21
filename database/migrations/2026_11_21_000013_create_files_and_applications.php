<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('path', 1024)->unique();
            $table->string('original_name');
            $table->string('mime_type');
            $table->bigInteger('size');
            $table->string('sha256', 64);
            $table->string('scan_status')->default('pending');
            $table->string('preview_path', 1024)->nullable();
            $table->timestampsTz();
            $table->index('scan_status');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE files
            ADD CONSTRAINT files_size_check CHECK (size > 0 AND size <= 10485760),
            ADD CONSTRAINT files_hash_check CHECK (sha256 ~ '^[0-9a-f]{64}$'),
            ADD CONSTRAINT files_scan_status_check CHECK (scan_status IN ('pending', 'clean', 'infected')),
            ADD CONSTRAINT files_metadata_check CHECK (btrim(path) <> '' AND btrim(original_name) <> '' AND btrim(mime_type) <> '')
            SQL);

        // Composite FK below prevents assigning an application to another company.
        Schema::table('jobs', fn (Blueprint $table) => $table->unique(['id', 'company_id'], 'jobs_id_company_unique'));
        Schema::create('applications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('job_id');
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('full_name');
            $table->string('phone_normalized', 10);
            $table->string('email', 254)->nullable();
            $table->foreignId('cv_file_id')->nullable()->constrained('files')->restrictOnDelete();
            $table->string('portfolio_url', 2048)->nullable();
            $table->foreignId('portfolio_file_id')->nullable()->constrained('files')->restrictOnDelete();
            $table->boolean('consent_given');
            $table->timestampTz('consented_at');
            $table->string('source', 64)->nullable();
            $table->timestampTz('submitted_at');
            $table->string('status')->default('new');
            $table->text('internal_notes')->nullable();
            $table->softDeletesTz();
            $table->timestampsTz();
            $table->foreign(['job_id', 'company_id'])->references(['id', 'company_id'])->on('jobs')->restrictOnDelete();
            $table->index(['company_id', 'job_id', 'submitted_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE applications
            ADD CONSTRAINT applications_ulid_check CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
            ADD CONSTRAINT applications_name_check CHECK (btrim(full_name) <> ''),
            ADD CONSTRAINT applications_phone_check CHECK (phone_normalized ~ '^0[0-9]{9}$'),
            ADD CONSTRAINT applications_consent_check CHECK (consent_given = true),
            ADD CONSTRAINT applications_source_check CHECK (source IS NULL OR source ~ '^[a-z0-9][a-z0-9_-]{0,63}$'),
            ADD CONSTRAINT applications_status_check CHECK (status IN ('new', 'viewed', 'interview', 'hired', 'rejected')),
            ADD CONSTRAINT applications_portfolio_check CHECK (portfolio_url IS NULL OR (portfolio_file_id IS NULL AND portfolio_url ~* '^https?://[^[:space:]]+$'))
            SQL);
        DB::statement('CREATE UNIQUE INDEX applications_active_job_phone_unique ON applications (job_id, phone_normalized) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
        Schema::table('jobs', fn (Blueprint $table) => $table->dropUnique('jobs_id_company_unique'));
        Schema::dropIfExists('files');
    }
};
