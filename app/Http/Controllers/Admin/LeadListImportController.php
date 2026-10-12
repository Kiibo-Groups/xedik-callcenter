<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeadList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeadListImportController extends Controller
{
    public function import(Request $request, LeadList $leadList): JsonResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_lists'), 403);

        $request->validate([
            'csv' => 'required|file|mimes:csv,txt|max:51200',
        ]);

        if ($leadList->status === 'archived') {
            return response()->json(['error' => 'No se puede importar a una lista archivada.'], 422);
        }

        $tableName = $leadList->table_name;
        $tenantId  = (int) $leadList->tenant_id;
        $path      = $request->file('csv')->getRealPath();

        DB::disableQueryLog();

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return response()->json(['error' => 'No se pudo abrir el archivo CSV.'], 422);
        }

        // Strip UTF-8 BOM (Excel "CSV UTF-8" añade EF BB BF al inicio)
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $headers = array_map('trim', fgetcsv($handle) ?: []);

        $phoneCol = $this->findColumn($headers, ['phone', 'telefono', 'numero', 'number', 'tel']);

        if ($phoneCol === null) {
            fclose($handle);
            return response()->json(['error' => 'No se encontró columna de teléfono (phone, telefono, numero).'], 422);
        }

        $chunk        = [];
        $chunkSize    = 1000;
        $imported     = 0;
        $skipDnc      = 0;
        $skipDupe     = 0;
        $skipInvalid  = 0;
        $invalidSamples = [];

        // Cargar DNC del tenant en memoria para filtro batch eficiente
        $dncSet = DB::table('dialer_dnc')
            ->where('tenant_id', $tenantId)
            ->pluck('phone')
            ->flip()
            ->toArray();

        while (($row = fgetcsv($handle)) !== false) {
            // Fila vacía (ej. línea en blanco al final del CSV de Excel)
            if (count(array_filter($row, fn($v) => $v !== null && $v !== '')) === 0) continue;

            if (empty($row[$phoneCol])) {
                Log::info("LeadList #{$leadList->id} SKIP empty_phone row: " . json_encode($row));
                if (count($invalidSamples) < 10) $invalidSamples[] = ['reason' => 'empty_phone', 'raw' => $row[$phoneCol] ?? ''];
                $skipInvalid++; continue;
            }

            $phone = preg_replace('/\D/', '', $row[$phoneCol]);
            if (strlen($phone) < 7) {
                Log::info("LeadList #{$leadList->id} SKIP short_phone raw=[{$row[$phoneCol]}] cleaned=[{$phone}]");
                if (count($invalidSamples) < 10) $invalidSamples[] = ['reason' => 'too_short', 'raw' => $row[$phoneCol], 'cleaned' => $phone];
                $skipInvalid++; continue;
            }

            // Filtro DNC
            if (isset($dncSet[$phone])) { $skipDnc++; continue; }

            $record = [
                'phone'  => $phone,
                'status' => 'NEW',
            ];

            // Todas las columnas excepto phone → custom_fields JSON
            // Se incluyen aunque vengan vacías para preservar la estructura del CSV
            // y permitir edición posterior desde el modal de detalle.
            $extra = [];
            foreach ($headers as $i => $h) {
                if ($i === $phoneCol) continue;
                $extra[$h] = trim($row[$i] ?? '');
            }
            $record['custom_fields'] = !empty($extra) ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null;

            $chunk[] = $record;

            if (count($chunk) >= $chunkSize) {
                [$inserted, $dupes] = $this->insertSkipDupes($tableName, $chunk);
                $imported   += $inserted;
                $skipDupe   += $dupes;
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            [$inserted, $dupes] = $this->insertSkipDupes($tableName, $chunk);
            $imported  += $inserted;
            $skipDupe  += $dupes;
        }

        fclose($handle);

        $leadList->update([
            'total_leads' => DB::table($tableName)->count(),
            'status'      => 'ready',
        ]);

        $skipped = $skipDnc + $skipDupe + $skipInvalid;
        Log::info("LeadList #{$leadList->id} import: {$imported} inserted, {$skipped} skipped (dnc={$skipDnc}, dupes={$skipDupe}, invalid={$skipInvalid}).");

        return response()->json([
            'ok'             => true,
            'imported'       => $imported,
            'skipped'        => $skipped,
            'skip_detail'    => [
                'duplicates' => $skipDupe,
                'dnc'        => $skipDnc,
                'invalid'    => $skipInvalid,
            ],
            'invalid_samples' => $invalidSamples,
        ]);
    }

    /**
     * Inserta solo los registros cuyo phone NO existe ya en la tabla.
     * Retorna [insertados, omitidos_por_duplicado].
     */
    private function insertSkipDupes(string $tableName, array $chunk): array
    {
        $phones = array_column($chunk, 'phone');

        $existing = DB::table($tableName)
            ->whereIn('phone', $phones)
            ->pluck('phone')
            ->flip()
            ->toArray();

        $toInsert = array_values(array_filter($chunk, fn($r) => !isset($existing[$r['phone']])));
        $dupes    = count($chunk) - count($toInsert);

        if (!empty($toInsert)) {
            DB::table($tableName)->insert($toInsert);
        }

        return [count($toInsert), $dupes];
    }

    private function findColumn(array $headers, array $candidates): ?int
    {
        foreach ($headers as $i => $h) {
            if (in_array(strtolower(trim($h)), $candidates, true)) return $i;
        }
        return null;
    }
}
