<?php

use App\Models\Household;
use App\Models\RewardRedemption;
use App\Models\TaskInstance;
use App\Models\TaskOffer;
use App\Models\User;
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
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Household::class)->constrained('households');
            $table->foreignIdFor(User::class)->constrained('users');
            $table->unsignedInteger('amount');
            $table->integer('balance_after');
            $table->string('type', 35);
            $table->foreignIdFor(TaskInstance::class)->nullable()->constrained('task_instances');
            $table->foreignIdFor(TaskOffer::class)->nullable()->constrained('task_offers');
            $table->foreignIdFor(RewardRedemption::class)->nullable()->constrained('reward_redemptions');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
    }
};
