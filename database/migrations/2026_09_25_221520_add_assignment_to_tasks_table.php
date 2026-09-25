<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('assignment_mode', 10)->default('none')->after('recurrence_unit');
            $table->foreignIdFor(User::class, 'fixed_user_id')->nullable()->after('assignment_mode')->constrained('users')->nullOnDelete();
            $table->foreignIdFor(User::class, 'last_assigned_user_id')->nullable()->after('fixed_user_id')->constrained('users')->nullOnDelete();
        });

        // SQLite rebuilds the table to add the foreign keys and drops the condition of the partial unique index.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('drop index tasks_household_id_name_unique');
            DB::statement('create unique index tasks_household_id_name_unique on tasks (household_id, name) where deleted_at is null');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fixed_user_id');
            $table->dropConstrainedForeignId('last_assigned_user_id');
            $table->dropColumn('assignment_mode');
        });
    }
};
