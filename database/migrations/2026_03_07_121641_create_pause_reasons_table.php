<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pause_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('max_minutes')->nullable(); // null = sin límite
            $table->string('color', 7)->default('#f59e0b'); // hex color
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pause_reasons');
    }
};
