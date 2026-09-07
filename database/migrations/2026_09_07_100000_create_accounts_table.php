<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identities this device has signed in as. One row per account; exactly
     * one `is_current`. The Sanctum token lives in SecureStorage keyed by
     * username, not here.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('name');
            $table->string('accent', 9)->default('#0A7CFF');
            $table->unsignedBigInteger('server_id')->nullable();   // User server id, once registered
            $table->boolean('is_current')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('is_current');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
