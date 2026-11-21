<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table): void {
            // Nullable only for pre-existing jobs. Never guess their worksite from the company office.
            $table->string('ward_name', 150)->nullable();
            $table->string('work_address', 500)->nullable();
        });
        DB::statement('ALTER TABLE jobs ADD CONSTRAINT jobs_work_address_pair CHECK ((ward_name IS NULL AND work_address IS NULL) OR (ward_name IS NOT NULL AND work_address IS NOT NULL AND length(btrim(ward_name)) > 0 AND length(btrim(work_address)) > 0))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE jobs DROP CONSTRAINT jobs_work_address_pair');
        Schema::table('jobs', fn (Blueprint $table) => $table->dropColumn(['ward_name', 'work_address']));
    }
};
