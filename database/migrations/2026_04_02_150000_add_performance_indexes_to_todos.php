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
        Schema::table('todos', function (Blueprint $table) {
            // is_completed is not yet indexed
            $table->index('is_completed');
            
            // Speed up progress calculation (Team/Member stats)
            // This is a composite index for where('team_id', x)->where('is_completed', y)
            $table->index(['team_id', 'is_completed']);

            // user_id and team_id are foreign keys, usually indexed by database engines,
            // but adding an index explicitly doesn't hurt IF it doesn't already exist.
            // Since device_id failed, we skip it and focus on what's missing.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropIndex(['is_completed']);
            $table->dropIndex(['team_id', 'is_completed']);
        });
    }
};
