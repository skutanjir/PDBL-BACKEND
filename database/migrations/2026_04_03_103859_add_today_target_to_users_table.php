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
        Schema::table('users', function (Blueprint $BluePrint) {
            if (!Schema::hasColumn('users', 'today_target')) {
                $BluePrint->integer('today_target')->default(0)->after('email');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $BluePrint) {
            $BluePrint->dropColumn('today_target');
        });
    }
};
