<?php

use App\Models\Category;
use App\Models\Household;
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
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->jsonb('name');
            $table->jsonb('description');
            $table->foreignIdFor(Category::class)->constrained('categories');
            $table->string('icon');
            $table->unsignedMediumInteger('default_duration_minutes');
            $table->unsignedMediumInteger('default_base_points');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_templates');
    }
};
