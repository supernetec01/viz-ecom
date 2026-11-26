<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->bigInteger('amount');
            $table->string('status')->default('pending');
            $table->string('method')->default('bank_transfer');
            $table->text('reason')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at', 6)->nullable();
            $table->timestampsTz();
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'status']);
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_values_check CHECK (type IN ('access_grant','job_boost') AND status IN ('pending','paid','cancelled') AND amount >= 0 AND method IN ('bank_transfer','free_trial','gift')),
            ADD CONSTRAINT orders_zero_reason_check CHECK ((amount > 0 AND method = 'bank_transfer') OR (amount = 0 AND method IN ('free_trial','gift') AND reason IS NOT NULL AND reason ~ '[^[:space:]]')),
            ADD CONSTRAINT orders_paid_confirmation_check CHECK (status <> 'paid' OR (confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL))");
        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('job_id');
            $table->unsignedBigInteger('order_id')->unique();
            $table->timestampTz('starts_at', 6);
            $table->timestampTz('ends_at', 6);
            $table->string('status')->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->foreign(['job_id', 'company_id'])->references(['id', 'company_id'])->on('jobs')->restrictOnDelete();
            $table->foreign(['order_id', 'company_id'])->references(['id', 'company_id'])->on('orders')->restrictOnDelete();
            $table->index(['company_id', 'job_id', 'status', 'starts_at', 'ends_at'], 'access_grants_current_lookup');
        });
        DB::statement("ALTER TABLE access_grants ADD CONSTRAINT access_grants_dates_check CHECK (ends_at > starts_at), ADD CONSTRAINT access_grants_status_check CHECK (status IN ('active','revoked'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('access_grants');
        Schema::dropIfExists('orders');
    }
};
