<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('restaurant_orders')) {
            Schema::create('restaurant_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('order_number', 32)->index();
                $table->string('channel', 32)->default('voice')->index(); // voice, whatsapp, facebook, instagram, manual
                $table->string('customer_name', 191)->nullable();
                $table->string('customer_phone', 64)->nullable()->index();
                $table->text('items_summary')->nullable();
                $table->string('delivery_type', 32)->default('delivery'); // delivery, pickup
                $table->text('delivery_address')->nullable();
                $table->string('payment_method', 64)->nullable();
                $table->decimal('total_amount', 10, 2)->nullable();
                $table->string('status', 32)->default('new')->index(); // new, preparing, ready, delivered, cancelled
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('call_id')->nullable()->index();
                $table->unsignedBigInteger('conversation_id')->nullable()->index();
                $table->unsignedBigInteger('lead_id')->nullable()->index();
                $table->timestamp('preparing_at')->nullable();
                $table->timestamp('ready_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();
            });
        }

        // Seed manage_orders and view_kds permissions
        $perms = [
            [
                'name'       => 'manage_orders',
                'label'      => 'Orders & Comandas',
                'module'     => 'orders',
                'route'      => 'orders.index',
                'icon'       => 'bi-receipt-cutoff',
                'sort_order' => 1,
            ],
            [
                'name'       => 'view_kds',
                'label'      => 'Live KDS Board',
                'module'     => 'orders',
                'route'      => 'orders.kds',
                'icon'       => 'bi-display',
                'sort_order' => 2,
            ],
        ];

        foreach ($perms as $p) {
            \Illuminate\Support\Facades\DB::table('permissions')->updateOrInsert(
                ['name' => $p['name']],
                array_merge($p, ['updated_at' => now(), 'created_at' => now()])
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_orders');
    }
};
