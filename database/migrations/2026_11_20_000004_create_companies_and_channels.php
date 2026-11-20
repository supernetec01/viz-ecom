<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('direct');
            $table->string('tax_code', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('introduction')->nullable();
            $table->text('industry')->nullable();
            $table->text('products')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_type_check CHECK (type IN ('direct', 'agency'))");
        Schema::create('company_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('display_name');
            $table->string('url', 2048);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE company_channels ADD CONSTRAINT company_channels_type_check CHECK (type IN ('shopee','tiktok','lazada','youtube','facebook','website'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('company_channels');
        Schema::dropIfExists('companies');
    }
};
