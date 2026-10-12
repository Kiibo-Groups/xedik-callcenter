<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * SetupController
 *
 * Permite ejecutar tareas de mantenimiento desde el navegador
 * cuando no hay acceso a consola (ej: Hostinger).
 *
 * RUTAS (agregar en routes/web.php dentro del grupo auth):
 *   Route::get('/setup/assign-campaigns',  [SetupController::class, 'assignCampaigns'])->name('setup.assignCampaigns');
 *
 * ACCESO: Solo admins.
 * SEGURIDAD: Una vez usadas, puedes comentar las rutas o dejar el abort_unless.
 */
class SetupController extends Controller
{
    /**
     * GET /setup/assign-campaigns
     *
     * Asigna campaña a todos los leads que tienen campaign_id = NULL,
     * leyendo el nombre del dialer desde el campo `notes`.
     */
    public function assignCampaigns()
    {
        abort_unless(Auth::user()->isAdmin(), 403);

        $leads   = Lead::whereNull('campaign_id')->get();
        $default = Campaign::getDefault();

        $results   = [];
        $assigned  = 0;
        $unmatched = 0;

        foreach ($leads as $lead) {
            // Extraer nombre de campaña desde notes:
            // "Created from dialer. Campaign: gBPO TLMK PVD OB"
            $dialerCampaign = null;
            if ($lead->notes && preg_match('/Campaign:\s*(.+)$/i', $lead->notes, $matches)) {
                $dialerCampaign = trim($matches[1]);
            }

            $campaign = null;

            if ($dialerCampaign) {
                $campaign = Campaign::matchFromDialer($dialerCampaign);
            }

            // Fallback a campaña default
            if (!$campaign && $default) {
                $campaign = $default;
            }

            if ($campaign) {
                $lead->update(['campaign_id' => $campaign->id]);
                $results[] = [
                    'status'   => 'ok',
                    'lead_id'  => $lead->id,
                    'phone'    => $lead->phone,
                    'campaign' => $campaign->name,
                    'source'   => $dialerCampaign ? "dialer: {$dialerCampaign}" : 'default fallback',
                ];
                $assigned++;
            } else {
                $results[] = [
                    'status'   => 'unmatched',
                    'lead_id'  => $lead->id,
                    'phone'    => $lead->phone,
                    'campaign' => null,
                    'source'   => $dialerCampaign ?? 'no notes',
                ];
                $unmatched++;
            }
        }

        // Renderizar resultado como HTML simple
        $html = '<!DOCTYPE html><html><head>
            <meta charset="UTF-8">
            <title>Assign Campaigns — Xedik CRM</title>
            <style>
                body { font-family: monospace; padding: 32px; background: #0f172a; color: #e2e8f0; }
                h1   { color: #7c3aed; margin-bottom: 24px; }
                .ok        { color: #4ade80; }
                .unmatched { color: #facc15; }
                .summary   { margin-top: 24px; padding: 16px; background: #1e293b; border-radius: 8px; font-size: 15px; }
                table      { border-collapse: collapse; width: 100%; margin-top: 16px; }
                th, td     { padding: 8px 12px; border: 1px solid #334155; text-align: left; font-size: 13px; }
                th         { background: #1e293b; color: #94a3b8; }
            </style>
        </head><body>';

        $html .= '<h1>⚙️ Assign Campaigns — Result</h1>';

        if (empty($results)) {
            $html .= '<p class="ok">✅ No leads without campaign found. Nothing to do.</p>';
        } else {
            $html .= '<table>
                <thead><tr><th>Lead ID</th><th>Phone</th><th>Campaign</th><th>Source</th><th>Status</th></tr></thead>
                <tbody>';

            foreach ($results as $r) {
                $statusIcon = $r['status'] === 'ok' ? '✅' : '⚠️';
                $statusClass = $r['status'] === 'ok' ? 'ok' : 'unmatched';
                $campaign = $r['campaign'] ?? '— no match —';
                $html .= "<tr class=\"{$statusClass}\">
                    <td>#{$r['lead_id']}</td>
                    <td>{$r['phone']}</td>
                    <td>{$campaign}</td>
                    <td>{$r['source']}</td>
                    <td>{$statusIcon}</td>
                </tr>";
            }

            $html .= '</tbody></table>';

            $html .= "<div class=\"summary\">
                <strong>Done!</strong><br>
                ✅ Assigned: <strong>{$assigned}</strong> &nbsp;|&nbsp;
                ⚠️ Unmatched: <strong>{$unmatched}</strong>
            </div>";

            if ($unmatched > 0) {
                $html .= '<p style="margin-top:16px;color:#facc15;">
                    Tip: marca una campaña como Default para que los leads sin match se asignen automáticamente,
                    o asígnalos manualmente desde el edit del lead.
                </p>';
            }
        }

        $html .= '</body></html>';

        return response($html);
    }
}
