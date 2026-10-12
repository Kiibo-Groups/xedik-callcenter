<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        DB::table('lead_sources')->insert([
            ['name' => 'Web',         'is_active' => 1, 'order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Phone',       'is_active' => 1, 'order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Dialer',      'is_active' => 1, 'order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Referral',    'is_active' => 1, 'order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Email',       'is_active' => 1, 'order' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Social Media','is_active' => 1, 'order' => 6, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Other',       'is_active' => 1, 'order' => 7, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sources');
    }
};
