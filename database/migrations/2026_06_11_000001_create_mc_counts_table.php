<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Same shape as loggio's table on purpose: cheap local increments,
        // shipped to Mission Control once a day by the PushCounts job.
        Schema::create('mc_counts', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('event_name');
            $table->integer('count')->default(0);

            $table->index(['date']);
            $table->unique(['date', 'event_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mc_counts');
    }
};
