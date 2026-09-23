<?php

use App\Models\Household;
use App\Models\Task;
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
        Schema::create('task_user_weights', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Household::class)->constrained('households');
            $table->foreignIdFor(User::class)->constrained('users');
            $table->foreignIdFor(Task::class)->constrained('tasks');
            $table->unsignedInteger('weight_score');
            $table->timestamps();

            $table->unique(['user_id', 'task_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_user_weights');
    }
};
