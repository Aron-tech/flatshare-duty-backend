<?php

use App\Models\Household;
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
        Schema::create('weekly_point_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Household::class)->constrained('households')->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained('users')->cascadeOnDelete();
            $table->date('week_starts_at');
            $table->unsignedInteger('target_points');
            $table->unsignedInteger('earned_points')->nullable();
            $table->unsignedInteger('shortfall_points')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['household_id', 'user_id', 'week_starts_at']);
            $table->index(['week_starts_at', 'closed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weekly_point_goals');
    }
};
