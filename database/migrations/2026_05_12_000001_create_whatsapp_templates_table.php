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
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('language', 10)->default('es');
            $table->string('display_name');
            $table->text('body_preview')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });

        $tenantId = DB::table('tenants')->where('id', 2)->value('id')
            ?? DB::table('tenants')->orderBy('id')->value('id');

        if ($tenantId) {
            DB::table('whatsapp_templates')->insert([
                'tenant_id'    => $tenantId,
                'name'         => 'welcome_message',
                'language'     => 'es',
                'display_name' => 'Welcome Message',
                'body_preview' => "Bienvenidos a ITSAAR\n\nHemos recibido correctamente tu solicitud de contacto. Un especialista de SAAR Tecnologías de la Información dará seguimiento a tu solicitud en breve.",
                'is_active'    => 1,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
