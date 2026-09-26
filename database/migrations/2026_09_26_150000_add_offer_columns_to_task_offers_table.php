<?php

use App\Models\Household;
use App\Models\TaskInstanceUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A member offers their claim to the others for points held in escrow, see StoreTaskOfferAction.
     * penalty_points is the weekly goal shortfall the offered claim covers, it is burned when the taker misses the task.
     */
    public function up(): void
    {
        Schema::table('task_offers', function (Blueprint $table) {
            $table->foreignIdFor(Household::class)->after('id')->constrained('households')->cascadeOnDelete();
            $table->foreignIdFor(TaskInstanceUser::class)->after('household_id')->constrained('task_instance_users');
            $table->foreignId('offered_by')->after('task_instance_user_id')->constrained('users');
            $table->foreignId('target_user_id')->nullable()->after('offered_by')->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by')->nullable()->after('target_user_id')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('points')->after('accepted_by');
            $table->unsignedInteger('penalty_points')->default(0)->after('points');
            $table->string('status', 20)->after('penalty_points');
            $table->timestamp('accepted_at')->nullable()->after('status');
            $table->timestamp('resolved_at')->nullable()->after('accepted_at');

            $table->index(['household_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_offers', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'status']);
            $table->dropConstrainedForeignId('household_id');
            $table->dropConstrainedForeignId('task_instance_user_id');
            $table->dropConstrainedForeignId('offered_by');
            $table->dropConstrainedForeignId('target_user_id');
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropColumn(['points', 'penalty_points', 'status', 'accepted_at', 'resolved_at']);
        });
    }
};
