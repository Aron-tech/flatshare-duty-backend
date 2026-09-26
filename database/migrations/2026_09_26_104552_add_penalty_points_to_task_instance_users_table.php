<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The part of the weekly goal's shortfall a penalty claim covers, only the points above it are paid.
     * Null for the penalty claims assigned before, which pay nothing.
     */
    public function up(): void
    {
        Schema::table('task_instance_users', function (Blueprint $table) {
            $table->unsignedInteger('penalty_points')->nullable()->after('weekly_point_goal_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_instance_users', function (Blueprint $table) {
            $table->dropColumn('penalty_points');
        });
    }
};
