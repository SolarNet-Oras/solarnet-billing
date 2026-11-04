<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_location_deferrals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->index();
            $table->uuid('collector_id')->index();
            $table->uuid('collection_plan_stop_id')->nullable()->index();
            $table->string('context')->default('collection');
            $table->text('reason');
            $table->timestamp('deferred_at')->index();
            $table->timestamps();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('collector_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('collection_plan_stop_id')->references('id')->on('collection_plan_stops')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_location_deferrals');
    }
};
