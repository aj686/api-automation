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
        // Outbound capture (plan section 13): a URL the system under test calls.
        Schema::create('capture_buckets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            // SHA-256 of the URL token; the token itself is shown once (loophole 38).
            $table->char('token_hash', 64);
            $table->unsignedSmallInteger('response_status')->default(200);
            // Off until switched on in the UI (loophole 38).
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('expires_after_hours')->default(24);
            $table->timestamps();

            $table->unique(['project_id', 'slug']);
        });

        Schema::create('captured_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capture_bucket_id')->constrained()->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('path', 2000);
            $table->text('query')->nullable();
            // Redacted; credential headers stored as present only (loophole 39).
            $table->json('headers');
            // Redacted and size-capped.
            $table->mediumText('body')->nullable();
            $table->boolean('body_truncated')->default(false);
            $table->timestamp('received_at', 3);

            $table->index(['capture_bucket_id', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('captured_requests');
        Schema::dropIfExists('capture_buckets');
    }
};
