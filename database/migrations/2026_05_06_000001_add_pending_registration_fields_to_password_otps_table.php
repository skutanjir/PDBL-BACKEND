<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_otps', function (Blueprint $table) {
            $table->string('pending_name')->nullable()->after('type');
            $table->text('pending_password')->nullable()->after('pending_name');
        });
    }

    public function down(): void
    {
        Schema::table('password_otps', function (Blueprint $table) {
            $table->dropColumn(['pending_name', 'pending_password']);
        });
    }
};
