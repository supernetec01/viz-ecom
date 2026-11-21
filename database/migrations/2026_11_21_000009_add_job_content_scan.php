<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->jsonb('content_scan_reasons')->default('[]');
            $table->timestampTz('content_scanned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['content_scan_reasons', 'content_scanned_at']);
        });
    }
};
