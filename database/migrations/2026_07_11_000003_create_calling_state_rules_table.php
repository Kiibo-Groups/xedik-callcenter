<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calling_state_rules', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2)->default('US');
            $table->string('state_code', 2);
            $table->string('state_name')->nullable();
            $table->string('rule_type')->default('telemarketing_calling_hours');
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->json('days_of_week')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source')->nullable();
            $table->string('source_url')->nullable();
            $table->date('source_updated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['state_code', 'rule_type']);
            $table->index('state_code');
            $table->index('is_active');
            $table->index('rule_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calling_state_rules');
    }
};
