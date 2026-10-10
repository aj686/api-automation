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
        Schema::create('run_results', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('item_name');
            $table->string('method', 10)->nullable();
            $table->text('url')->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->text('assertion')->nullable();
            $table->boolean('passed');
            $table->text('error_message')->nullable();

            $table->index(['run_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('run_results');
    }
};
