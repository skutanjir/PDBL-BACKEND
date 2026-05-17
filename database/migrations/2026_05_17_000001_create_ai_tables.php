<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('WUDI AI Assistant');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('ai_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('summary');
            $table->json('signals')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['ai_conversation_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('ai_context_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('cache_key');
            $table->json('payload');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'cache_key']);
            $table->index('expires_at');
        });

        Schema::create('ai_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->string('request_id')->unique();
            $table->string('status')->default('pending');
            $table->unsignedInteger('prompt_tokens_estimate')->default(0);
            $table->unsignedInteger('response_tokens_estimate')->default(0);
            $table->string('gemini_key_hash')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_request_logs');
        Schema::dropIfExists('ai_context_cache');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_memories');
        Schema::dropIfExists('ai_conversations');
    }
};
