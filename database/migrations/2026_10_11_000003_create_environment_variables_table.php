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
        Schema::create('environment_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            // Always ciphertext (encrypted cast), secret or not — see decisions.md D-012.
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['environment_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('environment_variables');
    }
};
