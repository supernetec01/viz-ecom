<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('job_id')->nullable();
            $table->string('payment_reference', 64)->nullable()->unique();
            $table->jsonb('payment_instructions')->nullable();
            $table->foreign(['job_id', 'company_id'])->references(['id', 'company_id'])->on('jobs')->restrictOnDelete();
        });
        // Only existing grants provide authoritative scope for pre-T37 orders.
        DB::statement('UPDATE orders SET job_id = access_grants.job_id FROM access_grants WHERE access_grants.order_id = orders.id');
        DB::statement("CREATE UNIQUE INDEX orders_one_pending_access_per_job ON orders (company_id, job_id) WHERE type = 'access_grant' AND status = 'pending' AND job_id IS NOT NULL");
    }

    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('payment_instructions')->exists()) {
            throw new RuntimeException('Preserve payment instructions before rolling back T37.');
        }
        DB::statement('DROP INDEX orders_one_pending_access_per_job');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['job_id', 'company_id']);
            $table->dropUnique(['payment_reference']);
            $table->dropColumn(['job_id', 'payment_reference', 'payment_instructions']);
        });
    }
};
