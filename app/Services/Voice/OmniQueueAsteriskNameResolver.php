<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\OmniQueue;

class OmniQueueAsteriskNameResolver
{
    public function defaultName(OmniQueue $queue): string
    {
        return $this->defaultNameFor((int) $queue->tenant_id, $queue->name ?: 'queue');
    }

    public function defaultNameFor(int $tenantId, string $queueName): string
    {
        $base = $this->normalize($queueName ?: 'queue');

        return $this->fit("t{$tenantId}_{$base}");
    }

    public function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[\s\-]+/', '_', $value) ?? '';
        $value = preg_replace('/[^a-z0-9_]/', '', $value) ?? '';
        $value = preg_replace('/_+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return $value !== '' ? $value : 'queue';
    }

    private function fit(string $value): string
    {
        return substr($value, 0, 128);
    }
}
