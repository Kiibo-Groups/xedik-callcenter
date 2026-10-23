<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Automation\ConversationAutomationController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\LeadListController;
use App\Http\Controllers\Api\RealtimeVoiceSnapshotController;
use App\Http\Controllers\Api\TelephonyQueueLogImportController;
use App\Http\Controllers\Api\WhatsappController;
use App\Http\Controllers\Api\Mobile\AuthController;
use App\Http\Controllers\Api\Mobile\AgentStatusController;
use App\Http\Controllers\Api\Mobile\CatalogController;
use App\Http\Controllers\Api\Mobile\ConversationController;
use App\Http\Controllers\Api\Mobile\DispositionController;
use App\Http\Controllers\Api\Mobile\LeadController;
use App\Http\Controllers\Api\Mobile\PusherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — sin sesión Laravel, sin CSRF
|--------------------------------------------------------------------------
| Grupo 1 (token.auth): API externa para dialers — autenticación por
|   query param ?token= (AccessToken de tenant).
|
| Grupo 2 (mobile.auth): PWA de agentes — autenticación por Bearer token
|   personal (PersonalToken de usuario).
*/

// ── Grupo 1: API externa / Dialer ─────────────────────────────────────────────

Route::post('telephony/realtime/snapshot', [RealtimeVoiceSnapshotController::class, 'store'])
    ->name('api.telephony.realtime.snapshot');

Route::post('voicebot/appointment', [\App\Http\Controllers\Api\VoicebotController::class, 'logCall'])
    ->name('api.voicebot.appointment');

Route::middleware('token.auth')->group(function () {
    // Lead Lists
    Route::post(  'lead-lists/{leadListId}/leads',         [LeadListController::class, 'store']  )->name('api.leads.store');
    Route::put(   'lead-lists/{leadListId}/leads/{phone}', [LeadListController::class, 'update'] )->name('api.leads.update');
    Route::delete('lead-lists/{leadListId}/leads/{phone}', [LeadListController::class, 'destroy'])->name('api.leads.destroy');

    // Emails
    Route::post('emails/send-by-template', [EmailController::class, 'sendByTemplate'])->name('api.emails.send-by-template');
    Route::post('telephony/queue-log/import', [TelephonyQueueLogImportController::class, 'store'])->name('api.telephony.queue-log.import');

    // WhatsApp (legacy — kept during transition to the generic automation routes below)
    Route::post('whatsapp/bot-reply', [WhatsappController::class, 'sendBotReply'])->name('api.whatsapp.bot-reply');
    Route::post('whatsapp/handoff',   [WhatsappController::class, 'handoff'])->name('api.whatsapp.handoff');

    // Generic conversational automation (n8n/AI) — channel-agnostic, routes by conversation.channel
    Route::prefix('automation/conversations/{conversation}')->name('api.automation.conversations.')->group(function () {
        Route::post('handoff', [ConversationAutomationController::class, 'handoff'])->name('handoff');
        Route::post('reply',   [ConversationAutomationController::class, 'reply'])->name('reply');
        Route::post('resolve', [ConversationAutomationController::class, 'resolve'])->name('resolve');
    });
});

// ── Grupo 2: Mobile PWA ────────────────────────────────────────────────────────

// Login público (sin auth)
Route::post('mobile/login', [AuthController::class, 'login'])->name('api.mobile.login');

Route::middleware('mobile.auth')->prefix('mobile')->name('api.mobile.')->group(function () {
    // Auth
    Route::get( 'me',     [AuthController::class, 'me']    )->name('me');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    // Conversations
    Route::get( 'conversations',              [ConversationController::class, 'index'] )->name('conversations.index');
    Route::get( 'conversations/{id}',         [ConversationController::class, 'show']  )->name('conversations.show');
    Route::post('conversations/{id}/reply',   [ConversationController::class, 'reply'] )->name('conversations.reply');

    // Leads
    Route::post('leads',             [LeadController::class, 'store']  )->name('leads.store');
    Route::get('leads',              [LeadController::class, 'index']  )->name('leads.index');
    Route::get('leads/{id}',         [LeadController::class, 'show']   )->name('leads.show');
    Route::put('leads/{id}',         [LeadController::class, 'update'] )->name('leads.update');
    Route::get('leads/{id}/notes',   [LeadController::class, 'notes']  )->name('leads.notes');
    Route::post('leads/{id}/notes',  [LeadController::class, 'addNote'])->name('leads.addNote');
    Route::get('leads/{id}/history', [LeadController::class, 'history'])->name('leads.history');
    Route::put('leads/{id}/tags',    [LeadController::class, 'syncTags'])->name('leads.tags.sync');

    // Dispositions
    Route::get( 'dispositions',               [DispositionController::class, 'index'])->name('dispositions.index');
    Route::post('conversations/{id}/disposition', [DispositionController::class, 'store'])->name('conversations.disposition');

    // Catalogs (statuses, campaigns)
    Route::get('lead-statuses', [CatalogController::class, 'leadStatuses'])->name('lead-statuses.index');
    Route::get('campaigns',     [CatalogController::class, 'campaigns']    )->name('campaigns.index');
    Route::get('tags',          [CatalogController::class, 'tags']         )->name('tags.index');

    // Agent status
    Route::get( 'agent/status', [AgentStatusController::class, 'show'] )->name('agent.status.show');
    Route::post('agent/status', [AgentStatusController::class, 'store'])->name('agent.status.store');
    Route::post('agent/ping',   [AgentStatusController::class, 'ping'] )->name('agent.ping');

    // Pusher channel auth (private channels)
    Route::post('pusher/auth', [PusherController::class, 'auth'])->name('pusher.auth');
});
