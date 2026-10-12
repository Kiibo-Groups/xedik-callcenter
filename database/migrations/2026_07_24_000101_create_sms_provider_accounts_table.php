<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conexión técnica con un proveedor SMS (DIDWW, Twilio, Telnyx, SMS Masivos MX, ...).
     * tenant_id nullable: una cuenta puede ser compartida entre varios tenants (varios
     * sms_channels de distintos tenants apuntando a la misma cuenta técnica) o dedicada
     * a uno solo. Sin BelongsToTenant — catálogo global gestionado por superadmin,
     * mismo patrón que VoiceGateway.
     */
    public function up(): void
    {
        Schema::create('sms_provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider'); // 'didww', 'twilio', 'telnyx', ...
            $table->string('display_name');
            $table->string('region')->nullable();
            $table->text('credentials'); // Cast encrypted:array — JSON cifrado (username/password/api_key/...)
            $table->json('settings')->nullable(); // no-secreto: outbound_url, etc. — específico por proveedor
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['provider', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_provider_accounts');
    }
};
