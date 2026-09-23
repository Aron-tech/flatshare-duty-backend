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
        Schema::create('household_users', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Household::class)->constrained('households');
            $table->foreignIdFor(User::class)->constrained('users');
            $table->string('role', 20);
            $table->integer('points_balance')->default(0);
            $table->timestamps();

            $table->unique(['household_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('household_users');
    }
};
