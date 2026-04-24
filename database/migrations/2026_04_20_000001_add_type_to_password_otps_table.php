<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_otps', function (Blueprint $table) {
            $table->string('type')->default('password_reset')->after('otp');
            // type: 'password_reset' | 'email_verification'
        });
    }

    public function down(): void
    {
        Schema::table('password_otps', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
