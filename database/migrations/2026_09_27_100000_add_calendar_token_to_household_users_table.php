<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * calendar_token: the secret of the member's iCalendar feed of the household, see ExportHouseholdCalendarFeedAction.
     * It is per membership, so leaving the household revokes the subscription.
     */
    public function up(): void
    {
        Schema::table('household_users', function (Blueprint $table) {
            $table->string('calendar_token', 64)->nullable()->unique()->after('points_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('household_users', function (Blueprint $table) {
            $table->dropUnique(['calendar_token']);
            $table->dropColumn('calendar_token');
        });
    }
};
