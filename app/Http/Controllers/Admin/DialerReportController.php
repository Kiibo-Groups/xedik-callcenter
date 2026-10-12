<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DialerCampaign;
use App\Models\LeadList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DialerReportController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('view_dialer_report'), 403);

        $leadLists = LeadList::orderBy('name')->get(['id', 'name', 'table_name', 'log_table_name', 'total_leads']);
        $campaigns = DialerCampaign::with('campaign')->orderBy('id', 'desc')->get(['id', 'lead_list_id']);

        $selectedListId    = $request->query('lead_list_id');
        $selectedCampaignId = $request->query('dialer_campaign_id');

        $logs  = null;
        $stats = null;
        $selectedList = null;

        if ($selectedListId) {
            $selectedList = $leadLists->firstWhere('id', $selectedListId);

            if ($selectedList) {
                $logTable = $selectedList->log_table_name;

                $query = DB::table($logTable)
                    ->leftJoin('dialer_campaigns', 'dialer_campaigns.id', '=', $logTable . '.dialer_campaign_id')
                    ->leftJoin('campaigns', 'campaigns.id', '=', 'dialer_campaigns.campaign_id')
                    ->select(
                        $logTable . '.*',
                        'dialer_campaigns.name as dc_name',
                        'campaigns.name as crm_campaign_name',
                        DB::raw("({$logTable}.duration - {$logTable}.billsec) as ring_time")
                    )
                    ->orderBy($logTable . '.called_at', 'desc');

                if ($selectedCampaignId) {
                    $query->where($logTable . '.dialer_campaign_id', $selectedCampaignId);
                }

                $logs = $query->paginate(50)->withQueryString();

                // Stats — agrupadas por final_status (fuente de verdad)
                $statsQuery = DB::table($logTable);
                if ($selectedCampaignId) {
                    $statsQuery->where('dialer_campaign_id', $selectedCampaignId);
                }
                $statRows = $statsQuery
                    ->selectRaw('final_status, COUNT(*) as total, SUM(duration) as total_duration, SUM(billsec) as total_billsec')
                    ->groupBy('final_status')
                    ->get()
                    ->keyBy('final_status');

                $totalCalls = $statRows->sum('total');
                $stats = [
                    'total'        => $totalCalls,
                    'answered'     => $statRows->get('ANSWER')?->total ?? 0,
                    'no_answer'    => $statRows->get('NO_ANSWER')?->total ?? 0,
                    'busy'         => $statRows->get('BUSY')?->total ?? 0,
                    'failed'       => $statRows->get('FAILED')?->total ?? 0,
                    'avg_duration' => $totalCalls > 0 ? round($statRows->sum('total_duration') / $totalCalls) : 0,
                    'avg_billsec'  => $totalCalls > 0 ? round($statRows->sum('total_billsec') / max(1, $statRows->filter(fn($r) => $r->total_billsec > 0)->sum('total'))) : 0,
                ];
            }
        }

        // Campaigns filter — solo las que tienen esta lead list
        $filteredCampaigns = $selectedListId
            ? DialerCampaign::with('campaign')
                ->where('lead_list_id', $selectedListId)
                ->orderBy('id', 'desc')
                ->get()
            : collect();

        return view('dialer_report.index', compact(
            'leadLists', 'filteredCampaigns', 'logs', 'stats',
            'selectedList', 'selectedListId', 'selectedCampaignId'
        ));
    }

    public function export(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('view_dialer_report'), 403);

        $selectedListId     = $request->query('lead_list_id');
        $selectedCampaignId = $request->query('dialer_campaign_id');

        abort_unless($selectedListId, 422);

        $selectedList = LeadList::findOrFail($selectedListId);
        $logTable     = $selectedList->log_table_name;
        $filename     = 'dialer-log-' . $selectedList->id . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($logTable, $selectedCampaignId) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Phone', 'Outcome', 'Disposition', 'Ring Time (s)', 'Talk Time (s)', 'Duration (s)', 'Campaign ID', 'Lead ID', 'Uniqueid', 'Called At']);

            DB::table($logTable)
                ->when($selectedCampaignId, fn($q) => $q->where('dialer_campaign_id', $selectedCampaignId))
                ->orderBy('called_at', 'desc')
                ->chunk(1000, function ($rows) use ($handle) {
                    foreach ($rows as $row) {
                        $ringTime = max(0, ($row->duration ?? 0) - ($row->billsec ?? 0));
                        fputcsv($handle, [
                            $row->id,
                            $row->phone,
                            $row->final_status ?? '',
                            $row->disposition ?? '',
                            $ringTime,
                            $row->billsec ?? 0,
                            $row->duration ?? 0,
                            $row->dialer_campaign_id,
                            $row->dialer_lead_id,
                            $row->uniqueid ?? '',
                            $row->called_at,
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
