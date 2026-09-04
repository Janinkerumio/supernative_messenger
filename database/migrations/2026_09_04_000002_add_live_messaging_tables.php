<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Privacy feature flags (WhatsApp-style: OFF means you neither
            // broadcast nor receive that signal).
            $table->boolean('active_status_visible')->default(true)->after('is_online');
            $table->boolean('read_receipts_enabled')->default(true)->after('active_status_visible');
            // 'system' | 'light' | 'dark'
            $table->string('theme_preference', 12)->default('system')->after('read_receipts_enabled');
        });

        Schema::table('messages', function (Blueprint $table) {
            // Idempotency key from the sending device — a retried POST must not
            // create a duplicate.
            $table->uuid('client_uuid')->nullable()->unique()->after('id');
            $table->timestamp('delivered_at')->nullable()->after('body');
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('platform', 16)->default('unknown'); // ios | android | unknown
            $table->string('push_token')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique('push_token');
            $table->index(['user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn(['client_uuid', 'delivered_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['active_status_visible', 'read_receipts_enabled', 'theme_preference']);
        });
    }
};
