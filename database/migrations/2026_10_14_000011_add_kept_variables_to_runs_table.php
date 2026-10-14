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
        // Names (never values) of the environment variables a run saved back
        // with "Keep variable values" (decisions.md D-039).
        Schema::table('runs', function (Blueprint $table) {
            $table->json('kept_variables')->nullable()->after('options');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('kept_variables');
        });
    }
};
