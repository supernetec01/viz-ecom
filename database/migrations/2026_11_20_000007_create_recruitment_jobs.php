<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('jobs', 'queue_jobs');
        DB::statement('ALTER INDEX jobs_pkey RENAME TO queue_jobs_pkey');
        DB::statement('ALTER INDEX jobs_queue_index RENAME TO queue_jobs_queue_index');
        DB::statement('ALTER SEQUENCE jobs_id_seq RENAME TO queue_jobs_id_seq');

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained('job_categories')->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('slug', 190)->unique();
            $table->text('description');
            $table->text('requirements');
            $table->text('benefits');
            $table->bigInteger('salary_min')->nullable();
            $table->bigInteger('salary_max')->nullable();
            $table->boolean('negotiable')->default(false);
            $table->integer('experience_years')->default(0);
            $table->integer('vacancies')->default(1);
            $table->string('employment_type')->default('full_time');
            $table->string('status')->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->softDeletesTz();
            $table->timestampsTz();
            $table->index(['status', 'published_at']);
            $table->index(['location_id', 'category_id']);
            $table->index(['salary_min', 'salary_max']);
        });
        DB::statement("ALTER TABLE jobs ADD CONSTRAINT jobs_salary_check CHECK ((salary_min IS NULL OR salary_min >= 0) AND (salary_max IS NULL OR salary_max >= 0) AND (salary_min IS NULL OR salary_max IS NULL OR salary_min <= salary_max)), ADD CONSTRAINT jobs_counts_check CHECK (experience_years >= 0 AND vacancies > 0), ADD CONSTRAINT jobs_employment_check CHECK (employment_type IN ('full_time', 'part_time')), ADD CONSTRAINT jobs_status_check CHECK (status IN ('draft', 'pending', 'published', 'expired', 'closed')), ADD CONSTRAINT jobs_dates_check CHECK ((published_at IS NULL OR expires_at IS NULL OR expires_at > published_at) AND (status <> 'published' OR (published_at IS NOT NULL AND expires_at IS NOT NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::rename('queue_jobs', 'jobs');
        DB::statement('ALTER INDEX queue_jobs_pkey RENAME TO jobs_pkey');
        DB::statement('ALTER INDEX queue_jobs_queue_index RENAME TO jobs_queue_index');
        DB::statement('ALTER SEQUENCE queue_jobs_id_seq RENAME TO jobs_id_seq');
    }
};
