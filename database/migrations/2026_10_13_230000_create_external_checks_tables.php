<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_check_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_history_id')->nullable()->constrained('check_history')->nullOnDelete();
            $table->string('content_hash', 64);
            $table->string('algorithm_version');
            $table->string('status')->default('pending');
            $table->json('input_analysis');
            $table->unsignedInteger('total_entries');
            $table->unsignedInteger('finished_entries')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'content_hash', 'status']);
        });
        Schema::create('external_check_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('run_id')->constrained('external_check_runs')->cascadeOnDelete();
            $table->string('entry_key', 255);
            $table->unsignedInteger('position');
            $table->json('input');
            $table->string('status')->default('queued');
            $table->json('result')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('request_count')->default(0);
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'entry_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_check_entries');
        Schema::dropIfExists('external_check_runs');
    }
};
