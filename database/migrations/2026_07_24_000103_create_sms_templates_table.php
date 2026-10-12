<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // null = disponible para cualquier queue del tenant; seteado = solo esa queue.
            $table->foreignId('omni_queue_id')->nullable()->constrained('omni_queues')->nullOnDelete();
            $table->string('name');
            $table->text('body');
            // P2P: editable siempre (is_locked = false). Reservado para A2P futuro (plantillas
            // aprobadas por proveedor que el agente no podrá modificar antes de enviar).
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};
