<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // Existing submissions remain null; new requests persist only a digest.
            $table->char('submission_hash', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $table) => $table->dropColumn('submission_hash'));
    }
};
