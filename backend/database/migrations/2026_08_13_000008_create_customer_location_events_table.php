<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_location_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->index();
            $table->uuid('location_capture_request_id')->nullable()->index();
            $table->string('onu_reference')->nullable();
            $table->string('source', 32);
            $table->string('action', 24);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy_meters', 10, 2)->nullable();
            $table->uuid('captured_by_user_id')->nullable();
            $table->timestamps();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('location_capture_request_id')->references('id')->on('customer_location_capture_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_location_events');
    }
};
