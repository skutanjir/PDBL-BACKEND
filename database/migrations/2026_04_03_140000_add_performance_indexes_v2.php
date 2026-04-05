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
        // device_id is already indexed in a previous migration (2026_03_02_214800)
        
        Schema::table('team_user', function (Blueprint $table) {
            // Composite index for fetching teams by membership/status
            // Critical for high-concurrency member verification and status checks
            $table->index(['user_id', 'status', 'team_id'], 'team_user_search_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            $table->dropIndex('team_user_search_idx');
        });
    }
};
