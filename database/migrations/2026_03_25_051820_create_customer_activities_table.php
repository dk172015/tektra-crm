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
        Schema::create('customer_activities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();

            $table->enum('type', [
                'call','message','meeting','site_visit','note','status_change','assignment_change'
            ]);

            $table->text('content');

            $table->timestamp('activity_time')->useCurrent();

            $table->timestamps();

            $table->index(['customer_id','activity_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_activities');
    }
};
