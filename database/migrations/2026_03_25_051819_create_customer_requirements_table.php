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
        Schema::create('customer_requirements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('preferred_location')->nullable();
            $table->integer('area_min')->nullable();
            $table->integer('area_max')->nullable();

            $table->decimal('budget_min',15,2)->nullable();
            $table->decimal('budget_max',15,2)->nullable();

            $table->date('move_in_date')->nullable();
            $table->integer('lease_term_months')->nullable();

            $table->text('special_requirements')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_requirements');
    }
};
