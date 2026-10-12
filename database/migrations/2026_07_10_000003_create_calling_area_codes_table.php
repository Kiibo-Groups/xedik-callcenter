<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calling_area_codes', function (Blueprint $table) {
            $table->id();
            $table->string('area_code', 3)->unique();
            $table->string('country_code', 2)->default('US');
            $table->string('state')->nullable();
            $table->string('state_code', 2)->nullable();
            $table->string('time_zone');
            $table->string('time_zone_abbr')->nullable();
            $table->string('status')->default('in_service');
            $table->boolean('is_active')->default(true);
            $table->string('source')->nullable();
            $table->date('source_updated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calling_area_codes');
    }
};
