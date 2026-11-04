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
        if (!Schema::hasTable('marketing_campaigns')) {
            Schema::create('marketing_campaigns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('name', 150);
                $table->unsignedBigInteger('campaign_id')->nullable()->index();
                $table->unsignedBigInteger('lead_list_id')->nullable()->index();
                $table->enum('channel', ['email', 'sms', 'whatsapp'])->index();

                // Sender accounts per channel
                $table->unsignedBigInteger('email_account_id')->nullable();
                $table->unsignedBigInteger('sms_channel_id')->nullable();
                $table->unsignedBigInteger('whatsapp_account_id')->nullable();

                // Templates per channel
                $table->unsignedBigInteger('email_template_id')->nullable();
                $table->unsignedBigInteger('sms_template_id')->nullable();
                $table->unsignedBigInteger('whatsapp_template_id')->nullable();

                // Optional custom/override subject & body
                $table->string('custom_subject', 255)->nullable();
                $table->text('custom_body')->nullable();

                // Pacing & conversation options
                $table->unsignedInteger('batch_size')->default(20);
                $table->unsignedInteger('delay_seconds')->default(2);
                $table->unsignedTinyInteger('max_retries')->default(1);
                $table->boolean('open_conversation')->default(true);

                // Lifecycle & scheduling
                $table->enum('status', ['idle', 'scheduled', 'running', 'paused', 'completed'])
                    ->default('idle')
                    ->index();
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('scheduled_end_at')->nullable();
                $table->time('daily_start_time')->nullable();
                $table->time('daily_end_time')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();

                // Counters
                $table->unsignedInteger('total_queued')->default(0);
                $table->unsignedInteger('sent_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);

                $table->timestamps();
            });
        }

        if (!Schema::hasTable('marketing_campaign_queue')) {
            Schema::create('marketing_campaign_queue', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('marketing_campaign_id')->index();
                $table->string('lead_list_table', 100)->nullable();
                $table->unsignedBigInteger('lead_row_id')->nullable();
                $table->string('recipient', 191)->index();
                $table->string('recipient_name', 191)->nullable();
                $table->json('variables')->nullable();
                $table->enum('status', ['pending', 'sending', 'sent', 'failed', 'skipped'])
                    ->default('pending')
                    ->index();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->string('provider_message_id', 191)->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->index(['marketing_campaign_id', 'status'], 'idx_mc_queue_camp_status');
            });
        }

        // Seed permission for sidebar navigation under Dialer module
        if (Schema::hasTable('permissions')) {
            $exists = DB::table('permissions')->where('name', 'manage_marketing_campaigns')->exists();
            if (!$exists) {
                $permId = DB::table('permissions')->insertGetId([
                    'name'       => 'manage_marketing_campaigns',
                    'label'      => 'Marketing Campaigns',
                    'module'     => 'dialer',
                    'route'      => 'marketing-campaigns.index',
                    'icon'       => 'bi-megaphone-fill',
                    'sort_order' => 25,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Grant automatically to roles and tenants that already have manage_dialer_campaigns
                $dialerPermId = DB::table('permissions')->where('name', 'manage_dialer_campaigns')->value('id');
                if ($dialerPermId) {
                    if (Schema::hasTable('permission_role')) {
                        $roleIds = DB::table('permission_role')->where('permission_id', $dialerPermId)->pluck('role_id');
                        foreach ($roleIds as $roleId) {
                            DB::table('permission_role')->insertOrIgnore([
                                'permission_id' => $permId,
                                'role_id'       => $roleId,
                            ]);
                        }
                    }
                    if (Schema::hasTable('permission_tenant')) {
                        $tenantIds = DB::table('permission_tenant')->where('permission_id', $dialerPermId)->pluck('tenant_id');
                        foreach ($tenantIds as $tenantId) {
                            DB::table('permission_tenant')->insertOrIgnore([
                                'permission_id' => $permId,
                                'tenant_id'     => $tenantId,
                            ]);
                        }
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaign_queue');
        Schema::dropIfExists('marketing_campaigns');
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('name', 'manage_marketing_campaigns')->delete();
        }
    }
};

