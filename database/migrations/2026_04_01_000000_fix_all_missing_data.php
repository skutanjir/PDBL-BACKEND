<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * This migration fixes data that may have been manually migrated but is not showing up
     * in the app due to status or ownership mismatch.
     */
    public function up(): void
    {
        // 1. Set all existing team memberships to 'accepted'
        // This ensures manually migrated memberships appear in the list.
        if (Schema::hasTable('team_user')) {
            DB::table('team_user')->update(['status' => 'accepted']);
        }

        // 2. Claim orphaned tasks that have a team_id but no user_id (NULL)
        // We assign them to the creator of the team.
        if (Schema::hasTable('todos') && Schema::hasTable('teams')) {
            DB::statement("
                UPDATE todos 
                SET user_id = (SELECT created_by FROM teams WHERE teams.id = todos.team_id) 
                WHERE user_id IS NULL AND team_id IS NOT NULL AND EXISTS (SELECT 1 FROM teams WHERE teams.id = todos.team_id)
            ");
        }

        // 3. Ensure all teams have a valid description if missing
        if (Schema::hasTable('teams') && Schema::hasColumn('teams', 'description')) {
            DB::table('teams')->whereNull('description')->update(['description' => 'Migrated team.']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse action needed for data recovery script.
    }
};
