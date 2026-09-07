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
            // Server-assigned idempotency key; the mirror stores it so pulled
            // rows dedupe against anything the client created optimistically.
            $table->uuid('client_uuid')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn('client_uuid');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['active_status_visible', 'read_receipts_enabled', 'theme_preference']);
        });
    }
};
