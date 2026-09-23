<?php

use App\Models\Category;
use App\Models\Household;
use App\Models\TaskTemplate;
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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(TaskTemplate::class)->nullable()->constrained('task_templates');
            $table->foreignIdFor(Household::class, 'household_id')->constrained('households');
            $table->foreignIdFor(User::class, 'created_by')->constrained('users');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->foreignIdFor(Category::class)->nullable()->constrained('categories');
            $table->string('icon')->nullable();
            $table->unsignedMediumInteger('duration_minutes');
            $table->unsignedMediumInteger('base_points');
            $table->boolean('is_recurring')->default(false);
            $table->unsignedMediumInteger('recurrence_interval')->nullable();
            $table->string('recurrence_unit', 10)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
