<?php

use App\Models\Household;
use App\Models\HouseholdRoom;
use App\Models\PointTransaction;
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
        Schema::create('household_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Household::class)->constrained('households')->cascadeOnDelete();
            $table->string('room', 32);
            $table->unsignedInteger('collected')->default(0);
            $table->timestamp('unlocked_at')->nullable();
            $table->timestamps();

            $table->unique(['household_id', 'room']);
        });

        Schema::create('room_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(HouseholdRoom::class)->constrained('household_rooms')->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained('users');
            $table->unsignedInteger('amount');
            $table->foreignIdFor(PointTransaction::class)->constrained('point_transactions');
            $table->timestamps();

            $table->index(['household_room_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_contributions');
        Schema::dropIfExists('household_rooms');
    }
};
