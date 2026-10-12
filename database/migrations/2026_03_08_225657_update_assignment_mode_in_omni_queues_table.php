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
        // SQLite no soporta ALTER COLUMN, hay que recrear
        Schema::table('omni_queues', function (Blueprint $table) {
            $table->string('assignment_mode')->default('round_robin')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('omni_queues', function (Blueprint $table) {
            //
        });
    }
};
