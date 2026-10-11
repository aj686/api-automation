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
        // One row per executed request, with its payloads (decisions.md D-037).
        // Everything is redacted before it is written; headers and bodies are
        // nulled after automation.payload_retention_days (payload_purged_at).
        Schema::create('run_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('item_name');
            $table->string('method', 10)->nullable();
            $table->text('url')->nullable();

            $table->json('request_headers')->nullable();
            $table->mediumText('request_body')->nullable();
            $table->boolean('request_body_truncated')->default(false);

            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('response_status', 100)->nullable();
            $table->json('response_headers')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->boolean('response_body_truncated')->default(false);
            $table->boolean('response_body_binary')->default(false);
            $table->string('response_content_type')->nullable();
            $table->unsignedInteger('response_size')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();

            $table->text('error_message')->nullable();
            $table->timestamp('payload_purged_at')->nullable();

            $table->index(['run_id', 'position']);
        });

        // Each assertion row belongs to the request it checked. NULL for runs
        // stored before payloads existed.
        Schema::table('run_results', function (Blueprint $table) {
            $table->foreignId('run_request_id')->nullable()->after('run_id')->constrained('run_requests')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('run_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('run_request_id');
        });
        Schema::dropIfExists('run_requests');
    }
};
