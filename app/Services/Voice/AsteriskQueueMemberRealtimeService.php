<?php

declare(strict_types=1);

namespace App\Services\Voice;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class AsteriskQueueMemberRealtimeService
{
    private const ALLOWED_COLUMNS = [
        'queue_name',
        'membername',
        'interface',
        'state_interface',
        'penalty',
        'paused',
    ];

    public function isAvailable(): bool
    {
        try {
            return $this->connection()->getPdo() !== null
                && $this->connection()->getSchemaBuilder()->hasTable($this->queueMembersTable());
        } catch (Throwable) {
            return false;
        }
    }

    public function rowsForQueue(string $queueName): Collection
    {
        return $this->connection()
            ->table($this->queueMembersTable())
            ->where('queue_name', $queueName)
            ->orderBy('uniqueid')
            ->get();
    }

    public function rowsForMember(string $queueName, string $interface): Collection
    {
        return $this->connection()
            ->table($this->queueMembersTable())
            ->where('queue_name', $queueName)
            ->where('interface', $interface)
            ->orderBy('uniqueid')
            ->get();
    }

    public function updatePaused(string $queueName, string $interface, bool $paused): int
    {
        $target = $paused ? 1 : 0;

        return $this->connection()
            ->table($this->queueMembersTable())
            ->where('queue_name', $queueName)
            ->where('interface', $interface)
            ->where(function ($query) use ($target): void {
                $query->whereNull('paused')
                    ->orWhere('paused', '<>', $target);
            })
            ->update(['paused' => $target]);
    }

    public function updatePausedForInterfaces(array $queueNames, string $interface, bool $paused): int
    {
        $queueNames = array_values(array_filter(array_unique($queueNames), fn ($value) => filled($value)));

        if ($queueNames === []) {
            return 0;
        }

        $target = $paused ? 1 : 0;

        return $this->connection()
            ->table($this->queueMembersTable())
            ->whereIn('queue_name', $queueNames)
            ->where('interface', $interface)
            ->where(function ($query) use ($target): void {
                $query->whereNull('paused')
                    ->orWhere('paused', '<>', $target);
            })
            ->update(['paused' => $target]);
    }

    public function countRowsForInterfaces(array $queueNames, string $interface): int
    {
        $queueNames = array_values(array_filter(array_unique($queueNames), fn ($value) => filled($value)));

        if ($queueNames === []) {
            return 0;
        }

        return $this->connection()
            ->table($this->queueMembersTable())
            ->whereIn('queue_name', $queueNames)
            ->where('interface', $interface)
            ->count();
    }

    public function upsertConfiguration(array $attributes): array
    {
        $payload = $this->sanitizeAttributes($attributes);

        if (blank($payload['queue_name'] ?? null) || blank($payload['interface'] ?? null)) {
            throw new InvalidArgumentException('Queue member queue_name and interface are required.');
        }

        $rows = $this->rowsForMember($payload['queue_name'], $payload['interface']);

        if ($rows->isEmpty()) {
            $this->connection()
                ->table($this->queueMembersTable())
                ->insert(array_merge($payload, ['paused' => 1]));

            return ['created' => 1, 'updated' => 0, 'duplicates_removed' => 0];
        }

        $canonical = $rows->first();
        $updates = collect($payload)
            ->except(['queue_name', 'interface', 'paused'])
            ->all();

        $this->connection()
            ->table($this->queueMembersTable())
            ->where('uniqueid', $canonical->uniqueid)
            ->update($updates);

        $duplicatesRemoved = $this->deleteDuplicateRows($rows->slice(1));

        return ['created' => 0, 'updated' => 1, 'duplicates_removed' => $duplicatesRemoved];
    }

    public function remove(string $queueName, string $interface): int
    {
        return $this->connection()
            ->table($this->queueMembersTable())
            ->where('queue_name', $queueName)
            ->where('interface', $interface)
            ->delete();
    }

    public function removeById(int $uniqueId): int
    {
        return $this->connection()
            ->table($this->queueMembersTable())
            ->where('uniqueid', $uniqueId)
            ->delete();
    }

    public function removeQueue(string $queueName): int
    {
        return $this->connection()
            ->table($this->queueMembersTable())
            ->where('queue_name', $queueName)
            ->delete();
    }

    public function deduplicate(string $queueName, string $interface): int
    {
        return $this->deleteDuplicateRows($this->rowsForMember($queueName, $interface)->slice(1));
    }

    private function deleteDuplicateRows(Collection $rows): int
    {
        $ids = $rows->pluck('uniqueid')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        return $this->connection()
            ->table($this->queueMembersTable())
            ->whereIn('uniqueid', $ids)
            ->delete();
    }

    private function sanitizeAttributes(array $attributes): array
    {
        $payload = array_intersect_key($attributes, array_flip(self::ALLOWED_COLUMNS));

        if (array_key_exists('penalty', $payload)) {
            $payload['penalty'] = (int) $payload['penalty'];
        }

        if (array_key_exists('paused', $payload)) {
            $payload['paused'] = (int) (bool) $payload['paused'];
        }

        return $payload;
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection('asterisk_realtime');
    }

    private function queueMembersTable(): string
    {
        $table = (string) config('voice.asterisk_tables.queue_members', 'queue_members');

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Invalid Asterisk queue_members table name configured.');
        }

        return $table;
    }
}
