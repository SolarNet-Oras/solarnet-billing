<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_location_capture_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->index();
            $table->uuid('router_id')->nullable()->index();
            $table->uuid('dhcp_lease_id')->nullable()->index();
            $table->string('onu_reference')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('source_ip', 45);
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('requested_at');
            $table->timestamp('shown_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy_meters', 10, 2)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('router_id')->references('id')->on('routers')->nullOnDelete();
            $table->foreign('dhcp_lease_id')->references('id')->on('dhcp_leases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_location_capture_requests');
    }
};
