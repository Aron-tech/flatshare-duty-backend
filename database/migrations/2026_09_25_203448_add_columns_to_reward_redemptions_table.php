<?php

use App\Models\Household;
use App\Models\Reward;
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
        Schema::table('reward_redemptions', function (Blueprint $table) {
            $table->foreignIdFor(Household::class)->after('id')->constrained('households');
            $table->foreignIdFor(Reward::class)->after('household_id')->nullable()->constrained('rewards')->nullOnDelete();
            $table->foreignIdFor(User::class)->after('reward_id')->constrained('users');
            $table->unsignedInteger('points_spent')->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reward_redemptions', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(Household::class);
            $table->dropConstrainedForeignIdFor(Reward::class);
            $table->dropConstrainedForeignIdFor(User::class);
            $table->dropColumn('points_spent');
        });
    }
};
