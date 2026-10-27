<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeadList;
use App\Models\Tenant;
use App\Services\DialerTableService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadListController extends Controller
{
    public function __construct(private DialerTableService $tableService) {}

    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_lists'), 403);

        $user = auth()->user();
        $lists = LeadList::orderBy('created_at', 'desc')->get();
        $isSuperAdmin = $user->isSuperAdmin();
        $tenants = $isSuperAdmin
            ? Tenant::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('lead_lists.index', compact('lists', 'isSuperAdmin', 'tenants'));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_lists'), 403);

        $user = auth()->user();
        $isSuperAdmin = $user->isSuperAdmin();

        $rules = [
            'name' => [
                'required', 'string', 'max:100',
            ],
        ];

        if ($isSuperAdmin) {
            $rules['tenant_id'] = ['required', 'integer', Rule::exists('tenants', 'id')->where('is_active', true)];
        }

        $validated = $request->validate($rules);

        $tenantId = $isSuperAdmin
            ? (int) $validated['tenant_id']
            : (int) $user->tenant_id;

        $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('lead_lists', 'name')->where('tenant_id', $tenantId),
            ],
        ]);

        // MySQL/MariaDB: CREATE TABLE hace implicit commit — no se puede usar DB::transaction() con DDL.
        // Usamos try/catch para cleanup manual si algo falla.

        // 1. Crear registro para obtener el ID
        $leadList = LeadList::create([
            'tenant_id'       => $tenantId,
            'name'           => $request->name,
            'table_name'     => 'placeholder',
            'log_table_name' => 'placeholder_log',
            'status'         => 'draft',
        ]);

        // 2. Nombres determinísticos basados en el ID
        $tableName    = 'dl_' . $leadList->id . '_leads';
        $logTableName = 'dl_' . $leadList->id . '_log';

        try {
            // 3. Crear tablas dinámicas
            $this->tableService->createLeadTable($tableName);
            $this->tableService->createLogTable($logTableName);

            // 4. Actualizar con nombres reales
            $leadList->update([
                'table_name'     => $tableName,
                'log_table_name' => $logTableName,
            ]);
        } catch (\Throwable $e) {
            // Cleanup manual: borrar registro y tablas si algo falló
            $leadList->forceDelete();
            try { $this->tableService->dropLeadTable($tableName); } catch (\Throwable $_) {}
            try { $this->tableService->dropLogTable($logTableName); } catch (\Throwable $_) {}
            return back()->with('error', 'Failed to create lead list: ' . $e->getMessage());
        }

        return back()->with('success', "Lead list \"{$request->name}\" created (tables: {$tableName}, {$logTableName}).");
    }

    public function destroy(LeadList $leadList)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_lists'), 403);

        if ($leadList->hasActiveCampaigns()) {
            return back()->with('error', 'Cannot delete: there are active or paused campaigns using this list.');
        }

        $this->tableService->dropLeadTable($leadList->table_name);
        $this->tableService->dropLogTable($leadList->log_table_name);

        $leadList->delete();

        return back()->with('success', "Lead list \"{$leadList->name}\" deleted along with its tables.");
    }

    public function export(LeadList $leadList): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_lists'), 403);

        $table    = $leadList->table_name;
        $tenantId = (int) $leadList->tenant_id;
        $filename = 'leadlist-' . $leadList->id . '-' . str_replace(' ', '_', $leadList->name) . '.csv';

        return response()->streamDownload(function () use ($table, $tenantId) {
            $handle = fopen('php://output', 'w');

            // Detectar custom_fields keys del primer registro
            $customKeys = [];
            $first = \Illuminate\Support\Facades\DB::table($table)->whereNotNull('custom_fields')->first();
            if ($first && $first->custom_fields) {
                $decoded = json_decode($first->custom_fields, true);
                if (is_array($decoded)) $customKeys = array_keys($decoded);
            }

            // Header row
            fputcsv($handle, array_merge(
                ['id', 'phone', 'status', 'called_count', 'last_called_at', 'is_dnc'],
                $customKeys
            ));

            // Stream rows in chunks
            \Illuminate\Support\Facades\DB::table("{$table} as dl")
                ->leftJoin('dialer_dnc as dnc', function ($j) use ($tenantId) {
                    $j->on('dnc.phone', '=', 'dl.phone')
                      ->where('dnc.tenant_id', '=', $tenantId);
                })
                ->selectRaw('dl.*, CASE WHEN dnc.phone IS NOT NULL THEN 1 ELSE 0 END as is_dnc')
                ->orderBy('dl.id')
                ->chunk(1000, function ($rows) use ($handle, $customKeys) {
                    foreach ($rows as $row) {
                        $custom = $row->custom_fields ? json_decode($row->custom_fields, true) : [];
                        fputcsv($handle, array_merge(
                            [
                                $row->id,
                                $row->phone,
                                $row->status,
                                $row->called_count,
                                $row->last_called_at ?? '',
                                $row->is_dnc ? 'YES' : '',
                            ],
                            array_map(fn($k) => $custom[$k] ?? '', $customKeys)
                        ));
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function leads(LeadList $leadList, \Illuminate\Http\Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_lists'), 403);

        $q        = trim($request->query('q', ''));
        $table    = $leadList->table_name;
        $tenantId = (int) $leadList->tenant_id;

        $query = \Illuminate\Support\Facades\DB::table("{$table} as dl")
            ->leftJoin('dialer_dnc as dnc', function ($join) use ($tenantId) {
                $join->on('dnc.phone', '=', 'dl.phone')
                     ->where('dnc.tenant_id', '=', $tenantId);
            })
            ->selectRaw('dl.*, CASE WHEN dnc.phone IS NOT NULL THEN 1 ELSE 0 END as is_dnc')
            ->orderBy('dl.id', 'asc');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('dl.phone', 'like', "%{$q}%")
                    ->orWhere('dl.custom_fields', 'like', "%{$q}%");
            });
        }

        $leads = $query->paginate(50)->withQueryString();

        return view('lead_lists.leads', compact('leadList', 'leads', 'q'));
    }

    public function archive(LeadList $leadList)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_lists'), 403);

        if ($leadList->hasActiveCampaigns()) {
            return back()->with('error', 'Cannot archive: there are active campaigns using this list.');
        }

        $leadList->update(['status' => 'archived']);

        return back()->with('success', "Lead list \"{$leadList->name}\" archived.");
    }

    public function unarchive(LeadList $leadList)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_lists'), 403);

        $status = $leadList->total_leads > 0 ? 'ready' : 'draft';
        $leadList->update(['status' => $status]);

        return back()->with('success', "Lead list \"{$leadList->name}\" restored to {$status}.");
    }

    public function updateLead(Request $request, LeadList $leadList, int $leadId): \Illuminate\Http\JsonResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_lists'), 403);

        $request->validate([
            'custom_fields' => 'nullable|array',
        ]);

        $table = $leadList->table_name;
        $lead  = \Illuminate\Support\Facades\DB::table($table)->where('id', $leadId)->first();

        abort_if($lead === null, 404);

        $custom = array_map('strval', (array) $request->input('custom_fields', []));

        \Illuminate\Support\Facades\DB::table($table)->where('id', $leadId)->update([
            'custom_fields' => !empty($custom) ? json_encode($custom, JSON_UNESCAPED_UNICODE) : null,
        ]);

        return response()->json(['ok' => true]);
    }

    public function storeLead(Request $request, LeadList $leadList)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_lists'), 403);

        $request->validate([
            'phone' => 'required|string|max:30',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|string|max:150',
            'custom_fields' => 'nullable|array',
        ]);

        $rawPhone = (string) $request->input('phone');
        $phone = preg_replace('/[^\d+]/', '', $rawPhone);
        if (strlen($phone) < 7) {
            return back()->with('error', 'El número de teléfono no es válido.');
        }

        $tenantId = (int) $leadList->tenant_id;

        // Check DNC
        $isDnc = \Illuminate\Support\Facades\DB::table('dialer_dnc')
            ->where('tenant_id', $tenantId)
            ->where('phone', $phone)
            ->exists();
        if ($isDnc) {
            return back()->with('error', "El teléfono {$phone} está registrado en la lista de No Molestar (DNC).");
        }

        $table = $leadList->table_name;

        // Check Duplicate in this list
        $exists = \Illuminate\Support\Facades\DB::table($table)->where('phone', $phone)->exists();
        if ($exists) {
            return back()->with('error', "El teléfono {$phone} ya existe en esta lista.");
        }

        // Build custom fields
        $custom = (array) $request->input('custom_fields', []);
        if ($request->filled('first_name')) {
            $custom['first_name'] = trim((string) $request->input('first_name'));
        }
        if ($request->filled('last_name')) {
            $custom['last_name'] = trim((string) $request->input('last_name'));
        }
        if ($request->filled('email')) {
            $custom['email'] = trim((string) $request->input('email'));
        }

        \Illuminate\Support\Facades\DB::table($table)->insert([
            'phone' => $phone,
            'status' => 'NEW',
            'called_count' => 0,
            'custom_fields' => !empty($custom) ? json_encode($custom, JSON_UNESCAPED_UNICODE) : null,
        ]);

        $leadList->increment('total_leads');
        if ($leadList->status === 'draft') {
            $leadList->update(['status' => 'ready']);
        }

        return back()->with('success', "Lead con teléfono {$phone} agregado exitosamente a la lista.");
    }

    public function destroyLead(LeadList $leadList, int $leadId)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_lists'), 403);

        $table = $leadList->table_name;
        $deleted = \Illuminate\Support\Facades\DB::table($table)->where('id', $leadId)->delete();

        if ($deleted) {
            $leadList->decrement('total_leads');
            if ($leadList->total_leads <= 0 && $leadList->status === 'ready') {
                $leadList->update(['status' => 'draft']);
            }
            return back()->with('success', 'Lead eliminado de la lista.');
        }

        return back()->with('error', 'No se encontró el lead a eliminar.');
    }
}
