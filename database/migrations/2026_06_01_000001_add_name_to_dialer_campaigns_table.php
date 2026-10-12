<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dialer_campaigns', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('dialer_campaigns', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
