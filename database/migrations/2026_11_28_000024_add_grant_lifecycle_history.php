<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->boolean('is_renewal')->default(false));
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_renewal_scope_check CHECK (NOT is_renewal OR (type = 'access_grant' AND job_id IS NOT NULL))");
        Schema::table('access_grants', function (Blueprint $table) {
            $table->unsignedBigInteger('previous_grant_id')->nullable();
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('revoked_reason', 80)->nullable();
            $table->unique(['id', 'company_id', 'job_id'], 'grants_history_scope_unique');
            $table->foreign(['previous_grant_id', 'company_id', 'job_id'], 'grants_previous_scope_fk')
                ->references(['id', 'company_id', 'job_id'])->on('access_grants')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE access_grants ADD CONSTRAINT grants_previous_not_self CHECK (previous_grant_id IS NULL OR previous_grant_id <> id)');
    }

    public function down(): void
    {
        if (DB::table('orders')->where('is_renewal', true)->exists()
            || DB::table('access_grants')->whereNotNull('previous_grant_id')->orWhereNotNull('revoked_at')->exists()) {
            throw new RuntimeException('Preserve renewal and revocation history before rolling back T39.');
        }
        Schema::table('access_grants', function (Blueprint $table) {
            $table->dropForeign('grants_previous_scope_fk');
            $table->dropForeign(['revoked_by']);
            $table->dropUnique('grants_history_scope_unique');
        });
        DB::statement('ALTER TABLE access_grants DROP CONSTRAINT grants_previous_not_self');
        Schema::table('access_grants', fn (Blueprint $table) => $table->dropColumn(['previous_grant_id', 'revoked_at', 'revoked_by', 'revoked_reason']));
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_renewal_scope_check');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('is_renewal'));
    }
};
