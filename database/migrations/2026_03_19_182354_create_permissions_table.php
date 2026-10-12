<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('module'); // console, leads, calls, admin, reports
            $table->string('name')->unique(); // view_console, view_all_leads
            $table->string('label'); // Agent Console, All Leads
            $table->string('route'); // reports.cdr, leads.index
            $table->string('icon')->default('bi-circle');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};