<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('omni_queue_id')->nullable()->constrained('omni_queues')->nullOnDelete();
            // string en vez de enum: linkedin y otras plataformas vendrán después
            // sin necesidad de ALTER TABLE (mismo trade-off que sources/statuses)
            $table->string('platform', 20);                       // facebook | instagram | linkedin...
            $table->string('zernio_profile_id', 50);              // profile del tenant en Zernio
            $table->string('zernio_account_id', 50)->unique();    // router del webhook (como phone_number_id en WA)
            $table->string('page_id', 50)->nullable();            // page de FB seleccionada (null en IG)
            $table->string('username')->nullable();
            $table->string('display_name');
            $table->string('picture_url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'platform']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('social_account_id')
                  ->nullable()
                  ->after('whatsapp_account_id')
                  ->constrained('social_accounts')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('social_account_id');
        });

        Schema::dropIfExists('social_accounts');
    }
};
