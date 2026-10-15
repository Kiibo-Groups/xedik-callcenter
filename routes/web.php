<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\LeadTagController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\DialerController;
use App\Http\Controllers\DialerApiController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\EmailAccountController;
use App\Http\Controllers\WhatsappAccountController;
use App\Http\Controllers\WhatsappTemplateController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\OmniQueueController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\PauseReasonController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\CallsReportController;
use App\Http\Controllers\RealtimeVoiceMonitorController;
use App\Http\Controllers\Supervisor\VoiceDashboardController;
use App\Http\Controllers\ProgramController;
use App\Http\Controllers\ScriptController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\Admin\LeadListController;
use App\Http\Controllers\Admin\LeadListImportController;
use App\Http\Controllers\Admin\DialerCampaignController;
use App\Http\Controllers\Admin\AccessTokenController;
use App\Http\Controllers\Admin\EndpointCatalogController;
use App\Http\Controllers\Dialer\LeadViewController;
use App\Http\Controllers\Dialer\LeadAddController;
use App\Http\Controllers\Admin\DialerDncController;
use App\Http\Controllers\Admin\DialerReportController;
use App\Http\Controllers\VoiceSessionController;
use Illuminate\Support\Facades\Mail;

// ── Screen pop (token auth, sin sesión Laravel) ──
Route::middleware('token.auth')->group(function () {
    Route::get('/dialer/lead-view',       [LeadViewController::class, 'show'])->name('dialer.lead-view');
    Route::post('/dialer/lead-view/save', [LeadViewController::class, 'save'])->name('dialer.lead-view.save');
    Route::get('/dialer/lead-add',        [LeadAddController::class, 'show'])->name('dialer.lead-add');
    Route::post('/dialer/lead-add',       [LeadAddController::class, 'store'])->name('dialer.lead-add.store');
});

// ── WEBHOOK (sin auth, sin CSRF) ──
Route::post('/webhook/call', [WebhookController::class, 'call'])->name('webhook.call');
Route::get('/webhooks/whatsapp',  [\App\Http\Controllers\WhatsappWebhookController::class, 'verify'])->name('webhook.whatsapp.verify')->middleware('throttle:10,1');
Route::post('/webhooks/whatsapp', [\App\Http\Controllers\WhatsappWebhookController::class, 'receive'])->name('webhook.whatsapp.receive');
Route::post('/webhooks/zernio',   [\App\Http\Controllers\ZernioWebhookController::class, 'receive'])->name('webhook.zernio.receive');
Route::post('/api/webhooks/sms/{provider}/inbound', [\App\Http\Controllers\SmsWebhookController::class, 'inbound'])->name('webhook.sms.inbound');
Route::post('/api/webhooks/sms/{provider}/status',  [\App\Http\Controllers\SmsWebhookController::class, 'status'])->name('webhook.sms.status');

// ── Auth routes ──
Route::get('/', fn() => auth()->check() ? view('home') : redirect()->route('login'));
Route::get('/login',  [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::get('/attachments/{attachment}/view', [AttachmentController::class, 'view'])->name('attachments.view');

// ── Protected routes ──
Route::middleware('auth')->group(function () {

    // Voice Session Ownership Check
    Route::middleware('voice.performance')->group(function () {
        Route::post('/voice/session/ownership',                 [VoiceSessionController::class, 'ownership'])->name('voice.session.ownership');
        Route::post('/voice/session/start',                     [VoiceSessionController::class, 'start'])->name('voice.session.start');
        Route::post('/voice/session/reconnect',                 [VoiceSessionController::class, 'reconnect'])->name('voice.session.reconnect');
        Route::post('/voice/session/take-over',                 [VoiceSessionController::class, 'takeOver'])->name('voice.session.take-over');
        Route::post('/voice/session/{sessionUuid}/registered',  [VoiceSessionController::class, 'registered'])->name('voice.session.registered');
        Route::post('/voice/session/{sessionUuid}/heartbeat',   [VoiceSessionController::class, 'heartbeat'])->name('voice.session.heartbeat');
        Route::post('/voice/session/{sessionUuid}/pagehide',    [VoiceSessionController::class, 'pagehide'])->name('voice.session.pagehide');
        Route::post('/voice/session/{sessionUuid}/revoke',      [VoiceSessionController::class, 'revoke'])->name('voice.session.revoke');
    });
    // Declarative call state update (no performance instrumentation needed)
    Route::post('/voice/session/call-state', [VoiceSessionController::class, 'callState'])->name('voice.session.call-state');

    // Voice conversation persistence — create/update from Agent Console adapter
    Route::post('/console/voice/quick-call/prepare',          [\App\Http\Controllers\VoiceConversationController::class, 'prepareQuickCall'])->name('voice.quick-call.prepare');
    Route::post('/console/voice/conversations',               [\App\Http\Controllers\VoiceConversationController::class, 'store'])->name('voice.conversations.store');
    Route::patch('/console/voice/calls/{callUuid}/state',     [\App\Http\Controllers\VoiceConversationController::class, 'updateState'])->name('voice.calls.updateState');
    Route::post('/console/voice/calls/{callUuid}/linkedid',   [\App\Http\Controllers\VoiceConversationController::class, 'correlateLinkedid'])->name('voice.calls.correlateLinkedid');
    Route::get('/voice/lab',                                [VoiceSessionController::class, 'lab'])->name('voice.lab');
    // Leads
    Route::get('/leads',                          [LeadController::class, 'index'])->name('leads.index')->middleware('permission:view_all_leads');
    Route::get('/leads/my',                       [LeadController::class, 'myLeads'])->name('leads.my')->middleware('permission:view_my_leads');
    Route::get('/leads/create',                   [LeadController::class, 'create'])->name('leads.create');
    Route::post('/leads',                         [LeadController::class, 'store'])->name('leads.store');
    Route::get('/leads/thread/{reportId}',        [LeadController::class, 'conversationThread'])->name('leads.conversationThread');
    Route::get('/leads/{lead}',                   [LeadController::class, 'show'])->name('leads.show');
    Route::patch('/leads/{lead}',    [LeadController::class, 'update'])->name('leads.update');
    Route::get('/leads/{lead}/edit', [LeadController::class, 'edit'])->name('leads.edit');
    Route::put('/leads/{lead}',      [LeadController::class, 'fullUpdate'])->name('leads.fullUpdate');
    Route::delete('/leads/{lead}',   [LeadController::class, 'destroy'])->name('leads.destroy');

    // Pipeline (Kanban)
    Route::get('/pipeline',                       [PipelineController::class, 'index'])->name('pipeline.index')->middleware('permission:view_pipeline');
    Route::patch('/pipeline/leads/{lead}/stage',  [PipelineController::class, 'updateStage'])->name('pipeline.updateStage')->middleware('permission:view_pipeline');
    Route::patch('/pipeline/status/reorder',      [PipelineController::class, 'reorderStatuses'])->name('pipeline.reorderStatuses')->middleware('permission:manage_lead_statuses');

    // Calls
    Route::get('/schedule',                     [CallController::class, 'schedule'])->name('calls.schedule')->middleware('permission:view_scheduled_calls');
    Route::post('/calls',                       [CallController::class, 'store'])->name('calls.store');
    Route::patch('/calls/{call}/complete',      [CallController::class, 'complete'])->name('calls.complete');
    Route::get('/leads/{lead}/calls',           [CallController::class, 'forLead'])->name('calls.forLead');

    // Lead Statuses
    Route::get('/statuses',                          [StatusController::class, 'index'])->name('statuses.index');
    Route::post('/statuses',                         [StatusController::class, 'store'])->name('statuses.store');
    Route::put('/statuses/{status}',                 [StatusController::class, 'update'])->name('statuses.update');
    Route::delete('/statuses/{status}',              [StatusController::class, 'destroy'])->name('statuses.destroy');
    Route::patch('/statuses/{status}/toggle-active', [StatusController::class, 'toggleActive'])->name('statuses.toggleActive');
    Route::post('/statuses/reorder',                 [StatusController::class, 'reorder'])->name('statuses.reorder');

    // Tags catalog
    Route::get('/tags/available', [TagController::class, 'available'])->name('tags.available');
    Route::get('/tags',           [TagController::class, 'index'])->name('tags.index');
    Route::post('/tags',          [TagController::class, 'store'])->name('tags.store');
    Route::put('/tags/{tag}',     [TagController::class, 'update'])->name('tags.update');
    Route::delete('/tags/{tag}',  [TagController::class, 'destroy'])->name('tags.destroy');

    // Email Templates — CRUD (manage_email_templates) + list/render for all agents
    Route::get('/email-templates',                                              [EmailTemplateController::class, 'index'])->name('email-templates.index');
    Route::get('/email-templates/create',            [EmailTemplateController::class, 'create'])->name('email-templates.create');
    Route::get('/email-templates/leadlist-fields',   [EmailTemplateController::class, 'leadListFields'])->name('email-templates.leadlist-fields');
    Route::post('/email-templates',                                             [EmailTemplateController::class, 'store'])->name('email-templates.store');
    Route::get('/email-templates/{emailTemplate}/edit',                         [EmailTemplateController::class, 'edit'])->name('email-templates.edit');
    Route::put('/email-templates/{emailTemplate}',                              [EmailTemplateController::class, 'update'])->name('email-templates.update');
    Route::delete('/email-templates/{emailTemplate}',                           [EmailTemplateController::class, 'destroy'])->name('email-templates.destroy');
    Route::get('/console/email-templates',                                      [EmailTemplateController::class, 'listForCompose'])->name('email-templates.list');
    Route::get('/console/email-template/{emailTemplate}/render',                [EmailTemplateController::class, 'render'])->name('email-templates.render');

    // Lead ↔ Tag assignment (agents, no manage_tags required)
    Route::post('/leads/{lead}/tags/{tag}',   [LeadTagController::class, 'attach'])->name('leads.tags.attach');
    Route::delete('/leads/{lead}/tags/{tag}', [LeadTagController::class, 'detach'])->name('leads.tags.detach');
    Route::post('/leads/{lead}/tags',         [LeadTagController::class, 'createAndAttach'])->name('leads.tags.createAttach');

    // Lead Sources
    Route::get('/sources',                         [SourceController::class, 'index'])->name('sources.index');
    Route::post('/sources',                        [SourceController::class, 'store'])->name('sources.store');
    Route::put('/sources/{source}',                [SourceController::class, 'update'])->name('sources.update');
    Route::delete('/sources/{source}',             [SourceController::class, 'destroy'])->name('sources.destroy');
    Route::patch('/sources/{source}/toggle-active',[SourceController::class, 'toggleActive'])->name('sources.toggleActive');
    Route::post('/sources/reorder',                [SourceController::class, 'reorder'])->name('sources.reorder');

    // Dialer API
    Route::get('/dialer/recording/{call}',   [DialerApiController::class, 'recording'])->name('dialer.recording');
    Route::post('/dialer/call/{lead}',       [DialerApiController::class, 'makeCall'])->name('dialer.call');
    Route::get('/dialer/recording-by-uid/{uid}', [DialerApiController::class, 'recordingByUid'])->name('dialer.recordingByUid');

    // Dialer whitelist
    Route::get('/dialer/dispositions',                        [DialerController::class, 'index'])->name('dialer.index');
    Route::post('/dialer/dispositions',                       [DialerController::class, 'store'])->name('dialer.store');
    Route::put('/dialer/dispositions/{disposition}',          [DialerController::class, 'update'])->name('dialer.update');
    Route::patch('/dialer/dispositions/{disposition}/toggle', [DialerController::class, 'toggleActive'])->name('dialer.toggle');
    Route::delete('/dialer/dispositions/{disposition}',       [DialerController::class, 'destroy'])->name('dialer.destroy');

    // Users
    Route::get('/users',                        [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create',                 [UserController::class, 'create'])->name('users.create');
    Route::get('/users/{user}/edit',            [UserController::class, 'edit'])->name('users.edit');
    Route::post('/users',                       [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}',                 [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}',              [UserController::class, 'destroy'])->name('users.destroy');
    Route::patch('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggleActive');
    Route::patch('/users/{user}/unlock', [UserController::class, 'unlock'])->name('users.unlock');

    // Campaigns
    Route::get('/campaigns',                            [CampaignController::class, 'index'])->name('campaigns.index');
    Route::get('/campaigns/create',                     [CampaignController::class, 'create'])->name('campaigns.create');
    Route::get('/campaigns/{campaign}/edit',            [CampaignController::class, 'edit'])->name('campaigns.edit');
    Route::post('/campaigns',                           [CampaignController::class, 'store'])->name('campaigns.store');
    Route::put('/campaigns/{campaign}',                 [CampaignController::class, 'update'])->name('campaigns.update');
    Route::delete('/campaigns/{campaign}',              [CampaignController::class, 'destroy'])->name('campaigns.destroy');
    Route::patch('/campaigns/{campaign}/toggle-active', [CampaignController::class, 'toggleActive'])->name('campaigns.toggleActive');

    // Email Accounts
    Route::middleware('permission:manage_email_accounts')->group(function () {
        Route::post('tools/test-imap', [EmailAccountController::class, 'testConnection'])->name('email-accounts.test-connection');
        Route::resource('email-accounts', EmailAccountController::class);
    });

    // WhatsApp Accounts & Templates
    Route::middleware('permission:manage_whatsapp_accounts')->group(function () {
        Route::resource('whatsapp-accounts', WhatsappAccountController::class);
    });

    // SMS — landing route for the sidebar's single "SMS" entry (tenant-admin).
    // Canonical destination is Numbers; existing sms-channels.*/sms-templates.*
    // route names are untouched for backward compatibility.
    Route::get('/sms', fn() => redirect()->route('sms-channels.index'))->name('sms.index');

    // SMS Channels (tenant-admin: solo ve el número y asigna queue) & Templates
    Route::middleware('permission:manage_sms_channels')->group(function () {
        Route::get('sms-channels',              [\App\Http\Controllers\SmsChannelController::class, 'index'])->name('sms-channels.index');
        Route::put('sms-channels/{smsChannel}', [\App\Http\Controllers\SmsChannelController::class, 'update'])->name('sms-channels.update');
    });
    Route::middleware('permission:manage_sms_templates')->group(function () {
        Route::resource('sms-templates', \App\Http\Controllers\SmsTemplateController::class)->except(['show', 'create', 'edit']);
        Route::patch('sms-templates/{smsTemplate}/toggle', [\App\Http\Controllers\SmsTemplateController::class, 'toggleActive'])->name('sms-templates.toggle');
    });

    // Conversational Automation (AI/n8n forwarding per channel) — tenant-scoped
    Route::middleware('permission:manage_conversation_automation')->prefix('admin')->name('admin.')->group(function () {
        Route::get('conversation-automation', [\App\Http\Controllers\Admin\ConversationAutomationController::class, 'index'])->name('conversation-automation.index');
        Route::put('conversation-automation/{channel}', [\App\Http\Controllers\Admin\ConversationAutomationController::class, 'update'])->name('conversation-automation.update');
    });

    // Social Network (Facebook/Instagram via Zernio)
    Route::middleware('permission:manage_social_accounts')->group(function () {
        Route::get('social-accounts',                          [\App\Http\Controllers\SocialAccountController::class, 'index'])->name('social-accounts.index');
        Route::put('social-accounts/{socialAccount}',          [\App\Http\Controllers\SocialAccountController::class, 'update'])->name('social-accounts.update');
        Route::patch('social-accounts/{socialAccount}/toggle', [\App\Http\Controllers\SocialAccountController::class, 'toggleActive'])->name('social-accounts.toggle');
        Route::delete('social-accounts/{socialAccount}',       [\App\Http\Controllers\SocialAccountController::class, 'destroy'])->name('social-accounts.destroy');
        Route::post('social-accounts/connect',                 [\App\Http\Controllers\SocialConnectController::class, 'redirect'])->name('social-accounts.connect');
        Route::get('social-accounts/callback',                 [\App\Http\Controllers\SocialConnectController::class, 'callback'])->name('social-accounts.callback');
        Route::post('social-accounts/select-page',             [\App\Http\Controllers\SocialConnectController::class, 'selectPage'])->name('social-accounts.selectPage');
    });
    Route::middleware('permission:manage_whatsapp_templates')->group(function () {
        Route::resource('whatsapp-templates', WhatsappTemplateController::class)->except(['show', 'create', 'edit']);
        Route::patch('whatsapp-templates/{whatsappTemplate}/toggle', [WhatsappTemplateController::class, 'toggleActive'])->name('whatsapp-templates.toggle');
    });

    // OmniQueues
    Route::get('omni-queues/agents-by-campaign/{campaignId}', [OmniQueueController::class, 'agentsByCampaign'])
        ->name('omni-queues.agents-by-campaign')
        ->middleware('permission:manage_omni_queues');
    Route::post('omni-queues/{omniQueue}/sync-asterisk', [OmniQueueController::class, 'syncAsterisk'])
        ->name('omni-queues.sync-asterisk')
        ->middleware('permission:manage_omni_queues');
    Route::resource('omni-queues', OmniQueueController::class);

    // Attachments
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])->name('attachments.download');

    // Agent
    Route::post('/agent/ping',   [AgentController::class, 'ping'])->name('agent.ping');
    Route::post('/agent/status', [AgentController::class, 'setStatus'])->name('agent.setStatus');
    Route::get('/agent/status',  [AgentController::class, 'status'])->name('agent.status');

    // Pause Reasons
    Route::get('/pause-reasons',                          [PauseReasonController::class, 'index'])->name('pause-reasons.index');
    Route::post('/pause-reasons',                         [PauseReasonController::class, 'store'])->name('pause-reasons.store');
    Route::put('/pause-reasons/{pauseReason}',            [PauseReasonController::class, 'update'])->name('pause-reasons.update');
    Route::delete('/pause-reasons/{pauseReason}',         [PauseReasonController::class, 'destroy'])->name('pause-reasons.destroy');
    Route::patch('/pause-reasons/{pauseReason}/toggle',   [PauseReasonController::class, 'toggleActive'])->name('pause-reasons.toggle');
    Route::post('/pause-reasons/reorder',                 [PauseReasonController::class, 'reorder'])->name('pause-reasons.reorder');

    // Console
    Route::get('/console',                                        [ConsoleController::class, 'index'])->name('console.index')->middleware('permission:view_console');
    Route::get('/console/unread',                                 [ConsoleController::class, 'unread'])->name('console.unread');
    Route::get('/console/my-conversations',                       [ConsoleController::class, 'myConversations'])->name('console.myConversations');
    Route::get('/console/conversation/{conversation}',            [ConsoleController::class, 'conversation'])->name('console.conversation');
    Route::post('/console/conversation/{conversation}/reply',     [ConsoleController::class, 'reply'])->name('console.reply');
    Route::post('/console/conversation/{conversation}/reply-all', [ConsoleController::class, 'replyAll'])->name('console.replyAll');
    Route::post('/console/conversation/{conversation}/forward',   [ConsoleController::class, 'forward'])->name('console.forward');
    Route::post('/console/compose',                               [ConsoleController::class, 'compose'])->name('console.compose');
    Route::patch('/console/conversation/{conversation}/read',     [ConsoleController::class, 'markRead'])->name('console.markRead');
    Route::post('/console/conversation/{conversation}/disposition',[ConsoleController::class, 'disposition'])->name('console.disposition');
    Route::post('/console/mark-all-read',                         [ConsoleController::class, 'markAllRead'])->name('console.markAllRead');
    Route::get('/console/message/{message}/html',                 [ConsoleController::class, 'messageHtml'])->name('console.messageHtml');
    Route::get('/console/agents/available',                       [ConsoleController::class, 'availableAgents'])->name('console.availableAgents');
    Route::post('/console/conversation/{conversation}/transfer',  [ConsoleController::class, 'transfer'])->name('console.transfer');
    Route::post('/console/conversation/{conversation}/create-lead',   [ConsoleController::class, 'createLead'])->name('console.createLead');
    Route::post('/console/conversation/{conversation}/associate-lead', [ConsoleController::class, 'associateLead'])->name('console.associateLead');
    Route::patch('/console/conversation/{conversation}/dynamic-lead-row/field', [ConsoleController::class, 'updateDynamicLeadRowField'])->name('console.updateDynamicLeadRowField');
    Route::post('/console/conversation/{conversation}/dynamic-lead-row/create-lead', [ConsoleController::class, 'createLeadFromDynamicLeadRow'])->name('console.createLeadFromDynamicLeadRow');
    Route::get('/console/leads/search',                           [ConsoleController::class, 'searchLeads'])->name('console.searchLeads');
    Route::get('/console/lead/{lead}',                            [ConsoleController::class, 'leadDetail'])->name('console.leadDetail');
    Route::get('/console/lead/{lead}/history',                    [ConsoleController::class, 'leadHistory'])->name('console.leadHistory');
    Route::post('/console/lead/{lead}/notes',                     [ConsoleController::class, 'saveLeadNotes'])->name('console.saveLeadNotes');
    Route::patch('/console/lead/{lead}/field',                    [ConsoleController::class, 'updateLeadField'])->name('console.updateLeadField');
    Route::get('/console/campaigns/my',                           [ConsoleController::class, 'myCampaigns'])->name('console.myCampaigns');
    Route::get('/console/preview/eligibility',                     [\App\Http\Controllers\PreviewDialerOfferController::class, 'eligibility'])->name('console.preview.eligibility');
    Route::get('/console/preview/offers/current',                  [\App\Http\Controllers\PreviewDialerOfferController::class, 'current'])->name('console.preview.offers.current');
    Route::post('/console/preview/offers/claim',                   [\App\Http\Controllers\PreviewDialerOfferController::class, 'claim'])->name('console.preview.offers.claim');
    Route::post('/console/preview/offers/{token}/accept',          [\App\Http\Controllers\PreviewDialerOfferController::class, 'accept'])->name('console.preview.offers.accept');
    Route::post('/console/preview/offers/{token}/decline',         [\App\Http\Controllers\PreviewDialerOfferController::class, 'decline'])->name('console.preview.offers.decline');
    Route::post('/console/preview/offers/{token}/expire',          [\App\Http\Controllers\PreviewDialerOfferController::class, 'expire'])->name('console.preview.offers.expire');
    Route::patch('/console/preview/offers/{token}/link',           [\App\Http\Controllers\PreviewDialerOfferController::class, 'link'])->name('console.preview.offers.link');
    Route::post('/console/preview/offers/{token}/recover-stale-call', [\App\Http\Controllers\PreviewDialerOfferController::class, 'recoverStaleCall'])->name('console.preview.offers.recover-stale-call');
    Route::get('/console/compose/accounts',                       [ConsoleController::class, 'composeAccounts'])->name('console.composeAccounts');
    Route::post('/console/whatsapp/send',                         [ConsoleController::class, 'sendWhatsapp'])->name('console.whatsapp.send');
    Route::post('/console/whatsapp/send-template',                [ConsoleController::class, 'sendWaTemplate'])->name('console.whatsapp.sendTemplate');
    Route::post('/console/social/send',                           [ConsoleController::class, 'sendSocial'])->name('console.social.send');
    Route::post('/console/sms/send',                              [ConsoleController::class, 'sendSmsP2p'])->name('console.sms.send');
    Route::post('/console/sms/reply',                             [ConsoleController::class, 'sendSmsReply'])->name('console.sms.reply');

    // Profile
    Route::get('/profile',  [UserController::class, 'profileGet'])->name('profile.get');
    Route::post('/profile', [UserController::class, 'profileSave'])->name('profile.save');

    // Reports
    Route::match(['get', 'post'], '/reports/agent-status', [ReportController::class, 'agentStatus'])
        ->name('reports.agent-status')->middleware('permission:view_agent_status');
    Route::get('/reports/agents-by-campaign', [ReportController::class, 'agentsByCampaign'])
        ->name('reports.agents-by-campaign');
    Route::get('/reports/cdr', [ReportController::class, 'cdr'])
        ->name('reports.cdr')->middleware('permission:view_cdr');
    Route::get('/reports/calls', [CallsReportController::class, 'index'])
        ->name('reports.calls.index')->middleware('permission:view_calls_report');
    Route::get('/reports/calls/export', [CallsReportController::class, 'export'])
        ->name('reports.calls.export')->middleware('permission:view_calls_report');
    Route::get('/reports/calls/{call}/recording', [CallsReportController::class, 'recording'])
        ->name('reports.calls.recording')->middleware(['permission:view_calls_report', 'throttle:30,1']);
    Route::get('/reports/calls/{call}/recording/download', [CallsReportController::class, 'recordingDownload'])
        ->name('reports.calls.recording.download')->middleware(['permission:view_calls_report', 'throttle:30,1']);
    Route::get('/reports/calls/{call}', [CallsReportController::class, 'show'])
        ->name('reports.calls.show')->middleware('permission:view_calls_report');
    Route::get('/reports/voicemail', [ReportController::class, 'voicemail'])
        ->name('reports.voicemail')->middleware('permission:view_voicemail');
    Route::get('/reports/voicemail/agents', [ReportController::class, 'voicemailAgents'])
        ->name('reports.voicemail.agents')->middleware('permission:view_voicemail');
    Route::get('/reports/conversations', [ReportController::class, 'conversations'])
        ->name('reports.conversations')->middleware('permission:view_conversations');
    Route::get('/reports/conversations/{conversation}', [ReportController::class, 'conversationDetail'])
        ->name('reports.conversations.detail')->middleware('permission:view_conversations');
    Route::get('/reports/conversation-report/{id}', [ReportController::class, 'conversationReportDetail'])
        ->name('reports.conversationReportDetail')->middleware('permission:view_conversations');
    Route::get('/reports/conversations/{id}/export-csv', [ReportController::class, 'exportConversationCsv'])
        ->name('reports.conversations.exportCsv')->middleware('permission:view_conversations');

    // Supervisor
    Route::get('/supervisor/dashboard', [VoiceDashboardController::class, 'index'])
        ->name('supervisor.dashboard.index')->middleware('permission:view_voice_dashboard');
    Route::get('/supervisor/dashboard/data', [VoiceDashboardController::class, 'data'])
        ->name('supervisor.dashboard.data')->middleware('permission:view_voice_dashboard');
    Route::get('/supervisor/realtime-voice', [RealtimeVoiceMonitorController::class, 'index'])
        ->name('supervisor.realtime-voice.index')->middleware('permission:view_realtime_voice_monitor');
    Route::get('/supervisor/realtime-voice/data', [RealtimeVoiceMonitorController::class, 'data'])
        ->name('supervisor.realtime-voice.data')->middleware('permission:view_realtime_voice_monitor');

    // Roles y Permisos
    Route::middleware('permission:manage_roles')->group(function () {
        Route::resource('roles', App\Http\Controllers\RoleController::class);
    });
    // Permissions: superadmin-only, enforced in PermissionController itself.
    Route::resource('permissions', App\Http\Controllers\PermissionController::class);

    // Scripts
    Route::middleware('permission:manage_scripts')->group(function () {
        Route::get('/scripts',                    [ScriptController::class, 'index'])->name('scripts.index');
        Route::get('/scripts/create',             [ScriptController::class, 'create'])->name('scripts.create');
        Route::post('/scripts',                   [ScriptController::class, 'store'])->name('scripts.store');
        Route::get('/scripts/{script}/edit',      [ScriptController::class, 'edit'])->name('scripts.edit');
        Route::put('/scripts/{script}',           [ScriptController::class, 'update'])->name('scripts.update');
        Route::delete('/scripts/{script}',        [ScriptController::class, 'destroy'])->name('scripts.destroy');
        Route::get('/scripts/by-campaign/{campaignId}', [ScriptController::class, 'byCampaign'])->name('scripts.byCampaign');
    });

    // Programs
    Route::get('/programs',              [ProgramController::class, 'index'])->name('programs.index')->middleware('permission:manage_programs');
    Route::post('/programs',             [ProgramController::class, 'store'])->name('programs.store');
    Route::put('/programs/{program}',    [ProgramController::class, 'update'])->name('programs.update');
    Route::delete('/programs/{program}', [ProgramController::class, 'destroy'])->name('programs.destroy');

    // Tenants (superadmin only)
    Route::get('/tenants',             [TenantController::class, 'index'])->name('tenants.index');
    Route::post('/tenants',            [TenantController::class, 'store'])->name('tenants.store');
    Route::put('/tenants/{tenant}',    [TenantController::class, 'update'])->name('tenants.update');
    Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');
    Route::get('/tenants/{tenant}/permissions', [TenantController::class, 'permissions'])->name('tenants.permissions');
    Route::put('/tenants/{tenant}/permissions', [TenantController::class, 'updatePermissions'])->name('tenants.permissions.update');

    // Compliance — State-Specific Calling Hours Rules (superadmin only)
    Route::prefix('admin/compliance')->name('admin.compliance.')->group(function () {
        Route::get('state-calling-rules',                       [\App\Http\Controllers\Admin\StateCallingRuleController::class, 'index'])->name('state-calling-rules.index');
        Route::get('state-calling-rules/create',                [\App\Http\Controllers\Admin\StateCallingRuleController::class, 'create'])->name('state-calling-rules.create');
        Route::post('state-calling-rules',                      [\App\Http\Controllers\Admin\StateCallingRuleController::class, 'store'])->name('state-calling-rules.store');
        Route::get('state-calling-rules/{rule}/edit',            [\App\Http\Controllers\Admin\StateCallingRuleController::class, 'edit'])->name('state-calling-rules.edit');
        Route::put('state-calling-rules/{rule}',                 [\App\Http\Controllers\Admin\StateCallingRuleController::class, 'update'])->name('state-calling-rules.update');
        Route::post('state-calling-rules/{rule}/toggle',         [\App\Http\Controllers\Admin\StateCallingRuleController::class, 'toggle'])->name('state-calling-rules.toggle');
        Route::delete('state-calling-rules/{rule}',              [\App\Http\Controllers\Admin\StateCallingRuleController::class, 'destroy'])->name('state-calling-rules.destroy');
    });

    // Power Dialer
    Route::get('/lead-lists',                       [LeadListController::class, 'index'])->name('lead-lists.index');
    Route::post('/lead-lists',                      [LeadListController::class, 'store'])->name('lead-lists.store');
    Route::get('/lead-lists/{leadList}/leads',       [LeadListController::class, 'leads'])->name('lead-lists.leads');
    Route::get('/lead-lists/{leadList}/export',      [LeadListController::class, 'export'])->name('lead-lists.export');
    Route::post('/lead-lists/{leadList}/import',     [LeadListImportController::class, 'import'])->name('lead-lists.import');
    Route::patch('/lead-lists/{leadList}/archive',          [LeadListController::class, 'archive'])->name('lead-lists.archive');
    Route::patch('/lead-lists/{leadList}/unarchive',        [LeadListController::class, 'unarchive'])->name('lead-lists.unarchive');
    Route::patch('/lead-lists/{leadList}/leads/{leadId}',   [LeadListController::class, 'updateLead'])->name('lead-lists.leads.update');
    Route::delete('/lead-lists/{leadList}',                 [LeadListController::class, 'destroy'])->name('lead-lists.destroy');

    Route::get('/dialer-campaigns',                                    [DialerCampaignController::class, 'index'])->name('dialer-campaigns.index');
    Route::post('/dialer-campaigns',                                   [DialerCampaignController::class, 'store'])->name('dialer-campaigns.store');
    Route::get('/dialer-campaigns/{dialerCampaign}/edit',              [DialerCampaignController::class, 'edit'])->name('dialer-campaigns.edit');
    Route::put('/dialer-campaigns/{dialerCampaign}',                   [DialerCampaignController::class, 'update'])->name('dialer-campaigns.update');
    Route::put('/dialer-campaigns/{dialerCampaign}/caller-ids',        [DialerCampaignController::class, 'updateCallerIds'])->name('dialer-campaigns.caller-ids');
    Route::delete('/dialer-campaigns/{dialerCampaign}',                [DialerCampaignController::class, 'destroy'])->name('dialer-campaigns.destroy');
    Route::post('/dialer-campaigns/{dialerCampaign}/play',             [DialerCampaignController::class, 'play'])->name('dialer-campaigns.play');
    Route::post('/dialer-campaigns/{dialerCampaign}/pause',            [DialerCampaignController::class, 'pause'])->name('dialer-campaigns.pause');
    Route::post('/dialer-campaigns/{dialerCampaign}/stop',             [DialerCampaignController::class, 'stop'])->name('dialer-campaigns.stop');
    Route::post('/dialer-campaigns/{dialerCampaign}/reset',            [DialerCampaignController::class, 'reset'])->name('dialer-campaigns.reset');
    Route::post('/dialer-campaigns/{dialerCampaign}/refresh-stats',    [DialerCampaignController::class, 'refreshStats'])->name('dialer-campaigns.refresh-stats');
    Route::put('/dialer-campaigns/{dialerCampaign}/filters',           [DialerCampaignController::class, 'updateFilters'])->name('dialer-campaigns.filters');
    Route::post('/dialer-campaigns/{dialerCampaign}/filters/test',     [DialerCampaignController::class, 'testFilters'])->name('dialer-campaigns.filters.test');
    Route::put('/dialer-campaigns/{dialerCampaign}/compliance',        [DialerCampaignController::class, 'updateCompliance'])->name('dialer-campaigns.compliance');
    Route::post('/dialer-campaigns/{dialerCampaign}/compliance/test',  [DialerCampaignController::class, 'testCompliance'])->name('dialer-campaigns.compliance.test');

    Route::get('/dialer-report',            [DialerReportController::class, 'index'])->name('dialer-report.index');
    Route::get('/dialer-report/export',     [DialerReportController::class, 'export'])->name('dialer-report.export');

    Route::get('/dialer-dnc',               [DialerDncController::class, 'index'])->name('dialer-dnc.index');
    Route::post('/dialer-dnc',              [DialerDncController::class, 'store'])->name('dialer-dnc.store');
    Route::delete('/dialer-dnc/{dialerDnc}',[DialerDncController::class, 'destroy'])->name('dialer-dnc.destroy');

    // Access Tokens
    Route::middleware('permission:manage_access_tokens')->group(function () {
        Route::get('/access-tokens',                       [AccessTokenController::class, 'index'])->name('access-tokens.index');
        Route::post('/access-tokens',                      [AccessTokenController::class, 'store'])->name('access-tokens.store');
        Route::patch('/access-tokens/{accessToken}/revoke',[AccessTokenController::class, 'revoke'])->name('access-tokens.revoke');
        Route::delete('/access-tokens/{accessToken}',      [AccessTokenController::class, 'destroy'])->name('access-tokens.destroy');
    });

    // Endpoints catalog
    Route::middleware('permission:manage_endpoints')->group(function () {
        Route::get('/endpoints',                               [EndpointCatalogController::class, 'index'])->name('endpoints.index');
        Route::post('/endpoints',                              [EndpointCatalogController::class, 'store'])->name('endpoints.store');
        Route::put('/endpoints/{endpointCatalog}',             [EndpointCatalogController::class, 'update'])->name('endpoints.update');
        Route::delete('/endpoints/{endpointCatalog}',          [EndpointCatalogController::class, 'destroy'])->name('endpoints.destroy');
    });

    // Webchat Configs (admin)
    Route::middleware('permission:manage_webchat_configs')->prefix('admin')->name('admin.')->group(function () {
        Route::resource('webchat-configs', \App\Http\Controllers\Admin\WebchatConfigController::class);
        Route::patch('webchat-configs/{webchatConfig}/toggle',
            [\App\Http\Controllers\Admin\WebchatConfigController::class, 'toggleActive'])
            ->name('webchat-configs.toggle');
        Route::post('webchat-configs/{webchatConfig}/regenerate-token',
            [\App\Http\Controllers\Admin\WebchatConfigController::class, 'regenerateToken'])
            ->name('webchat-configs.regenerate-token');
    });

    Route::post('/console/chat/{conversationId}/send', [ConsoleController::class, 'sendChat'])->name('console.chat.send');
    Route::post('/console/files/{file}/url', [ConsoleController::class, 'crmFileUrl'])->name('console.files.url');

    // ── Voice Admin (superadmin) ──────────────────────────────────────────────
    Route::prefix('admin/voice')->name('admin.voice.')->group(function () {
        // Overview
        Route::get('/', [\App\Http\Controllers\Admin\VoiceOverviewController::class, 'index'])
            ->name('overview');

        // Tenant Voice Settings
        Route::get('tenant-settings', [\App\Http\Controllers\Admin\VoiceTenantSettingController::class, 'index'])
            ->name('tenant-settings.index');
        Route::put('tenant-settings/{tenant}', [\App\Http\Controllers\Admin\VoiceTenantSettingController::class, 'update'])
            ->name('tenant-settings.update');

        // Extension Ranges
        Route::get('extension-ranges', [\App\Http\Controllers\Admin\VoiceExtensionRangeController::class, 'index'])
            ->name('extension-ranges.index');
        Route::post('extension-ranges', [\App\Http\Controllers\Admin\VoiceExtensionRangeController::class, 'store'])
            ->name('extension-ranges.store');
        Route::put('extension-ranges/{voiceExtensionRange}', [\App\Http\Controllers\Admin\VoiceExtensionRangeController::class, 'update'])
            ->name('extension-ranges.update');
        Route::delete('extension-ranges/{voiceExtensionRange}', [\App\Http\Controllers\Admin\VoiceExtensionRangeController::class, 'destroy'])
            ->name('extension-ranges.destroy');
        Route::patch('extension-ranges/{voiceExtensionRange}/toggle', [\App\Http\Controllers\Admin\VoiceExtensionRangeController::class, 'toggleActive'])
            ->name('extension-ranges.toggle');

        // Gateways
        Route::get('gateways', [\App\Http\Controllers\Admin\VoiceGatewayController::class, 'index'])
            ->name('gateways.index');
        Route::post('gateways', [\App\Http\Controllers\Admin\VoiceGatewayController::class, 'store'])
            ->name('gateways.store');
        Route::put('gateways/{voiceGateway}', [\App\Http\Controllers\Admin\VoiceGatewayController::class, 'update'])
            ->name('gateways.update');
        Route::delete('gateways/{voiceGateway}', [\App\Http\Controllers\Admin\VoiceGatewayController::class, 'destroy'])
            ->name('gateways.destroy');
        Route::patch('gateways/{voiceGateway}/toggle', [\App\Http\Controllers\Admin\VoiceGatewayController::class, 'toggleActive'])
            ->name('gateways.toggle');

        // Extensions (cross-tenant catalog)
        Route::get('extensions', [\App\Http\Controllers\Admin\VoiceExtensionAdminController::class, 'index'])
            ->name('extensions.index');
        Route::post('extensions/{voiceExtension}/reprovision', [\App\Http\Controllers\Admin\VoiceExtensionAdminController::class, 'reprovision'])
            ->name('extensions.reprovision');
        Route::post('extensions/{voiceExtension}/retry-deprovision', [\App\Http\Controllers\Admin\VoiceExtensionAdminController::class, 'retryDeprovision'])
            ->name('extensions.retry-deprovision');
        Route::patch('extensions/{voiceExtension}/toggle', [\App\Http\Controllers\Admin\VoiceExtensionAdminController::class, 'toggleActive'])
            ->name('extensions.toggle');

        // Caller IDs catalog (authorized outbound numbers per tenant)
        Route::get('caller-ids', [\App\Http\Controllers\Admin\VoiceCallerIdController::class, 'index'])
            ->name('caller-ids.index');
        Route::post('caller-ids', [\App\Http\Controllers\Admin\VoiceCallerIdController::class, 'store'])
            ->name('caller-ids.store');
        Route::put('caller-ids/{voiceCallerId}', [\App\Http\Controllers\Admin\VoiceCallerIdController::class, 'update'])
            ->name('caller-ids.update');
        Route::delete('caller-ids/{voiceCallerId}', [\App\Http\Controllers\Admin\VoiceCallerIdController::class, 'destroy'])
            ->name('caller-ids.destroy');
        Route::patch('caller-ids/{voiceCallerId}/toggle', [\App\Http\Controllers\Admin\VoiceCallerIdController::class, 'toggleActive'])
            ->name('caller-ids.toggle');
    });

    // ── SMS Admin (superadmin) ───────────────────────────────────────────────
    // Landing route for the sidebar's single "SMS" entry — canonical destination
    // is Providers; existing admin.sms.*.index route names are untouched.
    Route::get('/admin/sms', fn() => redirect()->route('admin.sms.provider-accounts.index'))->name('admin.sms.index');

    Route::prefix('admin/sms')->name('admin.sms.')->group(function () {
        Route::get('provider-accounts',                                    [\App\Http\Controllers\Admin\SmsProviderAccountController::class, 'index'])->name('provider-accounts.index');
        Route::post('provider-accounts',                                   [\App\Http\Controllers\Admin\SmsProviderAccountController::class, 'store'])->name('provider-accounts.store');
        Route::put('provider-accounts/{smsProviderAccount}',               [\App\Http\Controllers\Admin\SmsProviderAccountController::class, 'update'])->name('provider-accounts.update');
        Route::delete('provider-accounts/{smsProviderAccount}',            [\App\Http\Controllers\Admin\SmsProviderAccountController::class, 'destroy'])->name('provider-accounts.destroy');
        Route::patch('provider-accounts/{smsProviderAccount}/toggle',      [\App\Http\Controllers\Admin\SmsProviderAccountController::class, 'toggleActive'])->name('provider-accounts.toggle');

        Route::get('channels',                              [\App\Http\Controllers\Admin\SmsChannelController::class, 'index'])->name('channels.index');
        Route::post('channels',                              [\App\Http\Controllers\Admin\SmsChannelController::class, 'store'])->name('channels.store');
        Route::put('channels/{smsChannel}',                  [\App\Http\Controllers\Admin\SmsChannelController::class, 'update'])->name('channels.update');
        Route::delete('channels/{smsChannel}',                [\App\Http\Controllers\Admin\SmsChannelController::class, 'destroy'])->name('channels.destroy');
        Route::patch('channels/{smsChannel}/toggle',          [\App\Http\Controllers\Admin\SmsChannelController::class, 'toggleActive'])->name('channels.toggle');
    });

    // ── Storage Foundation (superadmin) ──────────────────────────────────────
    Route::prefix('admin/storage')->name('admin.storage.')->group(function () {
        // Storage Provider Catalog
        Route::get('providers', [\App\Http\Controllers\Admin\StorageProviderCatalogController::class, 'index'])
            ->name('providers.index');
        Route::patch('providers/{provider}/toggle', [\App\Http\Controllers\Admin\StorageProviderCatalogController::class, 'toggleActive'])
            ->name('providers.toggle');

        // Managed Storage Profiles
        Route::get('profiles', [\App\Http\Controllers\Admin\ManagedStorageProfileController::class, 'index'])
            ->name('profiles.index');
        Route::post('profiles', [\App\Http\Controllers\Admin\ManagedStorageProfileController::class, 'store'])
            ->name('profiles.store');
        Route::put('profiles/{profile}', [\App\Http\Controllers\Admin\ManagedStorageProfileController::class, 'update'])
            ->name('profiles.update');
        Route::post('profiles/{profile}/test-connection', [\App\Http\Controllers\Admin\ManagedStorageProfileController::class, 'testConnection'])
            ->name('profiles.test-connection');

        // Storage Plans
        Route::get('plans', [\App\Http\Controllers\Admin\StoragePlanController::class, 'index'])
            ->name('plans.index');
        Route::post('plans', [\App\Http\Controllers\Admin\StoragePlanController::class, 'store'])
            ->name('plans.store');
        Route::put('plans/{plan}', [\App\Http\Controllers\Admin\StoragePlanController::class, 'update'])
            ->name('plans.update');

        // Tenant Storage Settings
        Route::get('tenant-settings', [\App\Http\Controllers\Admin\TenantStorageSettingController::class, 'index'])
            ->name('tenant-settings.index');
        Route::post('tenant-settings', [\App\Http\Controllers\Admin\TenantStorageSettingController::class, 'save'])
            ->name('tenant-settings.save');
        Route::post('tenant-settings/test-connection', [\App\Http\Controllers\Admin\TenantStorageSettingController::class, 'testConnection'])
            ->name('tenant-settings.test-connection');
        Route::post('tenant-settings/subscriptions', [\App\Http\Controllers\Admin\TenantStorageSettingController::class, 'addSubscription'])
            ->name('tenant-settings.subscriptions.add');
        Route::delete('tenant-settings/subscriptions/{subscription}', [\App\Http\Controllers\Admin\TenantStorageSettingController::class, 'cancelSubscription'])
            ->name('tenant-settings.subscriptions.cancel');

        // CRM Files Browser
        Route::get('files', [\App\Http\Controllers\Admin\CrmFileBrowserController::class, 'index'])
            ->name('files.index');
        Route::post('files/{file}/temporary-url', [\App\Http\Controllers\Admin\CrmFileBrowserController::class, 'temporaryUrl'])
            ->name('files.temporary-url');
        Route::delete('files/{file}', [\App\Http\Controllers\Admin\CrmFileBrowserController::class, 'destroy'])
            ->name('files.destroy');
    });

});

// ── Webchat público ──
Route::prefix('api/webchat')->middleware('throttle:30,1')->group(function () {
    Route::get('{publicToken}/config',                          [\App\Http\Controllers\Webchat\PublicController::class, 'config']);
    Route::post('{publicToken}/start',                         [\App\Http\Controllers\Webchat\PublicController::class, 'start']);
    Route::post('{publicToken}/{sessionToken}/message',        [\App\Http\Controllers\Webchat\PublicController::class, 'message']);
    Route::post('{publicToken}/{sessionToken}/close',          [\App\Http\Controllers\Webchat\PublicController::class, 'close']);
    Route::get('{publicToken}/{sessionToken}/messages',        [\App\Http\Controllers\Webchat\PublicController::class, 'messages']);
});
Route::get('webchat/widget.js',      [\App\Http\Controllers\Webchat\PublicController::class, 'widget']);
Route::get('webchat/emoji-data',     function () {
    $path = public_path('js/vendor/emoji-mart/data.json');
    abort_unless(file_exists($path), 404);
    return response()->file($path, ['Content-Type' => 'application/json', 'Access-Control-Allow-Origin' => '*']);
});
Route::get('webchat-test/{publicToken}', [\App\Http\Controllers\Webchat\PublicController::class, 'testPage']);
