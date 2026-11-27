<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
            $table->string('operation_key', 100)->nullable()->unique();
        });
        Schema::create('access_limit_states', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id')->primary();
            $table->timestampTz('file_started_at', 6)->nullable();
            $table->unsignedInteger('file_used')->default(0);
            $table->timestampTz('details_started_at', 6)->nullable();
            $table->unsignedInteger('details_used')->default(0);
            $table->timestampTz('alerted_at', 6)->nullable();
        });
        Schema::create('access_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->index();
            $table->string('actor_role', 20);
            $table->unsignedBigInteger('company_id');
            $table->string('bucket', 20);
            $table->unsignedInteger('limit');
            $table->timestampTz('created_at', 6)->index();
        });
    }

    public function down(): void
    {
        if (DB::table('audit_logs')->whereNull('company_id')->exists()) {
            throw new RuntimeException('Preserve global audit records; rollback requires a reviewed migration.');
        }
        Schema::dropIfExists('access_alerts');
        Schema::dropIfExists('access_limit_states');
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('operation_key');
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
        });
    }
};
