<?php

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
        Schema::create('task_user_rotations', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Task::class)->constrained('tasks');
            $table->foreignIdFor(User::class)->constrained('users');
            $table->unsignedInteger('rotation_order')->default(0);
            $table->timestamps();

            $table->unique(['task_id', 'rotation_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_user_rotations');
    }
};
