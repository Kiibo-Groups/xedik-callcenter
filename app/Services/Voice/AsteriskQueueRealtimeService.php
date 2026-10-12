<?php

declare(strict_types=1);

namespace App\Services\Voice;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class AsteriskQueueRealtimeService
{
    private const ALLOWED_COLUMNS = [
        'name',
        'musiconhold',
        'strategy',
        'timeout',
        'retry',
        'maxlen',
        'servicelevel',
        'weight',
        'ringinuse',
        'wrapuptime',
        'autofill',
        'autopause',
    ];

    public function isAvailable(): bool
    {
        try {
            return $this->connection()->getPdo() !== null
                && $this->connection()->getSchemaBuilder()->hasTable($this->queuesTable());
        } catch (Throwable) {
            return false;
        }
    }

    public function find(string $queueName): ?object
    {
        return $this->connection()
            ->table($this->queuesTable())
            ->where('name', $queueName)
            ->first();
    }

    public function exists(string $queueName): bool
    {
        return $this->connection()
            ->table($this->queuesTable())
            ->where('name', $queueName)
            ->exists();
    }

    public function upsert(array $attributes): void
    {
        $payload = $this->sanitizeAttributes($attributes);

        if (blank($payload['name'] ?? null)) {
            throw new InvalidArgumentException('Asterisk queue name is required.');
        }

        $this->connection()
            ->table($this->queuesTable())
            ->updateOrInsert(
                ['name' => $payload['name']],
                $payload
            );
    }

    public function schemaHealth(): array
    {
        try {
            return [
                'connection' => 'asterisk_realtime',
                'table' => $this->queuesTable(),
                'available' => $this->isAvailable(),
                'columns' => $this->connection()->getSchemaBuilder()->getColumnListing($this->queuesTable()),
            ];
        } catch (Throwable $e) {
            return [
                'connection' => 'asterisk_realtime',
                'table' => $this->safeConfiguredTableName(),
                'available' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function sanitizeAttributes(array $attributes): array
    {
        $payload = array_intersect_key($attributes, array_flip(self::ALLOWED_COLUMNS));

        foreach (['ringinuse', 'autofill', 'autopause'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = $payload[$field] ? 'yes' : 'no';
            }
        }

        return $payload;
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection('asterisk_realtime');
    }

    private function queuesTable(): string
    {
        $table = $this->safeConfiguredTableName();

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Invalid Asterisk queues table name configured.');
        }

        return $table;
    }

    private function safeConfiguredTableName(): string
    {
        return (string) config('voice.asterisk_tables.queues', 'queues');
    }
}
