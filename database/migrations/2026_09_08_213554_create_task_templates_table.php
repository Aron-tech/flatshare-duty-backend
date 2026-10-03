<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->jsonb('name');
            $table->jsonb('description');
            $table->foreignIdFor(Category::class)->constrained('categories');
            $table->string('icon');
            $table->unsignedMediumInteger('duration_minutes');
            $table->string('difficulty', 10);
            $table->unsignedMediumInteger('base_points');
            $table->unsignedSmallInteger('max_user')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_templates');
    }
};
