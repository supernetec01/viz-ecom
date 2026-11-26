<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_mail_totals', function (Blueprint $table) {
            $table->unsignedBigInteger('job_id')->primary();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('total')->default(0);
            $table->timestampsTz();
            $table->foreign(['job_id', 'company_id'])->references(['id', 'company_id'])->on('jobs')->restrictOnDelete();
        });
        Schema::create('application_mail_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('notified_total')->default(0);
            $table->timestampTz('attempted_at')->nullable();
            $table->string('status')->default('pending');
            $table->string('error_code', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['job_id', 'user_id']);
        });
        DB::statement('ALTER TABLE application_mail_totals ADD CONSTRAINT application_mail_total_positive CHECK (total >= 0)');
        DB::statement("ALTER TABLE application_mail_receipts ADD CONSTRAINT application_mail_receipt_valid CHECK (notified_total >= 0 AND status IN ('pending','sending','sent','uncertain'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('application_mail_receipts');
        Schema::dropIfExists('application_mail_totals');
    }
};
