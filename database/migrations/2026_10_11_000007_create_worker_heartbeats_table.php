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
        // The UI warns "Runner not responding" when beat_at goes stale (plan loophole 25).
        Schema::create('worker_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('worker', 50)->unique();
            $table->timestamp('beat_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_heartbeats');
    }
};
