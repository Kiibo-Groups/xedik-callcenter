<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('variable_source', 20)->default('leads')->after('body');
            $table->unsignedBigInteger('leadlist_id')->nullable()->after('variable_source');
            $table->index('leadlist_id');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropIndex(['leadlist_id']);
            $table->dropColumn(['variable_source', 'leadlist_id']);
        });
    }
};
