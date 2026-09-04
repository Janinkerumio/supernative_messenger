<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->after('name');
            $table->string('tagline')->nullable()->after('username');
            $table->string('accent', 9)->default('#0A7CFF')->after('tagline');
            $table->boolean('is_online')->default(false)->after('accent');
            $table->timestamp('last_seen_at')->nullable()->after('is_online');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'tagline', 'accent', 'is_online', 'last_seen_at']);
        });
    }
};
