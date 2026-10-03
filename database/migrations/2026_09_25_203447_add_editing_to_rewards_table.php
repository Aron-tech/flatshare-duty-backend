<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $table) {
            $table->boolean('is_editing')->default(false)->after('is_active');
            $table->timestamp('editing_started_at')->nullable()->after('is_editing');
        });
    }

    public function down(): void
    {
        Schema::table('rewards', function (Blueprint $table) {
            $table->dropColumn(['is_editing', 'editing_started_at']);
        });
    }
};
