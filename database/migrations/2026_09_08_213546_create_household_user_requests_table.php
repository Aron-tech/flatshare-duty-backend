<?php

use App\Models\Household;
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
        Schema::create('household_user_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Household::class)->constrained('households');
            $table->foreignIdFor(User::class)->constrained('users');
            $table->string('status', 20);
            $table->foreignIdFor(User::class, 'approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['household_id', 'user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('household_user_requests');
    }
};
