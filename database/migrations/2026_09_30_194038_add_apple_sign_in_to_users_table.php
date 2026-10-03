<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The users signing in with the native Sign in with Apple have no WorkOS user, they are identified by Apple's `sub`.
     * The Apple refresh token is kept (encrypted) only to revoke it when the account is deleted, as the App Store requires.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('workos_id')->nullable()->change();
            $table->string('apple_id')->nullable()->unique()->after('workos_id');
            $table->text('apple_refresh_token')->nullable()->after('apple_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['apple_id']);
            $table->dropColumn(['apple_id', 'apple_refresh_token']);
            $table->string('workos_id')->nullable(false)->change();
        });
    }
};
