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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('company_name')->nullable();
            $table->string('contact_name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources');
            $table->string('source_detail')->nullable();
            $table->string('campaign_name')->nullable();

            $table->enum('status', [
                'new','consulting','viewing','negotiating','deposit','contracted','lost'
            ])->default('new');

            $table->text('note')->nullable();

            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();

            $table->index('phone');
            $table->index('email');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
