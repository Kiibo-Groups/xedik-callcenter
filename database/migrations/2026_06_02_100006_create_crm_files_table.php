<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('related_type', 100)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('channel', 30)->nullable();
            $table->string('direction', 10)->nullable();
            $table->string('source', 40);
            $table->string('category', 30);
            $table->string('storage_provider', 50);
            $table->string('storage_ownership_type', 20)->nullable();
            $table->foreignId('managed_storage_profile_id')->nullable()->constrained('managed_storage_profiles')->nullOnDelete();
            $table->string('bucket', 255);
            $table->string('object_key', 1000);
            $table->string('original_filename', 500);
            $table->string('stored_filename', 255);
            $table->string('extension', 20)->nullable();
            $table->string('mime_type', 200)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('visibility', 10)->default('private');
            $table->string('status', 30)->default('active');
            $table->unsignedInteger('retention_days')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('migrated_from_path', 1000)->nullable();
            $table->timestamp('migrated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('conversation_id');
            $table->index('message_id');
            $table->index(['related_type', 'related_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'channel', 'category']);
            $table->index('uploaded_by');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_files');
    }
};
