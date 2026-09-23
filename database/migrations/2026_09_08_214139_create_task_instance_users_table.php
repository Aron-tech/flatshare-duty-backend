<?php

use App\Models\TaskInstance;
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
        Schema::create('task_instance_users', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(TaskInstance::class)->constrained('task_instances');
            $table->foreignIdFor(User::class)->constrained('users');
            $table->timestamps();

            $table->unique(['task_instance_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_instance_users');
    }
};
