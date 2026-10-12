<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Profile de Zernio del tenant — frontera multi-tenant del lado de Zernio.
            // Se crea lazy la primera vez que el tenant conecta un canal social.
            $table->string('zernio_profile_id', 50)->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('zernio_profile_id');
        });
    }
};
