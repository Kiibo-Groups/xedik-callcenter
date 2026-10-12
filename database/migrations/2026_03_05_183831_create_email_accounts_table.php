<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->enum('imap_security', ['SSL', 'TLS', 'NONE'])->default('SSL');
            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port')->default(587);
            $table->enum('smtp_security', ['TLS', 'SSL', 'NONE'])->default('TLS');
            $table->boolean('require_auth')->default(true);
            $table->foreignId('omni_queue_id')
                  ->nullable()
                  ->constrained('omni_queues')
                  ->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};