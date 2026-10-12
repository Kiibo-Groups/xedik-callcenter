<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El número/canal SMS visible para el tenant — análogo a WhatsappAccount.
     * normalized_address: forma canónica (solo dígitos) usada para matching de
     * inbound/lookup, independiente del formato en que cada proveedor entregue "to"/"from".
     */
    public function up(): void
    {
        Schema::create('sms_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sms_provider_account_id')->constrained('sms_provider_accounts')->cascadeOnDelete();
            $table->foreignId('omni_queue_id')->nullable()->constrained('omni_queues')->nullOnDelete();
            $table->string('display_name');
            $table->string('address');            // valor tal cual lo usa el proveedor (source/destination)
            $table->string('normalized_address');  // solo dígitos, para matching de inbound
            $table->string('address_type')->default('long_code'); // long_code | toll_free | short_code
            $table->string('country', 5)->nullable();
            $table->json('capabilities')->nullable(); // {"p2p":true,"a2p":false}
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('normalized_address');
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_channels');
    }
};
