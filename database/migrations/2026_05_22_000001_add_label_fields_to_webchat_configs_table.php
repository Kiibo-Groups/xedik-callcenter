<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webchat_configs', function (Blueprint $table) {
            $table->string('label_position', 10)->default('top')->after('position');
            $table->string('label_visibility', 10)->default('always')->after('label_position');
        });
    }

    public function down(): void
    {
        Schema::table('webchat_configs', function (Blueprint $table) {
            $table->dropColumn(['label_position', 'label_visibility']);
        });
    }
};
