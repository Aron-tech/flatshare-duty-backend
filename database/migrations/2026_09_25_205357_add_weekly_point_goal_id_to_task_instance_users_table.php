<?php

use App\Models\WeeklyPointGoal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('task_instance_users', function (Blueprint $table) {
            $table->foreignIdFor(WeeklyPointGoal::class)->nullable()->after('user_id')->constrained('weekly_point_goals')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_instance_users', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(WeeklyPointGoal::class);
        });
    }
};
