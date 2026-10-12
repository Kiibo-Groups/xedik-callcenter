<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telephony_calls', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->after('tenant_id');

            $table->index(['tenant_id', 'user_id'], 'tc_tenant_user_idx');
        });
    }

    public function down(): void
    {
        Schema::table('telephony_calls', function (Blueprint $table): void {
            $table->dropIndex('tc_tenant_user_idx');
            $table->dropColumn('user_id');
        });
    }
};
