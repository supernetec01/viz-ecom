<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 10);
            $table->string('reason', 2000)->nullable();
            $table->timestampTz('reviewed_at');
            $table->index(['job_id', 'id']);
        });
        DB::statement("ALTER TABLE job_reviews ADD CONSTRAINT job_reviews_decision CHECK (decision IN ('approved', 'rejected'))");
        DB::statement("ALTER TABLE job_reviews ADD CONSTRAINT job_reviews_rejection_reason CHECK (decision <> 'rejected' OR (reason IS NOT NULL AND length(btrim(reason)) > 0))");
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('job_reviews');
    }
};
