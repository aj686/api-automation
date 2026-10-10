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
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('slug', 150);
            // App\Enums\CollectionKind — metadata only, one execution engine for all.
            $table->string('kind', 20)->default('other');
            // Relative to storage/app/private, never a user-supplied absolute path.
            $table->string('stored_path');
            $table->string('original_filename');
            $table->char('sha256', 64);
            $table->string('schema_version', 20)->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collections');
    }
};
