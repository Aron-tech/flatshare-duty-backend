<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array TABLES = ['households', 'tasks', 'task_instances', 'task_instance_users', 'rewards'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table_name) {
            Schema::table($table_name, function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        // A deleted task must not block creating a new one with the same name.
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['household_id', 'name']);
        });
        DB::statement('create unique index tasks_household_id_name_unique on tasks (household_id, name) where deleted_at is null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop index tasks_household_id_name_unique');
        Schema::table('tasks', function (Blueprint $table) {
            $table->unique(['household_id', 'name']);
        });

        foreach (self::TABLES as $table_name) {
            Schema::table($table_name, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
