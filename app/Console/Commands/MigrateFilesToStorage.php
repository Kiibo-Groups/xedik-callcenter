<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\FileMigrationService;
use App\Services\Storage\TenantStorageUsageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateFilesToStorage extends Command
{
    protected $signature = 'crm:files:migrate-to-storage
                            {--tenant=       : ID del tenant}
                            {--path=         : Ruta relativa dentro del disco local (ej: messages/inbound)}
                            {--source=migration : Valor del campo source en crm_files}
                            {--channel=email  : Canal (email, whatsapp, manual, system)}
                            {--category=      : Categoría (images, audio, documents, attachments, inline_images, raw, other)}
                            {--conversation=  : Filtrar por conversation_id}
                            {--message=       : Filtrar por message_id específico}
                            {--dry-run        : Solo reportar, no subir ni crear registros}
                            {--ignore-limit   : Ignorar límite de storage del tenant}
                            {--delete-after-verify : Eliminar archivo local después de verificar migración exitosa}';

    protected $description = 'Migra archivos locales al storage configurado del tenant (Cloudflare R2 por defecto)';

    public function __construct(
        private readonly FileMigrationService      $migrationService,
        private readonly TenantStorageUsageService $usageService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId    = (int) $this->option('tenant');
        $basePath    = $this->option('path') ?: 'messages';
        $source      = $this->option('source') ?: 'migration';
        $channel     = $this->option('channel') ?: 'email';
        $category    = $this->option('category') ?: null;
        $dryRun      = (bool) $this->option('dry-run');
        $ignoreLimit = (bool) $this->option('ignore-limit');
        $deleteAfter = (bool) $this->option('delete-after-verify');
        $convId      = $this->option('conversation') ? (int) $this->option('conversation') : null;
        $msgId       = $this->option('message') ? (int) $this->option('message') : null;

        if (!$tenantId) {
            $this->error('Se requiere --tenant=ID');
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Modo DRY-RUN activado. No se subirá ni creará ningún registro.');
        }

        $this->info("Escaneando: storage/app/private/{$basePath}");
        $files = Storage::disk('local')->allFiles($basePath);

        if (empty($files)) {
            $this->warn('No se encontraron archivos en la ruta especificada.');
            return self::SUCCESS;
        }

        $this->info(count($files) . ' archivos encontrados.');

        $stats = ['scanned' => 0, 'migrated' => 0, 'skipped' => 0, 'failed' => 0, 'bytes' => 0, 'dry_run' => 0];

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $file) {
            $stats['scanned']++;

            $metadata = [
                'channel'         => $channel,
                'category'        => $category ?? $this->inferCategory($file),
                'source'          => $source,
                'conversation_id' => $convId,
                'message_id'      => $msgId,
            ];

            $result = $this->migrationService->migrateLocalFile(
                $tenantId, $file, $metadata, $dryRun, $ignoreLimit
            );

            match ($result['status']) {
                'migrated'  => $stats['migrated']++,
                'dry_run'   => $stats['dry_run']++,
                'skipped'   => $stats['skipped']++,
                'failed'    => $stats['failed']++,
                default     => null,
            };

            $stats['bytes'] += $result['size_bytes'] ?? 0;

            if ($result['status'] === 'failed') {
                $this->newLine();
                $this->error("FAILED: {$file} — {$result['reason']}");
            }

            if ($deleteAfter && $result['status'] === 'migrated') {
                Storage::disk('local')->delete($file);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Archivos escaneados',  $stats['scanned']],
                [$dryRun ? 'Dry-run (sin subir)' : 'Migrados', $dryRun ? $stats['dry_run'] : $stats['migrated']],
                ['Omitidos',             $stats['skipped']],
                ['Fallidos',             $stats['failed']],
                ['Total bytes procesados', number_format($stats['bytes'])],
            ]
        );

        if (!$dryRun) {
            $usage = $this->usageService->getUsageSummary($tenantId);
            $this->info("Uso actual del tenant: {$usage['used_gb']} GB / {$usage['allowed_gb']} GB ({$usage['usage_percent']}%)");
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function inferCategory(string $path): string
    {
        if (str_contains($path, '/inline/') || str_contains($path, '/inline-images/')) {
            return 'inline_images';
        }
        if (str_contains($path, '/eml/') || str_ends_with($path, '.eml')) {
            return 'raw';
        }
        if (str_contains($path, '/attachments/')) {
            return 'attachments';
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match(true) {
            in_array($ext, ['jpg','jpeg','png','gif','webp','svg'], true) => 'images',
            in_array($ext, ['mp3','ogg','wav','aac','opus'], true)        => 'audio',
            in_array($ext, ['mp4','mov','avi','webm'], true)              => 'video',
            in_array($ext, ['pdf','doc','docx','xls','xlsx','csv','txt'], true) => 'documents',
            default => 'other',
        };
    }
}
