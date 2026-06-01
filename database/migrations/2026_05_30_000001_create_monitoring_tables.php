<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'status')) {
                $table->string('status', 20)->default('active')->index();
            }
            if (!Schema::hasColumn('users', 'status_reason')) {
                $table->text('status_reason')->nullable();
            }
            if (!Schema::hasColumn('users', 'status_changed_at')) {
                $table->timestamp('status_changed_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->index();
            }
            if (!Schema::hasColumn('users', 'banned_at')) {
                $table->timestamp('banned_at')->nullable();
            }
        });

        if (!Schema::hasTable('user_sessions')) {
            Schema::create('user_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('device_id')->nullable()->index();
                $table->string('session_key')->unique();
                $table->string('platform', 30)->nullable();
                $table->string('app_version', 40)->nullable();
                $table->string('timezone', 80)->nullable();
                $table->string('locale', 20)->nullable();
                $table->string('network_type', 40)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('ended_at')->nullable();
                $table->unsignedInteger('duration_seconds')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('monitoring_events')) {
            Schema::create('monitoring_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('device_id')->nullable()->index();
                $table->string('session_key')->nullable()->index();
                $table->string('event_type', 80)->index();
                $table->string('category', 40)->default('mobile')->index();
                $table->string('source', 40)->default('mobile');
                $table->unsignedInteger('duration_ms')->nullable();
                $table->string('screen', 80)->nullable()->index();
                $table->string('feature', 80)->nullable()->index();
                $table->string('network_type', 40)->nullable();
                $table->boolean('offline')->default(false);
                $table->unsignedInteger('payload_size')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->nullable()->index();
                $table->timestamps();
                $table->index(['event_type', 'occurred_at']);
                $table->index(['user_id', 'occurred_at']);
            });
        }

        if (!Schema::hasTable('api_activity_logs')) {
            Schema::create('api_activity_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('device_id')->nullable()->index();
                $table->string('method', 10);
                $table->string('path')->index();
                $table->string('route_name')->nullable();
                $table->unsignedSmallInteger('status_code')->nullable()->index();
                $table->unsignedInteger('duration_ms')->default(0)->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('action', 120)->nullable()->index();
                $table->boolean('blocked')->default(false)->index();
                $table->boolean('rate_limited')->default(false)->index();
                $table->boolean('suspicious')->default(false)->index();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->nullable()->index();
                $table->timestamps();
                $table->index(['path', 'occurred_at']);
                $table->index(['user_id', 'occurred_at']);
            });
        }

        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 120)->index();
                $table->string('entity_type', 80)->nullable();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('user_device_histories')) {
            Schema::create('user_device_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('device_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable()->index();
                $table->text('user_agent')->nullable();
                $table->string('platform', 30)->nullable();
                $table->string('app_version', 40)->nullable();
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->unsignedInteger('seen_count')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'device_id', 'ip_address'], 'user_device_ip_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_device_histories');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('api_activity_logs');
        Schema::dropIfExists('monitoring_events');
        Schema::dropIfExists('user_sessions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status', 'status_reason', 'status_changed_at', 'last_seen_at', 'banned_at']);
        });
    }
};
