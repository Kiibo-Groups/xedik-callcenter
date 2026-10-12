<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->string('from_name')->nullable()->after('name');
            $table->string('from_email')->nullable()->after('from_name');
            $table->string('reply_to')->nullable()->after('from_email');
        });
    }

    public function down(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropColumn(['from_name', 'from_email', 'reply_to']);
        });
    }
};
