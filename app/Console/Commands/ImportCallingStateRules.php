<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CallingStateRule;
use Illuminate\Console\Command;

/**
 * Upserts state-specific calling-hour rules from a CSV. The business/legal
 * team owns the CSV contents — no state legal rules are hardcoded here.
 *
 * Expected columns: state_code,state_name,start_time,end_time,days_of_week,is_active,source,source_url,source_updated_at,notes
 *
 * rule_type is not a CSV column — every imported row uses the default
 * 'telemarketing_calling_hours' (Phase 2 only supports this one rule type).
 */
class ImportCallingStateRules extends Command
{
    protected $signature = 'calling-state-rules:import {path : Path to the CSV file}';

    protected $description = 'Import/upsert US state-specific calling-hour rules (state_code + rule_type)';

    private const RULE_TYPE = 'telemarketing_calling_hours';
    private const REQUIRED_COLUMNS = ['state_code', 'start_time', 'end_time'];
    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';
    private const VALID_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

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
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }

            $totalRows++;
            $data = array_combine($header, array_pad($row, count($header), null));

            $stateCode = strtoupper(trim((string) ($data['state_code'] ?? '')));
            $startTime = trim((string) ($data['start_time'] ?? ''));
            $endTime   = trim((string) ($data['end_time'] ?? ''));

            if (!preg_match('/^[A-Z]{2}$/', $stateCode)) {
                $invalid++;
                $invalidRows[] = "row {$totalRows}: invalid state_code '{$stateCode}'";
                continue;
            }

            if (!preg_match(self::TIME_PATTERN, $startTime) || !preg_match(self::TIME_PATTERN, $endTime)) {
                $invalid++;
                $invalidRows[] = "row {$totalRows}: invalid start_time/end_time format (expected HH:mm) — got '{$startTime}'/'{$endTime}' (state_code {$stateCode})";
                continue;
            }

            // Phase 2 only supports same-day windows — reject overnight (end <= start).
            if ($endTime <= $startTime) {
                $invalid++;
                $invalidRows[] = "row {$totalRows}: overnight window not supported in Phase 2 (end_time {$endTime} <= start_time {$startTime}, state_code {$stateCode})";
                continue;
            }

            $daysOfWeekRaw = trim((string) ($data['days_of_week'] ?? ''));
            $daysOfWeek = null;
            if ($daysOfWeekRaw !== '') {
                $decoded = json_decode($daysOfWeekRaw, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    $invalid++;
                    $invalidRows[] = "row {$totalRows}: invalid days_of_week JSON '{$daysOfWeekRaw}' (state_code {$stateCode})";
                    continue;
                }

                $normalizedDays = array_map(fn ($d) => strtolower(trim((string) $d)), $decoded);
                $unknownDays = array_diff($normalizedDays, self::VALID_DAYS);
                if ($unknownDays !== []) {
                    $invalid++;
                    $invalidRows[] = "row {$totalRows}: invalid days_of_week value(s) '" . implode(',', $unknownDays) . "' — expected mon,tue,wed,thu,fri,sat,sun (state_code {$stateCode})";
                    continue;
                }

                $daysOfWeek = $normalizedDays === [] ? null : array_values(array_unique($normalizedDays));
            }

            $sourceUpdatedAt = $this->nullableTrim($data['source_updated_at'] ?? null);
            if ($sourceUpdatedAt !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sourceUpdatedAt)) {
                $invalid++;
                $invalidRows[] = "row {$totalRows}: invalid source_updated_at date '{$sourceUpdatedAt}' (expected YYYY-MM-DD, state_code {$stateCode})";
                continue;
            }

            $attributes = [
                'country_code'       => 'US',
                'state_name'         => $this->nullableTrim($data['state_name'] ?? null),
                'start_time'         => $startTime,
                'end_time'           => $endTime,
                'days_of_week'       => $daysOfWeek,
                'is_active'          => $this->parseBool($data['is_active'] ?? null, default: true),
                'source'             => $this->nullableTrim($data['source'] ?? null),
                'source_url'         => $this->nullableTrim($data['source_url'] ?? null),
                'source_updated_at'  => $sourceUpdatedAt,
                'notes'              => $this->nullableTrim($data['notes'] ?? null),
            ];

            $existing = CallingStateRule::query()
                ->where('state_code', $stateCode)
                ->where('rule_type', self::RULE_TYPE)
                ->first();

            if ($existing) {
                $existing->fill($attributes);
                if ($existing->isDirty()) {
                    $existing->save();
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                CallingStateRule::create(['state_code' => $stateCode, 'rule_type' => self::RULE_TYPE] + $attributes);
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
