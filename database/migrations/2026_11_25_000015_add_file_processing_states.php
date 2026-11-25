<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('preview_status')->default('pending');
            $table->boolean('review_required')->default(false)->index();
            $table->unsignedInteger('processing_version')->default(0);
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->unsignedSmallInteger('scan_attempts')->default(0);
            $table->unsignedSmallInteger('preview_attempts')->default(0);
            $table->string('scan_error_code', 64)->nullable();
            $table->string('preview_error_code', 64)->nullable();
            $table->char('scanned_sha256', 64)->nullable();
            $table->timestampTz('scanned_at')->nullable();
        });
        DB::statement("ALTER TABLE files DROP CONSTRAINT files_scan_status_check, ADD CONSTRAINT files_scan_status_check CHECK (scan_status IN ('pending','clean','infected','error')), ADD CONSTRAINT files_preview_status_check CHECK (preview_status IN ('pending','ready','failed'))");
        // Earlier tasks have no verified scan provenance. Preserve bytes; require admin reprocessing.
        DB::table('files')->update(['scan_status' => DB::raw("CASE WHEN scan_status = 'infected' THEN 'infected' ELSE 'error' END"),
            'review_required' => true, 'scan_error_code' => 'verification_required']);
    }

    public function down(): void
    {
        DB::table('files')->where('scan_status', 'error')->update(['scan_status' => 'pending']);
        DB::statement("ALTER TABLE files DROP CONSTRAINT files_preview_status_check, DROP CONSTRAINT files_scan_status_check, ADD CONSTRAINT files_scan_status_check CHECK (scan_status IN ('pending','clean','infected'))");
        Schema::table('files', fn (Blueprint $table) => $table->dropColumn(['preview_status', 'review_required', 'processing_version', 'processing_attempts',
            'scan_attempts', 'preview_attempts', 'scan_error_code', 'preview_error_code', 'scanned_sha256', 'scanned_at']));
    }
};
