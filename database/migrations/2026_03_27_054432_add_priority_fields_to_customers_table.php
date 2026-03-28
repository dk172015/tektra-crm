<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_priority')->default(false)->after('warning_updated_at');
            $table->timestamp('priority_marked_at')->nullable()->after('is_priority');
            $table->foreignId('priority_marked_by')->nullable()->after('priority_marked_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('priority_marked_by');
            $table->dropColumn(['is_priority', 'priority_marked_at']);
        });
    }
};