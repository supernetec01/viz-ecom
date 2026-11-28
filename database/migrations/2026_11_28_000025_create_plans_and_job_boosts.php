<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('duration_days');
            $table->integer('priority');
            $table->bigInteger('price');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_values_check CHECK (length(trim(name)) > 0 AND duration_days BETWEEN 1 AND 366 AND priority BETWEEN 1 AND 1000000 AND price > 0)');
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('boost_plan_id')->nullable()->constrained('plans')->restrictOnDelete();
            $table->string('boost_plan_name')->nullable();
            $table->integer('boost_days')->nullable();
            $table->integer('boost_priority')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['id', 'company_id', 'job_id'], 'orders_boost_scope_unique');
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_boost_snapshot_check CHECK ((boost_plan_id IS NULL AND boost_plan_name IS NULL AND boost_days IS NULL AND boost_priority IS NULL) OR (type = 'job_boost' AND job_id IS NOT NULL AND boost_plan_id IS NOT NULL AND boost_plan_name IS NOT NULL AND length(trim(boost_plan_name)) > 0 AND boost_days IS NOT NULL AND boost_days BETWEEN 1 AND 366 AND boost_priority IS NOT NULL AND boost_priority BETWEEN 1 AND 1000000 AND requested_by IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX orders_one_pending_boost_per_job ON orders (company_id, job_id) WHERE type = 'job_boost' AND status = 'pending' AND job_id IS NOT NULL");
        Schema::create('job_boosts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('job_id');
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->unsignedBigInteger('order_id')->unique();
            $table->timestampTz('starts_at', 6);
            $table->timestampTz('ends_at', 6);
            $table->integer('priority');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->foreign(['job_id', 'company_id'])->references(['id', 'company_id'])->on('jobs')->restrictOnDelete();
            $table->foreign(['order_id', 'company_id', 'job_id'])->references(['id', 'company_id', 'job_id'])->on('orders')->restrictOnDelete();
            $table->index(['job_id', 'starts_at', 'ends_at', 'priority'], 'job_boosts_effective_lookup');
        });
        DB::statement('ALTER TABLE job_boosts ADD CONSTRAINT job_boosts_values_check CHECK (ends_at > starts_at AND priority BETWEEN 1 AND 1000000)');
    }

    public function down(): void
    {
        if (DB::table('plans')->exists() || DB::table('orders')->whereNotNull('boost_plan_id')->exists() || DB::table('job_boosts')->exists()) {
            throw new RuntimeException('Preserve plan and boost history before rolling back T40.');
        }
        Schema::drop('job_boosts');
        DB::statement('DROP INDEX orders_one_pending_boost_per_job');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_boost_snapshot_check');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['boost_plan_id']);
            $table->dropForeign(['requested_by']);
            $table->dropUnique('orders_boost_scope_unique');
            $table->dropColumn(['boost_plan_id', 'boost_plan_name', 'boost_days', 'boost_priority', 'requested_by']);
        });
        Schema::drop('plans');
    }
};
