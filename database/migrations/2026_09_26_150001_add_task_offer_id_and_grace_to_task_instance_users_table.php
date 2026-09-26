<?php

use App\Models\TaskOffer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * task_offer_id: the claim was taken over through this offer, see AcceptTaskOfferAction.
     * grace_granted_at: the member used a grace day on the claim, see RequestTaskInstanceGraceDayAction.
     */
    public function up(): void
    {
        Schema::table('task_instance_users', function (Blueprint $table) {
            $table->foreignIdFor(TaskOffer::class)->nullable()->after('penalty_points')->constrained('task_offers')->nullOnDelete();
            $table->timestamp('grace_granted_at')->nullable()->after('task_offer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_instance_users', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(TaskOffer::class);
            $table->dropColumn('grace_granted_at');
        });
    }
};
