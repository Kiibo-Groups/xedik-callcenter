<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permission;
use App\Models\Role;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Permisos base
        $permissions = [
            // Console
            ['module' => 'console', 'name' => 'view_console', 'label' => 'Agent Console', 'route' => 'console.index', 'icon' => 'bi-headset', 'sort_order' => 1],
            // Leads
            ['module' => 'leads', 'name' => 'view_all_leads', 'label' => 'All Leads', 'route' => 'leads.index', 'icon' => 'bi-people-fill', 'sort_order' => 2],
            ['module' => 'leads', 'name' => 'view_my_leads', 'label' => 'My Leads', 'route' => 'leads.my', 'icon' => 'bi-person-check-fill', 'sort_order' => 3],
            ['module' => 'leads', 'name' => 'view_pipeline', 'label' => 'Pipeline', 'route' => 'pipeline.index', 'icon' => 'bi-kanban', 'sort_order' => 19],
            // Calls
            ['module' => 'calls', 'name' => 'view_scheduled_calls', 'label' => 'Scheduled Calls', 'route' => 'calls.schedule', 'icon' => 'bi-calendar-check-fill', 'sort_order' => 4],
            ['module' => 'calls', 'name' => 'view_voicemail', 'label' => 'Voicemail', 'route' => 'reports.voicemail', 'icon' => 'bi-voicemail', 'sort_order' => 20],
            // Admin
            ['module' => 'admin', 'name' => 'manage_users', 'label' => 'Users', 'route' => 'users.index', 'icon' => 'bi-people-fill', 'sort_order' => 5],
            ['module' => 'admin', 'name' => 'manage_campaigns', 'label' => 'Campaigns', 'route' => 'campaigns.index', 'icon' => 'bi-megaphone-fill', 'sort_order' => 6],
            ['module' => 'admin', 'name' => 'manage_lead_statuses', 'label' => 'Lead Statuses', 'route' => 'statuses.index', 'icon' => 'bi-tags-fill', 'sort_order' => 7],
            ['module' => 'admin', 'name' => 'manage_lead_sources', 'label' => 'Lead Sources', 'route' => 'sources.index', 'icon' => 'bi-diagram-3-fill', 'sort_order' => 8],
            ['module' => 'admin', 'name' => 'manage_tags', 'label' => 'Tags', 'route' => 'tags.index', 'icon' => 'bi-tag-fill', 'sort_order' => 17],
            ['module' => 'admin', 'name' => 'manage_dialer', 'label' => 'Dispositions', 'route' => 'dialer.index', 'icon' => 'bi-telephone-fill', 'sort_order' => 9],
            ['module' => 'admin', 'name' => 'manage_email_accounts', 'label' => 'Email Accounts', 'route' => 'email-accounts.index', 'icon' => 'bi-envelope-fill', 'sort_order' => 10],
            ['module' => 'admin', 'name' => 'manage_omni_queues', 'label' => 'Omni Queues', 'route' => 'omni-queues.index', 'icon' => 'bi-diagram-3-fill', 'sort_order' => 11],
            ['module' => 'admin', 'name' => 'manage_pause_reasons', 'label' => 'Pause Reasons', 'route' => 'pause-reasons.index', 'icon' => 'bi-pause-circle-fill', 'sort_order' => 12],
            // Reports
            ['module' => 'reports', 'name' => 'view_agent_status', 'label' => 'Agent Status', 'route' => 'reports.agent-status', 'icon' => 'bi-bar-chart-fill', 'sort_order' => 13],
            ['module' => 'reports', 'name' => 'view_cdr', 'label' => 'CDR Report', 'route' => 'reports.cdr', 'icon' => 'bi-telephone-fill', 'sort_order' => 14],
            // WhatsApp
            ['module' => 'admin', 'name' => 'manage_whatsapp_accounts', 'label' => 'WhatsApp Accounts', 'route' => 'whatsapp-accounts.index', 'icon' => 'bi-whatsapp', 'sort_order' => 15],
            ['module' => 'admin', 'name' => 'manage_whatsapp_templates', 'label' => 'WhatsApp Templates', 'route' => 'whatsapp-templates.index', 'icon' => 'bi-chat-square-text-fill', 'sort_order' => 16],
            ['module' => 'admin', 'name' => 'manage_email_templates', 'label' => 'Email Templates', 'route' => 'email-templates.index', 'icon' => 'bi-file-earmark-text-fill', 'sort_order' => 18],
            ['module' => 'admin', 'name' => 'manage_scripts',        'label' => 'Scripts',         'route' => 'scripts.index',         'icon' => 'bi-file-text-fill',          'sort_order' => 24],
            // Voice
            ['module' => 'voice', 'name' => 'manage_voice_settings',   'label' => 'Voice Settings',   'route' => 'admin.voice.tenant-settings.index', 'icon' => 'bi-gear-fill',       'sort_order' => 21],
            ['module' => 'voice', 'name' => 'manage_voice_extensions',  'label' => 'Voice Extensions', 'route' => 'admin.voice.extensions.index',       'icon' => 'bi-headset',         'sort_order' => 22],
            ['module' => 'voice', 'name' => 'manage_voice_gateways',    'label' => 'Voice Gateways',   'route' => 'admin.voice.gateways.index',         'icon' => 'bi-hdd-network-fill','sort_order' => 23],
        ];

        foreach ($permissions as $p) {
            Permission::updateOrCreate(['name' => $p['name']], $p);
        }

        // Roles base
        $admin = Role::updateOrCreate(['name' => 'admin'], ['label' => 'Admin']);
        $agent = Role::updateOrCreate(['name' => 'agent'], ['label' => 'Agent']);

        // Admin tiene todos los permisos
        $admin->permissions()->sync(Permission::all()->pluck('id'));

        // Agent solo tiene console, my leads y pipeline
        $agentPermissions = Permission::whereIn('name', ['view_console', 'view_my_leads', 'view_pipeline', 'view_scheduled_calls'])->pluck('id');
        $agent->permissions()->sync($agentPermissions);
    }
}