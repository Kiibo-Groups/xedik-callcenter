<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sin unique constraint rígido: el scope de un opt-out puede evolucionar
     * (tenant-wide hoy, por canal o por campaña después). El MVP aplica scope
     * 'tenant' y deduplica en código vía updateOrCreate — no a nivel de BD,
     * para no cerrar la puerta a scopes más finos.
     */
    public function up(): void
    {
        Schema::create('sms_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('phone'); // digits, mismo formato usado como destination/from
            $table->foreignId('sms_channel_id')->nullable()->constrained('sms_channels')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->string('scope', 20)->default('tenant'); // tenant | channel | campaign (futuro)
            $table->string('reason', 30); // keyword detectado: STOP, STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT
            $table->string('provider')->nullable();
            $table->string('source_message_id')->nullable();
            $table->timestamp('opted_out_at');
            $table->timestamps();

            $table->index(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_opt_outs');
    }
};
