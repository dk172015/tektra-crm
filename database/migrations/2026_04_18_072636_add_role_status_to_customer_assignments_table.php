<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_assignments', function (Blueprint $table) {
            $table->string('role', 20)->default('main')->after('user_id');
            $table->boolean('is_active')->default(true)->after('role');
            $table->timestamp('ended_at')->nullable()->after('is_active');

            $table->index(['customer_id', 'role', 'is_active'], 'idx_ca_customer_role_active');
            $table->index(['user_id', 'role', 'is_active'], 'idx_ca_user_role_active');
        });

        // backfill dữ liệu cũ: tất cả assignment hiện có coi là main + active
        DB::table('customer_assignments')
            ->update([
                'role' => 'main',
                'is_active' => 1,
                'ended_at' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('customer_assignments', function (Blueprint $table) {
            $table->dropIndex('idx_ca_customer_role_active');
            $table->dropIndex('idx_ca_user_role_active');

            $table->dropColumn([
                'role',
                'is_active',
                'ended_at',
            ]);
        });
    }
};