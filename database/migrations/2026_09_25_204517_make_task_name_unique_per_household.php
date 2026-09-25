<?php

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
        $has_name_unique = Schema::hasIndex('tasks', ['name'], 'unique');
        $has_household_name_index = Schema::hasIndex('tasks', ['household_id', 'name']);

        Schema::table('tasks', function (Blueprint $table) use ($has_name_unique, $has_household_name_index) {
            if ($has_name_unique) {
                $table->dropUnique(['name']);
            }
            if ($has_household_name_index) {
                $table->dropIndex(['household_id', 'name']);
            }
            $table->unique(['household_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['household_id', 'name']);
            $table->index(['household_id', 'name']);
            $table->unique('name');
        });
    }
};
