<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('reply_to_id')->nullable()->after('sender_id')->constrained('chat_messages')->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('mentions_all');
            $table->timestamp('deleted_at')->nullable()->after('edited_at');
            $table->foreignId('deleted_by_id')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            $table->string('delete_reason')->nullable()->after('deleted_by_id');
            $table->index(['chat_conversation_id', 'deleted_at']);
        });

        Schema::create('chat_message_user_deletions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['chat_message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_user_deletions');

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_id');
            $table->dropConstrainedForeignId('deleted_by_id');
            $table->dropColumn(['edited_at', 'deleted_at', 'delete_reason']);
        });
    }
};
