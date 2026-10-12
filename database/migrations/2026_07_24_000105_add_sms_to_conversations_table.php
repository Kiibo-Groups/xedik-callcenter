<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('sms_channel_id')->nullable()->after('whatsapp_account_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('sms_channel_id')->references('id')->on('sms_channels')->nullOnDelete();
        });

        // SQLite no valida enums (TEXT) — no requiere cambio. MySQL: extender el enum.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE conversations MODIFY COLUMN channel ENUM('email','chat','whatsapp','facebook','instagram','voice','sms') NOT NULL DEFAULT 'email'");
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['sms_channel_id']);
            $table->dropColumn('sms_channel_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE conversations MODIFY COLUMN channel ENUM('email','chat','whatsapp','facebook','instagram','voice') NOT NULL DEFAULT 'email'");
        }
    }
};
