<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('parent_customer_id')->nullable()->after('id')->constrained('customers')->nullOnDelete();
            $table->string('revived_from_type', 20)->nullable()->after('parent_customer_id');
            $table->unsignedBigInteger('revived_from_id')->nullable()->after('revived_from_type');
            $table->boolean('is_recycled_lead')->default(false)->after('revived_from_id');
            $table->timestamp('recycled_at')->nullable()->after('is_recycled_lead');
            $table->foreignId('recycled_by')->nullable()->after('recycled_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('customer_deals', function (Blueprint $table) {
            $table->foreignId('recreated_customer_id')->nullable()->after('status')->constrained('customers')->nullOnDelete();
            $table->timestamp('recreated_at')->nullable()->after('recreated_customer_id');
            $table->foreignId('recreated_by')->nullable()->after('recreated_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('customer_losses', function (Blueprint $table) {
            $table->foreignId('recreated_customer_id')->nullable()->after('note')->constrained('customers')->nullOnDelete();
            $table->timestamp('recreated_at')->nullable()->after('recreated_customer_id');
            $table->foreignId('recreated_by')->nullable()->after('recreated_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_losses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recreated_by');
            $table->dropColumn('recreated_at');
            $table->dropConstrainedForeignId('recreated_customer_id');
        });

        Schema::table('customer_deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recreated_by');
            $table->dropColumn('recreated_at');
            $table->dropConstrainedForeignId('recreated_customer_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recycled_by');
            $table->dropColumn([
                'recycled_at',
                'is_recycled_lead',
                'revived_from_id',
                'revived_from_type',
            ]);
            $table->dropConstrainedForeignId('parent_customer_id');
        });
    }
};