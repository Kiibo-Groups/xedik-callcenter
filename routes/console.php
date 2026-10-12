<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('email:poll')->everyTwoMinutes();
Schedule::command('agents:cleanup')->everyMinute();
Schedule::command('conversations:assign')->everyMinute();
Schedule::command('preview:recover-stale-offers')->everyMinute()->withoutOverlapping();

Schedule::call(function () {
    // Copiar mensajes a message_reports antes de borrar
    \App\Models\Conversation::where('status', 'resolved')
        ->whereDate('resolved_at', '<', today())
        ->whereExists(function ($q) {
            $q->select(\Illuminate\Support\Facades\DB::raw(1))
              ->from('conversation_reports')
              ->whereColumn('conversation_reports.conversation_id', 'conversations.id');
        })
        ->with('messages')
        ->get()
        ->each(function ($conv) {
            $report = \App\Models\ConversationReport::where('conversation_id', $conv->id)->first();
            if ($report && $conv->messages->isNotEmpty()) {
                foreach ($conv->messages as $msg) {
                    \App\Models\MessageReport::firstOrCreate(
                        ['conversation_report_id' => $report->id, 'sent_at' => $msg->sent_at],
                        [
                            'direction'  => $msg->direction,
                            'from_email' => $msg->from_email,
                            'to_email'   => $msg->to_email,
                            'from_name'  => $msg->from_name,
                            'subject'    => $msg->subject,
                            'body_html'  => $msg->body_html,
                            'body_text'  => $msg->body_text,
                        ]
                    );
                }
            }
        });

    \App\Models\Conversation::where('status', 'resolved')
        ->whereDate('resolved_at', '<', today())
        ->whereExists(function ($q) {
            $q->select(\Illuminate\Support\Facades\DB::raw(1))
              ->from('conversation_reports')
              ->whereColumn('conversation_reports.conversation_id', 'conversations.id');
        })
        ->delete();
    \Illuminate\Support\Facades\Log::info('Conversations cleanup: resolved conversations archived.');
})->dailyAt('02:00')->name('conversations:cleanup')->withoutOverlapping();
