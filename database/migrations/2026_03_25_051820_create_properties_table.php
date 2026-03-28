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
        Schema::create('properties', function (Blueprint $table) {
            $table->id();

            $table->string('building_name');
            $table->string('address')->nullable();
            $table->string('district')->nullable();
            $table->integer('area')->nullable();
            $table->decimal('price_per_m2', 15, 2)->nullable();
            $table->enum('status', ['available', 'reserved', 'rented'])->default('available');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
