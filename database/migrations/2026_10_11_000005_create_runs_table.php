<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            // ULID: exposed in URLs and the n8n API, so ids are not guessable or countable.
            $table->ulid('id')->primary();

            // Nullable + SET NULL, with name snapshots below, so deleting a
            // project keeps its history readable (plan section 4).
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('collection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_name', 100);
            $table->string('environment_name', 100);
            $table->string('environment_type', 20);
            $table->string('collection_name', 150);

            $table->string('status', 12);
            $table->string('trigger', 8);
            $table->timestamp('cancel_requested_at')->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->smallInteger('exit_code')->nullable();
            $table->string('cli_version', 20)->nullable();
            $table->unsignedInteger('timeout_seconds');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // NULL means "unknown", never zero (plan section 4).
            $table->unsignedInteger('total_requests')->nullable();
            $table->unsignedInteger('total_assertions')->nullable();
            $table->unsignedInteger('passed_assertions')->nullable();
            $table->unsignedInteger('failed_assertions')->nullable();

            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            // Redacted and size-capped before it is written.
            $table->mediumText('log')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
