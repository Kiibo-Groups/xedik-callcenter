<?php

namespace App\Http\Controllers;

use App\Models\AgentStatusLog;
use App\Models\User;
use App\Models\PauseReason;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Campaign;
use App\Models\CaptureGbpo;
use App\Models\Conversation;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use App\Models\Scopes\TenantScope;

class ReportController extends Controller
{
    public function agentStatus(Request $request)
    {
        $user         = Auth::user();
        $isSuperAdmin = $user->isSuperAdmin();

        $users        = $isSuperAdmin
            ? User::orderBy('name')->get()
            : User::where('tenant_id', $user->tenant_id)->orderBy('name')->get();

        $pauseReasons = PauseReason::orderBy('name')->get();
        $type         = $request->get('type', 'detailed');
        $campaigns    = Campaign::orderBy('name')->get();

        $query = AgentStatusLog::with(['user', 'pauseReason'])
            ->whereNotNull('ended_at')
            ->where('status', '!=', 'offline');

        if ($from = $request->get('from')) {
            $query->whereDate('started_at', '>=', $from);
        } else {
            $query->whereDate('started_at', '>=', now()->subDays(30)->startOfDay());
        }

        if ($to = $request->get('to')) {
            $query->whereDate('started_at', '<=', $to);
        }
        
        // Filtro campaña — limita los user_ids
        if ($campaignId = $request->get('campaign_id')) {
            $userIds = DB::table('campaign_user')
                ->where('campaign_id', $campaignId)
                ->pluck('user_id');
            $query->whereIn('user_id', $userIds);
        }

        if ($agentId = $request->get('agent_id')) {
            $query->where('user_id', $agentId);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($reasonId = $request->get('pause_reason_id')) {
            $query->where('pause_reason_id', $reasonId);
        }

        if ($request->get('export') === 'csv') {
            $logs = $query->orderBy('started_at', 'desc')->get();
            return $type === 'grouped'
                ? $this->exportGroupedCsv($logs)
                : $this->exportCsv($logs);
        }

        if ($type === 'grouped') {
            $logs    = $query->orderBy('started_at', 'desc')->get();
            $grouped = $this->buildGrouped($logs);
            $logs    = collect();
            return view('reports.agent_status', compact('logs', 'grouped', 'users', 'campaigns', 'pauseReasons', 'type'));
        }

        $logs    = $query->orderBy('started_at', 'desc')->paginate(20)->withQueryString();
        $grouped = collect();       

        return view('reports.agent_status', compact('logs', 'grouped', 'users', 'campaigns', 'pauseReasons', 'type'));
    }

    private function exportCsv($logs)
    {
        $filename = 'agent_status_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($logs) {
            $handle = fopen('php://output', 'w');

            // Header row
            fputcsv($handle, [
                'Agent', 'Status', 'Pause Reason',
                'Started At', 'Ended At', 'Duration'
            ]);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->user->name,
                    AgentStatusLog::displayLabel($log->status),
                    $log->pauseReason?->name ?? '',
                    $log->started_at->format('Y-m-d H:i:s'),
                    $log->ended_at?->format('Y-m-d H:i:s') ?? '',
                    $log->duration_formatted,
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }  

    private function buildGrouped($logs): \Illuminate\Support\Collection
    {
        return $logs->groupBy('user_id')->map(function ($agentLogs) {
            $user     = $agentLogs->first()->user;
            $byStatus = $agentLogs->groupBy(function ($log) {
                return $log->status === 'paused'
                    ? ($log->pauseReason?->name ?? 'Paused')
                    : AgentStatusLog::displayLabel($log->status);
            });

            $rows              = [];
            $totalPauses       = 0;
            $totalReady        = 0;
            // Ringing/In Call/Wrap-Up are Voice EFFECTIVE occupancy — they are their
            // own mutually-exclusive category and must never be folded into
            // "Total Pauses" (they are not a pause) nor into Ready (they are not idle).
            $totalVoiceOccupied = 0;

            foreach ($byStatus as $label => $statusLogs) {
                $seconds   = $statusLogs->sum('duration_seconds');
                $rawStatus = $statusLogs->first()->status;
                $isReady   = $rawStatus === 'ready';
                $isVoiceOccupied = in_array($rawStatus, AgentStatusLog::EFFECTIVE_STATUSES, true);
                $isPauseReason = $rawStatus === 'paused';

                if ($isReady) {
                    $totalReady += $seconds;
                } elseif ($isVoiceOccupied) {
                    $totalVoiceOccupied += $seconds;
                } elseif ($isPauseReason) {
                    $totalPauses += $seconds;
                }

                $firstLog   = $statusLogs->first();
                $pauseColor = $firstLog->pauseReason?->color ?? null;

                $rows[] = [
                    'agent'    => $user->name,
                    'status'   => $label,
                    'times'    => $statusLogs->count(),
                    'duration' => gmdate('H:i:s', $seconds),
                    'is_total' => false,
                    'is_ready' => $isReady,
                    'is_voice_occupied' => $isVoiceOccupied,
                    'paused_color' => $pauseColor,
                ];
            }

            // Filas de totales
            $rows[] = [
                'agent'    => $user->name,
                'status'   => 'Total Pauses',
                'times'    => '—',
                'duration' => gmdate('H:i:s', $totalPauses),
                'is_total' => true,
                'is_ready' => false,
                'is_voice_occupied' => false,
                'paused_color' => null,
            ];
            if ($totalVoiceOccupied > 0) {
                $rows[] = [
                    'agent'    => $user->name,
                    'status'   => 'Total Voice Occupancy',
                    'times'    => '—',
                    'duration' => gmdate('H:i:s', $totalVoiceOccupied),
                    'is_total' => true,
                    'is_ready' => false,
                    'is_voice_occupied' => true,
                    'paused_color' => null,
                ];
            }
            $rows[] = [
                'agent'    => $user->name,
                'status'   => 'Total',
                'times'    => '—',
                'duration' => gmdate('H:i:s', $totalReady + $totalPauses + $totalVoiceOccupied),
                'is_total' => true,
                'is_ready' => false,
                'is_voice_occupied' => false,
                'paused_color' => null,
            ];

            return [
                'user' => $user->name,
                'rows' => $rows,
            ];
        })->values();
    }

    private function exportGroupedCsv($logs)
    {
        $filename = 'agent_status_grouped_' . now()->format('Ymd_His') . '.csv';
        $grouped  = $this->buildGrouped($logs);

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($grouped) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Agent', 'Status', 'Times', 'Total Duration']);

            foreach ($grouped as $agentData) {
                foreach ($agentData['rows'] as $row) {
                    fputcsv($handle, [
                        $row['agent'],
                        $row['status'],
                        $row['times'],
                        $row['duration'],
                    ]);
                }
                fputcsv($handle, []); // línea vacía entre agentes
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
    public function agentsByCampaign(Request $request)
    {
        $user         = Auth::user();
        $isSuperAdmin = $user->isSuperAdmin();
        $campaignId   = $request->get('campaign_id');

        $query = User::orderBy('name');

        if (!$isSuperAdmin) {
            $query->where('tenant_id', $user->tenant_id);
        }

        if ($campaignId) {
            $query->whereHas('campaigns', function ($q) use ($campaignId) {
                $q->where('campaign_id', $campaignId);
            });
        }

        return response()->json($query->get(['id', 'name']));
    }
    public function cdr(Request $request)
    {
        $user = Auth::user();
        if (!$user->isSuperAdmin() && $user->tenant?->slug !== 'gbpo') {
            abort(403, 'Este reporte es exclusivo del tenant GBPO.');
        }

        $query = CaptureGbpo::query()
            ->select(
                'phonenumber',
                'did',
                'campaign',
                'agent',
                'uniqueid',
                'disposition',
                'disposition_code',
                'media_type',
                'duration_call',
                'recording_id',
                'created_date'
            );

        if ($request->filled('agent')) {
            if ($request->agent === '__none__') {
                $query->where(function ($q) {
                    $q->whereNull('agent')->orWhere('agent', '');
                });
            } else {
                $query->where('agent', $request->agent);
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_date', '<=', $request->date_to);
        }


        if ($request->get('export') === 'csv') {
            $records = $query->orderBy('created_date', 'desc')->get();
            $filename = 'cdr_' . now()->format('Ymd_His') . '.csv';
            $headers = [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ];
            $callback = function () use ($records) {
                $handle = fopen('php://output', 'w');
                fputs($handle, "\xEF\xBB\xBF");
                fputcsv($handle, ['Date', 'Phone', 'DID', 'Campaign', 'Agent', 'Disposition', 'Status Call', 'Media Type', 'Duration', 'Recording ID']);
                foreach ($records as $r) {
                    fputcsv($handle, [
                        $r->created_date,
                        $r->phonenumber,
                        $r->did,
                        $r->campaign,
                        $r->agent ?: 'None',
                        $r->disposition,
                        $r->disposition_code,
                        $r->media_type,
                        $r->duration_call,
                        $r->recording_id,
                    ]);
                }
                fclose($handle);
            };
            return response()->stream($callback, 200, $headers);
        }

        $type = $request->get('type', 'detail');

        if ($type === 'grouped') {
            $records = CaptureGbpo::query()
                ->select(
                    'agent',
                    DB::raw('COUNT(*) as total_calls'),
                    DB::raw('SUM(CAST(duration_call AS UNSIGNED)) as total_seconds')
                )
                ->when($request->filled('agent'), function ($q) use ($request) {
                    if ($request->agent === '__none__') {
                        $q->where(function ($q2) {
                            $q2->whereNull('agent')->orWhere('agent', '');
                        });
                    } else {
                        $q->where('agent', $request->agent);
                    }
                })
                ->when($request->filled('date_from'), fn($q) => $q->whereDate('created_date', '>=', $request->date_from))
                ->when($request->filled('date_to'), fn($q) => $q->whereDate('created_date', '<=', $request->date_to))
                ->groupBy('agent')
                ->orderBy('total_calls', 'desc')
                ->get()
                ->map(function ($r) {
                    $r->talk_time = gmdate('H:i:s', $r->total_seconds ?? 0);
                    return $r;
                });
        } else {
            $records = $query->orderBy('created_date', 'desc')->paginate(25)->withQueryString();
        }

        $agents = CaptureGbpo::select('agent')
            ->whereNotNull('agent')
            ->where('agent', '!=', '')
            ->distinct()
            ->orderBy('agent')
            ->pluck('agent');

        return view('reports.cdr', compact('records', 'agents', 'type'));
    }

    public function conversationDetail(\App\Models\Conversation $conversation)
    {
        $user    = Auth::user();
        $isAdmin = $user->hasPermission('view_all_conversations') || $user->isAdmin();

        if (!$isAdmin && $conversation->assigned_to !== $user->id) {
            abort(403);
        }

        $conversation->load(['lead.statusModel', 'lead.campaign', 'emailAccount', 'messages.attachments']);

        $messages = $conversation->messages->map(function($m) {
            return [
                'id'         => $m->id,
                'direction'  => $m->direction,
                'from_email' => $m->from_email,
                'to_email'   => $m->to_email,
                'body_html'  => $m->body_html,
                'body_text'  => $m->body_text,
                'sent_at'    => $m->sent_at?->format('M d, Y · g:i A'),
            ];
        });

        $lead = null;
        if ($conversation->lead) {
            $lead = [
                'first_name' => $conversation->lead->first_name,
                'last_name'  => $conversation->lead->last_name,
                'email'      => $conversation->lead->email,
            ];
        }

        return response()->json([
            'conversation' => [
                'id'         => $conversation->id,
                'subject'    => $conversation->subject,
                'from_email' => $conversation->from_email,
                'from_name'  => $conversation->from_name,
                'status'     => $conversation->status,
                'channel'    => $conversation->channel,
            ],
            'messages' => $messages,
            'lead'     => $lead,
        ]);
    }

    public function conversations(Request $request)
    {
        $user          = Auth::user();
        $isSuperAdmin  = $user->isSuperAdmin();
        $isAdmin       = $isSuperAdmin || $user->isAdmin() || $user->hasPermission('view_all_conversations');

        $agents       = $isSuperAdmin
            ? User::orderBy('name')->get(['id', 'name'])
            : User::where('tenant_id', $user->tenant_id)->orderBy('name')->get(['id', 'name']);
        $dispositions = \App\Models\DialerDisposition::orderBy('name')->get(['id', 'name']);

        $baseQuery = fn() => $isSuperAdmin
            ? \App\Models\ConversationReport::withoutGlobalScope(TenantScope::class)
            : \App\Models\ConversationReport::query();

        $query = $baseQuery()
            ->orderBy('resolved_at', 'desc');

        if (!$isAdmin) {
            $query->where('agent_id', $user->id);
        }

        if ($from = $request->get('from')) {
            $query->whereDate('resolved_at', '>=', $from);
        } else {
            $query->whereDate('resolved_at', '>=', now()->subDays(30)->startOfDay());
        }

        if ($to = $request->get('to')) {
            $query->whereDate('resolved_at', '<=', $to);
        }

        if ($agentId = $request->get('agent_id')) {
            $query->where('agent_id', $agentId);
        }

        if ($disposition = $request->get('disposition')) {
            $query->where('disposition', $disposition);
        }

        if ($channel = $request->get('channel')) {
            $query->where('channel', $channel);
        }

        if ($direction = $request->get('direction')) {
            $query->where('direction', $direction);
        }

        if ($search = $request->get('search')) {
            $query->where(function($q) use ($search) {
                $q->where('contact_email', 'like', "%{$search}%")
                  ->orWhere('lead_name',   'like', "%{$search}%")
                  ->orWhere('subject',     'like', "%{$search}%")
                  ->orWhere('agent_name',  'like', "%{$search}%");
            });
        }

        $type = $request->get('type', 'detailed');

        // ── Export CSV (siempre antes de las vistas) ──
        if ($request->get('export') === 'csv') {
            $filename = 'conversations_' . now()->format('Ymd_His') . '.csv';
            $headers  = [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ];

            if ($type === 'grouped') {
                $grouped = $baseQuery()
                    ->selectRaw("
                        agent_id, agent_name, channel,
                        COUNT(*) as total,
                        SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as total_inbound,
                        SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) as total_outbound,
                        AVG(duration_seconds) as avg_duration,
                        SUM(duration_seconds) as total_duration,
                        SUM(message_count) as total_msgs,
                        SUM(inbound_message_count) as total_inbound_msgs,
                        SUM(outbound_message_count) as total_outbound_msgs
                    ")
                    ->when(!$isAdmin, fn($q) => $q->where('agent_id', $user->id))
                    ->when($request->get('from'), fn($q) => $q->whereDate('resolved_at', '>=', $request->get('from')))
                    ->when(!$request->get('from'), fn($q) => $q->whereDate('resolved_at', '>=', now()->startOfDay()))
                    ->when($request->get('to'), fn($q) => $q->whereDate('resolved_at', '<=', $request->get('to')))
                    ->when($request->get('agent_id'), fn($q) => $q->where('agent_id', $request->get('agent_id')))
                    ->when($request->get('channel'), fn($q) => $q->where('channel', $request->get('channel')))
                    ->when($request->get('search'), function($q) use ($request) {
                        $search = $request->get('search');
                        $q->where(function($q2) use ($search) {
                            $q2->where('contact_email', 'like', "%{$search}%")
                               ->orWhere('lead_name',   'like', "%{$search}%")
                               ->orWhere('subject',     'like', "%{$search}%")
                               ->orWhere('agent_name',  'like', "%{$search}%");
                        });
                    })
                    ->groupBy('agent_id', 'agent_name', 'channel')
                    ->orderBy('agent_name')
                    ->orderBy('channel')
                    ->get()
                    ->groupBy('agent_id');

                $fmtSec = fn($s) => $s ? sprintf('%02d:%02d:%02d', floor($s/3600), floor(($s%3600)/60), (int)$s%60) : '—';

                $callback = function() use ($grouped, $fmtSec) {
                    $handle = fopen('php://output', 'w');
                    fputs($handle, "\xEF\xBB\xBF");
                    fputcsv($handle, [
                        'Agent', 'Channel', 'Convs',
                        'Msgs Total', 'Msgs Inbound', 'Msgs Outbound',
                        'Avg Duration', 'Total Time'
                    ]);
                    foreach ($grouped as $agentId => $rows) {
                        foreach ($rows as $row) {
                            fputcsv($handle, [
                                $row->agent_name,
                                strtoupper($row->channel),
                                $row->total,
                                $row->total_msgs ?? 0,
                                $row->total_inbound_msgs ?? 0,
                                $row->total_outbound_msgs ?? 0,
                                $fmtSec((int)$row->avg_duration),
                                $fmtSec($row->total_duration),
                            ]);
                        }
                        fputcsv($handle, [
                            'Total',
                            '',
                            $rows->sum('total'),
                            $rows->sum('total_msgs'),
                            $rows->sum('total_inbound_msgs'),
                            $rows->sum('total_outbound_msgs'),
                            $fmtSec((int)$rows->avg('avg_duration')),
                            $fmtSec($rows->sum('total_duration')),
                        ]);
                        fputcsv($handle, []);
                    }
                    fclose($handle);
                };
                return response()->stream($callback, 200, $headers);
            }

            // Detailed export
            $records  = $query->get();
            $callback = function() use ($records) {
                $handle = fopen('php://output', 'w');
                fputs($handle, "\xEF\xBB\xBF");
                fputcsv($handle, [
                    'Date', 'Contact', 'Agent', 'Channel', 'Direction',
                    'Subject', 'First Message', 'Disposition',
                    'Total Messages', 'Inbound Messages', 'Outbound Messages',
                    'Duration', 'Lead', 'Resolved By'
                ]);
                foreach ($records as $r) {
                    fputcsv($handle, [
                        $r->resolved_at?->format('Y-m-d H:i:s'),
                        $r->contact_email,
                        $r->agent_name,
                        strtoupper($r->channel),
                        ucfirst($r->direction),
                        $r->subject,
                        $r->first_message_preview,
                        $r->disposition,
                        $r->message_count,
                        $r->inbound_message_count,
                        $r->outbound_message_count,
                        $r->duration_formatted,
                        $r->lead_name ?? 'No Lead',
                        ucfirst($r->resolved_source ?? 'human'),
                    ]);
                }
                fclose($handle);
            };
            return response()->stream($callback, 200, $headers);
        }

        // ── Vista Grouped ──
        if ($type === 'grouped') {
            $grouped = $baseQuery()
                ->selectRaw("
                    agent_id,
                    agent_name,
                    channel,
                    COUNT(*) as total,
                    SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as total_inbound,
                    SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) as total_outbound,
                    AVG(duration_seconds) as avg_duration,
                    SUM(duration_seconds) as total_duration,
                    SUM(message_count) as total_msgs,
                    SUM(inbound_message_count) as total_inbound_msgs,
                    SUM(outbound_message_count) as total_outbound_msgs
                ")
                ->when(!$isAdmin, fn($q) => $q->where('agent_id', $user->id))
                ->when($request->get('from'), fn($q) => $q->whereDate('resolved_at', '>=', $request->get('from')))
                ->when(!$request->get('from'), fn($q) => $q->whereDate('resolved_at', '>=', now()->startOfDay()))
                ->when($request->get('to'), fn($q) => $q->whereDate('resolved_at', '<=', $request->get('to')))
                ->when($request->get('agent_id'), fn($q) => $q->where('agent_id', $request->get('agent_id')))
                ->when($request->get('channel'), fn($q) => $q->where('channel', $request->get('channel')))
                ->when($request->get('search'), function($q) use ($request) {
                    $search = $request->get('search');
                    $q->where(function($q2) use ($search) {
                        $q2->where('contact_email', 'like', "%{$search}%")
                           ->orWhere('lead_name',   'like', "%{$search}%")
                           ->orWhere('subject',     'like', "%{$search}%")
                           ->orWhere('agent_name',  'like', "%{$search}%");
                    });
                })
                ->groupBy('agent_id', 'agent_name', 'channel')
                ->orderBy('agent_name')
                ->orderBy('channel')
                ->get()
                ->groupBy('agent_id');

            $conversations = collect();
            return view('reports.conversations', compact(
                'conversations', 'agents', 'dispositions', 'isAdmin', 'type', 'grouped'
            ));
        }

        // ── Vista Detailed ──
        $conversations = $query->paginate(20)->withQueryString();

        $grouped = collect();
        return view('reports.conversations', compact(
            'conversations', 'agents', 'dispositions', 'isAdmin', 'type', 'grouped'
        ));
    }

    public function conversationReportDetail(int $id): \Illuminate\Http\JsonResponse
    {
        $user         = Auth::user();
        $isSuperAdmin = $user->isSuperAdmin();
        $isAdmin      = $isSuperAdmin || $user->isAdmin() || $user->hasPermission('view_all_conversations');

        $report = $isSuperAdmin
            ? \App\Models\ConversationReport::withoutGlobalScope(TenantScope::class)->findOrFail($id)
            : \App\Models\ConversationReport::findOrFail($id);

        if (!$isAdmin && $report->agent_id !== $user->id) {
            abort(403);
        }

        $report->load('messageReports');
        $messages = $report->messageReports->map(function ($m) {
            return [
                'id'         => $m->id,
                'direction'  => $m->direction,
                'from_email' => $m->from_email,
                'to_email'   => $m->to_email,
                'from_name'  => $m->from_name,
                'body_html'  => $m->body_html,
                'body_text'  => $m->body_text,
                'sent_at'    => $m->sent_at?->format('M d, Y · g:i:s A'),
            ];
        })->toArray();

        // Fallback: si no hay message_reports, busca en conversations activas
        if (empty($messages) && $report->conversation_id) {
            $conv = \App\Models\Conversation::with('messages')->find($report->conversation_id);
            if ($conv) {
                $messages = $conv->messages->map(function ($m) {
                    return [
                        'id'         => $m->id,
                        'direction'  => $m->direction,
                        'from_email' => $m->from_email,
                        'to_email'   => $m->to_email,
                        'from_name'  => $m->from_name,
                        'body_html'  => $m->body_html,
                        'body_text'  => $m->body_text,
                        'sent_at'    => $m->sent_at?->format('M d, Y · g:i:s A'),
                    ];
                })->toArray();
            }
        }

        return response()->json([
            'conversation' => [
                'id'          => $report->conversation_id,
                'subject'     => $report->subject,
                'from_email'  => $report->contact_email,
                'from_name'   => $report->lead_name,
                'status'      => 'resolved',
                'channel'     => $report->channel,
                'disposition' => $report->disposition,
                'notes'       => $report->notes,
                'agent_name'  => $report->agent_name,
                'resolved_at' => $report->resolved_at?->format('M d, Y · g:i A'),
            ],
            'messages'           => $messages,
            'messages_archived'  => empty($messages),
            'lead' => [
                'first_name' => explode(' ', $report->lead_name ?? '')[0] ?? '',
                'last_name'  => implode(' ', array_slice(explode(' ', $report->lead_name ?? ''), 1)) ?? '',
                'email'      => $report->contact_email,
            ],
        ]);
    }

    public function exportConversationCsv(int $id)
    {
        $user    = Auth::user();
        $isAdmin = $user->isAdmin() || $user->hasPermission('view_all_conversations');

        // $id es el ConversationReport.id — resolvemos la conversación real por conversation_id
        $report = \App\Models\ConversationReport::findOrFail($id);

        if (!$isAdmin && $report->agent_id !== $user->id) {
            abort(403);
        }

        // Intentar cargar desde message_reports (archivados)
        $report->load('messageReports');
        $messages = $report->messageReports->sortBy('sent_at');

        // Fallback: conversación aún existe en la tabla activa
        if ($messages->isEmpty() && $report->conversation_id) {
            $conv = \App\Models\Conversation::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                ->with('messages')
                ->find($report->conversation_id);
            if ($conv) {
                $messages = $conv->messages->sortBy('sent_at');
            }
        }

        $rows = [['Direction', 'From', 'To', 'Message', 'Date']];

        foreach ($messages as $msg) {
            $from = str_contains($msg->from_email ?? '', '@')
                ? $msg->from_email
                : \App\Helpers\PhoneHelper::formatDisplay($msg->from_email ?? '');

            $to = str_contains($msg->to_email ?? '', '@')
                ? $msg->to_email
                : \App\Helpers\PhoneHelper::formatDisplay($msg->to_email ?? '');

            $rows[] = [
                $msg->direction,
                $msg->from_name ?: $from,
                $to,
                $msg->body_text ?: strip_tags($msg->body_html ?? ''),
                $msg->sent_at?->format('Y-m-d H:i:s'),
            ];
        }

        $filename = 'conversation_' . $report->conversation_id . '_' . now()->format('Ymd') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($rows) {
            $file = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($file, $row);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function voicemail(Request $request)
    {
        $user = Auth::user();
        if (!$user->isSuperAdmin() && $user->tenant?->slug !== 'gbpo') {
            abort(403, 'Este reporte es exclusivo del tenant GBPO.');
        }

        $isAdmin = $user->isAdmin() || $user->isSuperAdmin();

        $query = CaptureGbpo::query()->where('disposition_code', 'VOICEMAIL');

        if (!$isAdmin) {
            $query->where('product_2', $user->phone_user_id);
        } elseif ($request->filled('agent')) {
            $query->where('product_2', $request->agent);
        }

        // Filtrar por campaña usando el dialer_pattern de la campaña CRM
        $selectedCampaign = null;
        if ($request->filled('campaign_id')) {
            $selectedCampaign = Campaign::find((int) $request->campaign_id);
            if ($selectedCampaign?->dialer_pattern) {
                $query->where('campaign', 'like', '%' . $selectedCampaign->dialer_pattern . '%');
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_date', '<=', $request->date_to);
        }

        if ($request->get('export') === 'csv') {
            $records  = $query->orderBy('created_date', 'desc')->get();
            $filename = 'voicemail_' . now()->format('Ymd_His') . '.csv';
            $headers  = [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ];
            $callback = function () use ($records) {
                $handle = fopen('php://output', 'w');
                fputs($handle, "\xEF\xBB\xBF");
                fputcsv($handle, ['Date', 'Phone', 'DID', 'Campaign', 'Agent', 'Disposition', 'Status Call', 'Media Type', 'Duration', 'Recording ID']);
                foreach ($records as $r) {
                    fputcsv($handle, [
                        $r->created_date,
                        $r->phonenumber,
                        $r->did,
                        $r->campaign,
                        $r->agent ?: 'None',
                        $r->disposition,
                        $r->disposition_code,
                        $r->media_type,
                        $r->duration_call,
                        $r->recording_id,
                    ]);
                }
                fclose($handle);
            };
            return response()->stream($callback, 200, $headers);
        }

        $records = $query->orderBy('created_date', 'desc')->paginate(25)->withQueryString();

        // Campaigns del CRM (TenantScope aplica para admin; superadmin ve las de GBPO)
        $campaigns = Campaign::where('is_active', true)->orderBy('name')->get();

        // Usuarios del tenant GBPO con phone_user_id, filtrados por campaña si aplica
        $agents = collect();
        if ($isAdmin) {
            $usersQuery = User::whereNotNull('phone_user_id')
                ->where('phone_user_id', '!=', '');

            if ($user->isSuperAdmin()) {
                $gbpoTenantId = Tenant::where('slug', 'gbpo')->value('id');
                $usersQuery->where('tenant_id', $gbpoTenantId);
            }
            // Admin GBPO: TenantScope ya aplica automáticamente

            if ($selectedCampaign) {
                $usersQuery->whereHas('campaigns', fn($q) => $q->where('campaigns.id', $selectedCampaign->id));
            }

            $agents = $usersQuery->orderBy('name')->get(['name', 'phone_user_id']);
        }

        return view('reports.voicemail', compact('records', 'campaigns', 'agents', 'isAdmin'));
    }

    public function voicemailAgents(Request $request)
    {
        $user = Auth::user();
        if (!$user->isSuperAdmin() && $user->tenant?->slug !== 'gbpo') {
            abort(403);
        }
        if (!$user->isAdmin() && !$user->isSuperAdmin()) {
            abort(403);
        }

        $usersQuery = User::whereNotNull('phone_user_id')->where('phone_user_id', '!=', '');

        if ($user->isSuperAdmin()) {
            $gbpoTenantId = Tenant::where('slug', 'gbpo')->value('id');
            $usersQuery->where('tenant_id', $gbpoTenantId);
        }

        if ($request->filled('campaign_id')) {
            $usersQuery->whereHas('campaigns', fn($q) => $q->where('campaigns.id', (int) $request->campaign_id));
        }

        return response()->json(
            $usersQuery->orderBy('name')->get(['name', 'phone_user_id'])
        );
    }
}