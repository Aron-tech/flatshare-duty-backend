<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reward_redemptions', function (Blueprint $table) {
            $table->timestamp('fulfilled_at')->nullable()->after('points_spent');
            $table->timestamp('refunded_at')->nullable()->after('fulfilled_at');
        });

        // The redemptions made before the fulfillment was tracked count as fulfilled, so they are not refunded later.
        DB::table('reward_redemptions')->update(['fulfilled_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reward_redemptions', function (Blueprint $table) {
            $table->dropColumn(['fulfilled_at', 'refunded_at']);
        });
    }
};
