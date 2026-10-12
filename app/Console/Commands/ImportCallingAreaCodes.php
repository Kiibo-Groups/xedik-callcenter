<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CallingAreaCode;
use Illuminate\Console\Command;

/**
 * Upserts the calling_area_codes reference table from a CSV.
 *
 * Expected columns: area_code,state,state_code,time_zone,time_zone_abbr,status,is_active,source,notes
 *
 * This is a data-maintenance command, not a one-time seed — it can be re-run
 * safely any time the underlying NANP dataset is refreshed (upsert by area_code).
 */
class ImportCallingAreaCodes extends Command
{
    protected $signature = 'calling-area-codes:import {path : Path to the CSV file}';

    protected $description = 'Import/upsert US NANP area code → IANA timezone reference data';

    private const REQUIRED_COLUMNS = ['area_code', 'time_zone'];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (!is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Could not open file: {$path}");

            return self::FAILURE;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            $this->error('CSV file is empty.');
            fclose($handle);

            return self::FAILURE;
        }

        $header = array_map(fn (string $h) => trim(strtolower($h)), $header);

        foreach (self::REQUIRED_COLUMNS as $required) {
            if (!in_array($required, $header, true)) {
                $this->error("CSV is missing required column: {$required}");
                fclose($handle);

                return self::FAILURE;
            }
        }

        $totalRows = 0;
        $inserted  = 0;
        $updated   = 0;
        $skipped   = 0;
        $invalid   = 0;
        $invalidRows = [];

        while (($row = fgetcsv($handle)) !== false) {
            // Skip fully blank lines (trailing newline in the file, etc.)
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }

            $totalRows++;
            $data = array_combine($header, array_pad($row, count($header), null));

            $areaCode = trim((string) ($data['area_code'] ?? ''));
            $timeZone = trim((string) ($data['time_zone'] ?? ''));

            if (!preg_match('/^\d{3}$/', $areaCode)) {
                $invalid++;
                $invalidRows[] = "row {$totalRows}: invalid area_code '{$areaCode}'";
                continue;
            }

            if (!$this->isValidTimezone($timeZone)) {
                $invalid++;
                $invalidRows[] = "row {$totalRows}: invalid time_zone '{$timeZone}' (area_code {$areaCode})";
                continue;
            }

            $status = trim((string) ($data['status'] ?? 'in_service')) ?: 'in_service';
            $isActive = $this->parseBool($data['is_active'] ?? null, default: true);

            // Planned area codes are import-only — never dial-eligible, regardless
            // of what the CSV's is_active column says.
            if ($status === 'planned') {
                $isActive = false;
            }

            $attributes = [
                'country_code'      => trim((string) ($data['country_code'] ?? 'US')) ?: 'US',
                'state'             => $this->nullableTrim($data['state'] ?? null),
                'state_code'        => $this->nullableTrim($data['state_code'] ?? null),
                'time_zone'         => $timeZone,
                'time_zone_abbr'    => $this->nullableTrim($data['time_zone_abbr'] ?? null),
                'status'            => $status,
                'is_active'         => $isActive,
                'source'            => $this->nullableTrim($data['source'] ?? null),
                'source_updated_at' => $this->nullableTrim($data['source_updated_at'] ?? null),
                'notes'             => $this->nullableTrim($data['notes'] ?? null),
            ];

            $existing = CallingAreaCode::query()->where('area_code', $areaCode)->first();

            if ($existing) {
                $existing->fill($attributes);
                if ($existing->isDirty()) {
                    $existing->save();
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                CallingAreaCode::create(['area_code' => $areaCode] + $attributes);
                $inserted++;
            }
        }

        fclose($handle);

        $this->line('');
        $this->line("Total rows processed: {$totalRows}");
        $this->info("Inserted: {$inserted}");
        $this->info("Updated: {$updated}");
        $this->line("Skipped (no change): {$skipped}");
        $this->warn("Invalid: {$invalid}");

        if ($invalidRows !== []) {
            $this->line('');
            $this->line('Invalid row details:');
            foreach ($invalidRows as $detail) {
                $this->line("  - {$detail}");
            }
        }

        return self::SUCCESS;
    }

    private function isValidTimezone(string $timeZone): bool
    {
        if ($timeZone === '') {
            return false;
        }

        try {
            new \DateTimeZone($timeZone);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function parseBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'y'], true);
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
