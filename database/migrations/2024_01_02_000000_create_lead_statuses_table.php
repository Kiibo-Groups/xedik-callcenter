<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Crear tabla de catálogo de status
        Schema::create('lead_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');           // e.g. "New", "Closed Won"
            $table->string('slug')->unique(); // e.g. "new", "closed_won"
            $table->string('color', 7)->default('#64748b'); // hex color
            $table->string('bg_color', 7)->default('#f1f5f9'); // hex background
            $table->text('description')->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Insertar los status actuales
        DB::table('lead_statuses')->insert([
            ['name' => 'New',          'slug' => 'new',          'color' => '#0891b2', 'bg_color' => '#cffafe', 'order' => 1, 'is_default' => true,  'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Contacted',    'slug' => 'contacted',    'color' => '#4f46e5', 'bg_color' => '#e0e7ff', 'order' => 2, 'is_default' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Qualified',    'slug' => 'qualified',    'color' => '#b45309', 'bg_color' => '#fef9c3', 'order' => 3, 'is_default' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Proposal',     'slug' => 'proposal',     'color' => '#15803d', 'bg_color' => '#dcfce7', 'order' => 4, 'is_default' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Negotiation',  'slug' => 'negotiation',  'color' => '#e2e8f0', 'bg_color' => '#1e293b', 'order' => 5, 'is_default' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Closed Won',   'slug' => 'closed_won',   'color' => '#16a34a', 'bg_color' => '#dcfce7', 'order' => 6, 'is_default' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Closed Lost',  'slug' => 'closed_lost',  'color' => '#dc2626', 'bg_color' => '#fee2e2', 'order' => 7, 'is_default' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 3. Cambiar columna status de ENUM a VARCHAR en leads
        // SQLite no soporta ALTER COLUMN, se hace recreando
        // Para MySQL:
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE leads MODIFY COLUMN status VARCHAR(100) NOT NULL DEFAULT "new"');
        }
        // Para SQLite la columna ya acepta cualquier string con ENUM en SQLite
        // (SQLite no enforcea ENUM realmente), así que no necesita cambio
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_statuses');
    }
};
