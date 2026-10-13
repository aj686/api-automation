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
        // Run sequence and stop-on-error for one run (decisions.md D-038).
        // NULL = the whole collection in file order, as before.
        Schema::table('runs', function (Blueprint $table) {
            $table->json('options')->nullable()->after('trigger');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
