<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->string('contract_code')->nullable();
            $table->string('building_name');
            $table->string('address')->nullable();
            $table->decimal('area', 12, 2)->nullable();
            $table->decimal('monthly_revenue', 15, 2);
            $table->integer('lease_term_months')->nullable();
            $table->date('signed_date')->nullable();
            $table->date('start_date')->nullable();
            $table->string('status', 30)->default('won');
            $table->text('note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_deals');
    }
};