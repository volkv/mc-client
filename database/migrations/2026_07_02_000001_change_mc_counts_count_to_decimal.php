<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Fractional counters (cost in USD etc.); the hub business ingest
        // accepts fractional counts since 2026-07.
        Schema::table('mc_counts', function (Blueprint $table) {
            $table->decimal('count', 14, 6)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('mc_counts', function (Blueprint $table) {
            $table->integer('count')->default(0)->change();
        });
    }
};
