<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employer_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('credential_stamp', 64);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('last_seen_at', 6);
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->index(['user_id', 'revoked_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employer_sessions');
    }
};
