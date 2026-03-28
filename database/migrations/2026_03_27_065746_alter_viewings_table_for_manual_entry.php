<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viewings', function (Blueprint $table) {
            if (Schema::hasColumn('viewings', 'property_id')) {
                try {
                    $table->dropForeign(['property_id']);
                } catch (\Throwable $e) {
                    // ignore if foreign key does not exist
                }

                $table->unsignedBigInteger('property_id')->nullable()->change();
            }

            if (!Schema::hasColumn('viewings', 'building_name')) {
                $table->string('building_name')->nullable()->after('property_id');
            }

            if (!Schema::hasColumn('viewings', 'address')) {
                $table->string('address')->nullable()->after('building_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('viewings', function (Blueprint $table) {
            if (Schema::hasColumn('viewings', 'building_name')) {
                $table->dropColumn('building_name');
            }

            if (Schema::hasColumn('viewings', 'address')) {
                $table->dropColumn('address');
            }

            if (Schema::hasColumn('viewings', 'property_id')) {
                $table->unsignedBigInteger('property_id')->nullable(false)->change();
                $table->foreign('property_id')->references('id')->on('properties')->nullOnDelete();
            }
        });
    }
};