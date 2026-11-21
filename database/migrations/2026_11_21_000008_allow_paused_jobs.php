<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE jobs DROP CONSTRAINT jobs_status_check, ADD CONSTRAINT jobs_status_check CHECK (status IN ('draft', 'pending', 'published', 'paused', 'expired', 'closed'))");
    }

    public function down(): void
    {
        // Refuse rollback while paused rows exist, rather than silently changing business data.
        DB::statement("ALTER TABLE jobs DROP CONSTRAINT jobs_status_check, ADD CONSTRAINT jobs_status_check CHECK (status IN ('draft', 'pending', 'published', 'expired', 'closed'))");
    }
};
