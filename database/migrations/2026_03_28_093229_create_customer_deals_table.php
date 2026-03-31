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
            $table->foreignId('closer_user_id')->constrained('users')->cascadeOnDelete();

            $table->string('project_code')->nullable();
            $table->string('building_name');
            $table->string('address')->nullable();
            $table->string('floor')->nullable();

            $table->decimal('area', 12, 2)->nullable();
            $table->decimal('rental_price', 15, 2)->nullable();
            $table->integer('contract_term_months')->nullable();

            $table->date('first_payment_date')->nullable();
            $table->decimal('brokerage_fee', 15, 2)->nullable();

            $table->text('note')->nullable();
            $table->string('status', 30)->default('won');
            $table->timestamp('signed_at')->nullable();
            $table->date('deposit_date')->nullable();
            $table->boolean('has_vat')->default(false);
            $table->decimal('vat_revenue', 15, 2)->nullable();
            $table->decimal('back_fee', 15, 2)->nullable();

            $table->decimal('net_revenue', 15, 2)->nullable();   // sau VAT
            $table->decimal('final_revenue', 15, 2)->nullable(); // sau back

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_deals');
    }
};