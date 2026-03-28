<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('warning_level', 20)->nullable()->after('status');
            $table->boolean('warning_locked_by_admin')->default(false)->after('warning_level');
            $table->timestamp('warning_updated_at')->nullable()->after('warning_locked_by_admin');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'warning_level',
                'warning_locked_by_admin',
                'warning_updated_at',
            ]);
        });
    }
};