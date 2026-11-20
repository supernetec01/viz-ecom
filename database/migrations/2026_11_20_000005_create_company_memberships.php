<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['company_user', 'staff_company'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('company_id')->constrained()->restrictOnDelete();
                $table->primary(['user_id', 'company_id']);
                $table->index('company_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_company');
        Schema::dropIfExists('company_user');
    }
};
