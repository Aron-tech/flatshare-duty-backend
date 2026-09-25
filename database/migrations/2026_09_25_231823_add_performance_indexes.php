<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL does not index foreign keys on its own, these cover the hottest queries.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('point_transactions', function (Blueprint $table) {
            // Weekly points of the members (stats, weekly goals).
            $table->index(['household_id', 'type', 'created_at']);
            // Activity feed, paged by id.
            $table->index(['household_id', 'type', 'id']);
        });

        Schema::table('task_instances', function (Blueprint $table) {
            // The instances of a task (recurring generation, assignment, deletion).
            $table->index(['task_id', 'status']);
        });

        Schema::table('task_user_weights', function (Blueprint $table) {
            // The user's weight of a task (claiming, points).
            $table->index(['task_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('point_transactions', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'type', 'created_at']);
            $table->dropIndex(['household_id', 'type', 'id']);
        });

        Schema::table('task_instances', function (Blueprint $table) {
            $table->dropIndex(['task_id', 'status']);
        });

        Schema::table('task_user_weights', function (Blueprint $table) {
            $table->dropIndex(['task_id', 'user_id']);
        });
    }
};
