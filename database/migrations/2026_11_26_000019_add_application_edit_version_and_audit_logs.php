<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedBigInteger('edit_version')->default(0);
            $table->unsignedBigInteger('note_updated_by')->nullable();
            $table->string('note_updated_role', 20)->nullable();
            $table->timestampTz('note_updated_at')->nullable();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->index();
            $table->string('actor_role', 20);
            $table->string('action', 80);
            $table->string('subject_type', 40);
            $table->string('subject_id', 26);
            $table->unsignedBigInteger('company_id');
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->jsonb('metadata');
            $table->timestampTz('created_at');
            $table->index(['company_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::table('applications', fn (Blueprint $table) => $table->dropColumn(['edit_version', 'note_updated_by', 'note_updated_role', 'note_updated_at']));
    }
};
