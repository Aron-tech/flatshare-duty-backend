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
        Schema::create('task_stickers', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Household::class)->constrained('households')->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained('users')->cascadeOnDelete();
            $table->foreignIdFor(Task::class)->constrained('tasks')->cascadeOnDelete();
            $table->unsignedSmallInteger('milestone');
            $table->timestamp('unlocked_at');
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'task_id', 'milestone']);
            $table->index(['household_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_stickers');
    }
};
